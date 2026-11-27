<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Attachment;
use App\Models\UploadSession;
use App\Models\User;
use App\Repositories\AttachmentRepository;
use App\Repositories\DraftRepository;
use App\Repositories\UploadSessionRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class AttachmentService
{
    public const CHUNK_SIZE = 5 * 1024 * 1024;

    /** 內容會被瀏覽器當成可執行頁面的 mime，一律拒絕 */
    private const DANGEROUS_MIME = ['text/html', 'image/svg+xml', 'application/xhtml+xml', 'text/javascript', 'application/javascript', 'text/x-php', 'application/x-httpd-php'];

    public function __construct(
        private AttachmentRepository $attachments,
        private UploadSessionRepository $sessions,
        private DraftRepository $drafts,
        private WorkflowService $wf,
    ) {}

    private function disk()
    {
        return Storage::disk('local');
    }

    public function allowedExtensions(): array
    {
        $raw = (string) $this->attachments->setting('attachment.allowed_ext');

        return array_values(array_filter(array_map(fn ($e) => strtolower(trim($e)), explode(',', $raw))));
    }

    public function maxBytes(): int
    {
        return (int) $this->attachments->setting('attachment.max_mb') * 1024 * 1024;
    }

    private function assertNameAndSize(string $name, int $size): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || ! in_array($ext, $this->allowedExtensions(), true)) {
            throw new BusinessException('不允許的檔案類型：'.($ext === '' ? '無副檔名' : $ext).'。可上傳：'.implode('、', $this->allowedExtensions()));
        }
        if ($size <= 0) {
            throw new BusinessException('檔案是空的');
        }
        if ($size > $this->maxBytes()) {
            throw new BusinessException('檔案超過大小上限 '.(int) $this->attachments->setting('attachment.max_mb').' MB');
        }

        return $ext;
    }

    /** 貼上／拖曳圖片 */
    public function storeImage(User $user, UploadedFile $file, ?string $context): Attachment
    {
        $name = $file->getClientOriginalName() ?: 'image.png';
        if (pathinfo($name, PATHINFO_EXTENSION) === '') {
            $name .= '.'.($file->guessExtension() ?: 'png');
        }
        $name = $this->cleanName($name);
        $this->assertNameAndSize($name, (int) $file->getSize());

        $tmp = $file->getRealPath();
        $mime = $this->realMime($tmp);
        if (! str_starts_with($mime, 'image/') || in_array($mime, self::DANGEROUS_MIME, true)) {
            throw new BusinessException('只接受圖片檔');
        }

        return $this->persist($user, $tmp, $name, $mime, $context);
    }

    /** @return array{id:string, chunk_size:int} */
    public function initUpload(User $user, string $name, int $size, ?string $context): array
    {
        $name = $this->cleanName($name);
        $this->assertNameAndSize($name, $size);
        $session = $this->sessions->create([
            'user_id' => $user->id,
            'original_name' => $name,
            'total_size' => $size,
            'total_chunks' => (int) ceil($size / self::CHUNK_SIZE),
            'received_chunks' => 0,
            'draft_context_key' => $context,
            'status' => 'open',
        ]);

        return ['id' => $session->id, 'chunk_size' => self::CHUNK_SIZE];
    }

    public function receiveChunk(User $user, string $sessionId, int $index, UploadedFile $chunk): int
    {
        $s = $this->ownedSession($user, $sessionId);
        if ($index < 0 || $index >= $s->total_chunks) {
            throw new BusinessException('分段編號錯誤');
        }
        if ($chunk->getSize() > self::CHUNK_SIZE) {
            throw new BusinessException('分段過大');
        }
        $this->disk()->putFileAs("chunks/{$s->id}", $chunk, sprintf('%06d.part', $index));
        $count = count($this->disk()->files("chunks/{$s->id}"));
        $this->sessions->setReceived($s, $count);

        return $count;
    }

    public function completeUpload(User $user, string $sessionId): Attachment
    {
        $s = $this->ownedSession($user, $sessionId);
        // two "complete" calls for one session must not both assemble and store the file
        if (! $this->sessions->claim($s, 'open', 'assembling')) {
            throw new BusinessException('上傳工作階段無效');
        }
        $dir = "chunks/{$s->id}";
        $this->disk()->makeDirectory($dir);
        $assembled = $this->disk()->path("$dir/assembled.tmp");

        try {
            $out = fopen($assembled, 'wb');
            for ($i = 0; $i < $s->total_chunks; $i++) {
                $part = sprintf('%s/%06d.part', $dir, $i);
                if (! $this->disk()->exists($part)) {
                    fclose($out);
                    throw new BusinessException('分段尚未上傳完整');
                }
                $in = fopen($this->disk()->path($part), 'rb');
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
            fclose($out);

            if (filesize($assembled) !== (int) $s->total_size) {
                throw new BusinessException('檔案大小不符，請重新上傳');
            }
            $this->assertNameAndSize($s->original_name, (int) $s->total_size);
            $mime = $this->realMime($assembled);
            if (in_array($mime, self::DANGEROUS_MIME, true)) {
                throw new BusinessException('不允許的檔案內容');
            }

            $att = $this->persist($user, $assembled, $s->original_name, $mime, $s->draft_context_key);
            $this->sessions->setStatus($s, 'done');

            return $att;
        } catch (\Throwable $e) {
            // release the claim so the client can send missing chunks and complete again
            $this->sessions->claim($s, 'assembling', 'open');

            throw $e;
        } finally {
            $this->disk()->deleteDirectory($dir);
        }
    }

    /**
     * File name as shown and offered for download: no path separators (Content-Disposition refuses / and \\)
     * and no control characters.
     */
    private function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', str_replace(['/', '\\'], '_', $name)));

        return $name === '' ? 'file' : $name;
    }

    private function ownedSession(User $user, string $id): UploadSession
    {
        $s = $this->sessions->find($id);
        if (! $s || $s->user_id !== $user->id || $s->status !== 'open') {
            throw new BusinessException('上傳工作階段無效');
        }

        return $s;
    }

    private function realMime(string $path): string
    {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($f, $path) ?: 'application/octet-stream';
        finfo_close($f);

        return $mime;
    }

    /** 搬到最終位置並建檔（同 sha256 同副檔名共用實體檔） */
    private function persist(User $user, string $srcPath, string $name, string $mime, ?string $context): Attachment
    {
        $sha = hash_file('sha256', $srcPath);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $rel = 'attachments/'.now()->format('Y/m')."/$sha.$ext";
        $size = filesize($srcPath);

        if (! $this->disk()->exists($rel)) {
            $dest = $this->disk()->path($rel);
            if (! is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0775, true);
            }
            copy($srcPath, $dest);
        }

        return $this->attachments->create([
            'draft_context_key' => $context,
            'disk_path' => $rel,
            'original_name' => mb_substr($name, 0, 255),
            'mime' => $mime,
            'size' => $size,
            'sha256' => $sha,
            'state' => 'temp',
            'uploaded_by' => $user->id,
        ]);
    }

    public function storeMeetingMarkdown(User $user, UploadedFile $file, string $context): Attachment
    {
        $path = $file->getRealPath();
        $name = $file->getClientOriginalName();
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'md' || mb_strlen($name) > 255) {
            throw new BusinessException('請選擇檔名不超過 255 字元的 .md 文件。');
        }
        $this->readMarkdown($path);

        return $this->persist($user, $path, $name, 'text/markdown', $context);
    }

    public function readMarkdown(string $path): string
    {
        $limit = min($this->maxBytes(), (int) config('meeting.markdown_max_bytes'));
        if ($limit <= 0 || filesize($path) > $limit) {
            throw new BusinessException('Markdown 文件超過大小上限 '.number_format($limit).' bytes。');
        }
        $text = file_get_contents($path);
        if ($text !== false && str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        if ($text === false || trim($text) === '' || str_contains($text, "\0") || ! mb_check_encoding($text, 'UTF-8')) {
            throw new BusinessException('請上傳有內容的 UTF-8 Markdown 文字檔。');
        }

        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    public function find(int $id): ?Attachment
    {
        return $this->attachments->find($id);
    }

    public function absolutePath(Attachment $a): ?string
    {
        return $this->disk()->exists($a->disk_path) ? $this->disk()->path($a->disk_path) : null;
    }

    public function canView(User $user, Attachment $a): bool
    {
        $meetingFile = $a->state === 'bound' && in_array($a->owner_type, ['meeting', 'meeting_internal', 'meeting_item'], true);
        if ($user->is_admin && ! $meetingFile) {
            return true;
        }
        // the uploader keeps access only while the file is unsaved; once bound it follows the container's rules
        if ($a->state === 'temp' && $a->uploaded_by === $user->id) {
            return true;
        }
        if ($a->state !== 'bound' || ! $a->owner_type || ! $a->owner_id) {
            return false;
        }
        if (! $user->seesInternal() && in_array($a->owner_type, ['issue', 'issue_comment', 'meeting_internal'], true)) {
            return false;
        }
        if (! $user->seesInternal() && $a->owner_type === 'meeting' && ! $this->attachments->meetingOpenToClients((int) $a->owner_id)) {
            return false;
        }
        // item pictures follow the item details: clients only once the meeting's record is open to them
        if (! $user->seesInternal() && $a->owner_type === 'meeting_item'
            && ! $this->attachments->meetingOpenToClients((int) $this->attachments->meetingIdForItem((int) $a->owner_id))) {
            return false;
        }
        $projectId = $this->attachments->projectIdForOwner($a->owner_type, (int) $a->owner_id);
        if ($projectId === null) {
            return false;
        }
        if ($user->is_admin) {
            return true;
        }
        $perm = config('attachments.view_permissions.'.$a->owner_type);

        return $perm !== null
            && $this->attachments->isProjectMember($user->id, $projectId)
            && $this->attachments->projectIsActive($projectId)
            && $this->wf->can($user, $perm, $projectId);
    }

    /** 儲存成功後呼叫：綁定 html 內引用的暫存附件，刪除同 context 未引用者 */
    public function bindFromHtml(string $html, string $ownerType, int $ownerId, string $context, ?int $userId = null): void
    {
        $userId ??= Auth::id();
        if (! $userId) {
            return;
        }

        preg_match_all('/data-attachment-id="(\d+)"|\/attachments\/(\d+)/', $html, $m);
        $ids = array_values(array_unique(array_map('intval', array_filter(array_merge($m[1], $m[2])))));

        $this->attachments->bindTemp($ids, $userId, $ownerType, $ownerId, $context);
        $this->deleteTemp($this->attachments->tempByContext($userId, $context)->filter(fn ($a) => ! in_array($a->id, $ids, true)));
    }

    /** The user's own unsaved upload in an editor context (Excel import reads the uploaded workbook through this). */
    public function importFile(User $user, int $id, string $context): ?Attachment
    {
        return $this->attachments->tempByContext($user->id, $context)->firstWhere('id', $id);
    }

    /** @return int[] ids of the user's unsaved pictures in a context */
    public function tempImageIds(User $user, string $context): array
    {
        return $this->attachments->tempByContext($user->id, $context)
            ->filter(fn (Attachment $a) => str_starts_with((string) $a->mime, 'image/'))->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** 刪除某使用者某 context 所有仍為暫存的附件 */
    public function discardTemp(int $userId, string $context): void
    {
        $this->deleteTemp($this->attachments->tempByContext($userId, $context));
    }

    // ---- pictures owned directly by a record (meeting items) ----

    /**
     * @param  int[]  $ownerIds
     * @return Collection<int, Collection<int, Attachment>> owner id => pictures, oldest first
     */
    public function ownedBy(string $ownerType, array $ownerIds): Collection
    {
        return $ownerIds ? $this->attachments->owned($ownerType, $ownerIds)->toBase()->groupBy('owner_id') : collect();
    }

    public function countOwned(string $ownerType, int $ownerId): int
    {
        return $this->attachments->countOwned($ownerType, $ownerId);
    }

    /** Bind the user's freshly uploaded picture (POST /uploads/image) to an owner. */
    public function bindImage(User $user, int $attachmentId, string $ownerType, int $ownerId): Attachment
    {
        $a = $this->attachments->tempImage($attachmentId, $user->id) ?? throw new BusinessException('找不到剛上傳的圖片，請重新上傳。');
        $this->attachments->bind($a, $ownerType, $ownerId);

        return $a;
    }

    /** Swap a bound picture's file for the user's new upload (annotated version); the old file goes if unused. */
    public function replaceImage(User $user, Attachment $old, int $newId): Attachment
    {
        $new = $this->attachments->tempImage($newId, $user->id) ?? throw new BusinessException('找不到剛上傳的圖片，請重新上傳。');
        $oldPath = $old->disk_path;
        $this->attachments->takeFile($old, $new);
        if ($this->attachments->countByPath($oldPath) === 0) {
            $this->disk()->delete($oldPath);
        }

        return $old;
    }

    /** Remove pictures: rows, and files no other row shares. @param iterable<Attachment> $list */
    public function deleteFiles(iterable $list): void
    {
        $this->deleteTemp($list);
    }

    /** Copy as bound to another owner (排下次會議 carries an item's pictures). */
    public function copyBound(Attachment $a, string $ownerType, int $ownerId): Attachment
    {
        return $this->attachments->duplicate($a, ['state' => 'bound', 'owner_type' => $ownerType, 'owner_id' => $ownerId, 'draft_context_key' => null]);
    }

    /**
     * Copy as the user's unsaved upload of an editor context (reused when that context already holds the same file):
     * the editor binds it on save like a pasted picture, under the new container's view permission.
     */
    public function copyAsTemp(User $user, Attachment $a, string $context): Attachment
    {
        $have = $this->attachments->tempByContext($user->id, $context)->firstWhere('disk_path', $a->disk_path);

        return $have ?? $this->attachments->duplicate($a, ['state' => 'temp', 'owner_type' => null, 'owner_id' => null,
            'draft_context_key' => $context, 'uploaded_by' => $user->id]);
    }

    /** @param iterable<Attachment> $list */
    private function deleteTemp(iterable $list): void
    {
        $ids = [];
        foreach ($list as $a) {
            $ids[] = $a->id;
        }
        $this->attachments->deleteByIds($ids);
        foreach ($list as $a) {
            if ($this->attachments->countByPath($a->disk_path) === 0) {
                $this->disk()->delete($a->disk_path);
            }
        }
    }

    /** 排程清理：暫存附件 / 分段 / upload_sessions（7 天）、草稿（30 天） */
    public function cleanupTemp(): array
    {
        $cut = now()->subDays(7);
        $temps = $this->attachments->tempOlderThan($cut);
        $n = $temps->count();
        $this->deleteTemp($temps);

        $sessions = $this->sessions->olderThan($cut);
        foreach ($sessions as $s) {
            $this->disk()->deleteDirectory("chunks/{$s->id}");
            $this->sessions->delete($s);
        }

        $drafts = $this->drafts->deleteOlderThan(now()->subDays(30));

        return ['attachments' => $n, 'sessions' => $sessions->count(), 'drafts' => $drafts];
    }
}

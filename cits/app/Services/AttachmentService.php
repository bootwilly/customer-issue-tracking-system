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
            throw new BusinessException('不允許的檔案類型：'.($ext === '' ? '無副檔名' : $ext));
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
        } finally {
            $this->disk()->deleteDirectory($dir);
        }
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
        if ($user->is_admin) {
            return true;
        }
        if ($a->uploaded_by === $user->id) {
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
        $projectId = $this->attachments->projectIdForOwner($a->owner_type, (int) $a->owner_id);

        return $projectId !== null && $this->attachments->isProjectMember($user->id, $projectId);
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

        $this->attachments->bindTemp($ids, $userId, $ownerType, $ownerId);
        $this->deleteTemp($this->attachments->tempByContext($userId, $context)->filter(fn ($a) => ! in_array($a->id, $ids, true)));
    }

    /** 刪除某使用者某 context 所有仍為暫存的附件 */
    public function discardTemp(int $userId, string $context): void
    {
        $this->deleteTemp($this->attachments->tempByContext($userId, $context));
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

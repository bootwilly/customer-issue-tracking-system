<?php

namespace App\Services;

use App\Repositories\DraftRepository;

class DraftService
{
    public function __construct(
        private DraftRepository $drafts,
        private AttachmentService $attachments,
        private SanitizerService $sanitizer,
    ) {}

    /** Same ceiling as the sanitizer input: a draft longer than that could never be saved anyway. */
    public const MAX_HTML = 2_000_000;

    /** Open drafts per user (one per editor context); the daily cleanup also drops drafts older than 30 days. */
    public const MAX_PER_USER = 200;

    public function save(int $userId, string $context, string $html): void
    {
        // a new context beyond the cap pushes out the user's least recently saved draft (drafts are a convenience)
        if (! $this->drafts->find($userId, $context) && $this->drafts->countFor($userId) >= self::MAX_PER_USER) {
            $this->drafts->deleteOldestFor($userId, $this->drafts->countFor($userId) - self::MAX_PER_USER + 1);
        }
        $this->drafts->save($userId, $context, $this->sanitizer->clean($html));
    }

    /** @return array{html:string, updated_at:string}|null */
    public function get(int $userId, string $context): ?array
    {
        $d = $this->drafts->find($userId, $context);

        return $d ? ['html' => $d->content_html, 'updated_at' => $d->updated_at->toIso8601String()] : null;
    }

    /** 儲存成功或取消後呼叫：刪草稿與該 context 未引用的暫存附件 */
    public function clear(int $userId, string $context): void
    {
        $this->drafts->delete($userId, $context);
        $this->attachments->discardTemp($userId, $context);
    }
}

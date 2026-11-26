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

    public function save(int $userId, string $context, string $html): void
    {
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

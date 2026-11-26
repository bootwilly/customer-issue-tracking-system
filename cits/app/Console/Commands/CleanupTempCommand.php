<?php

namespace App\Console\Commands;

use App\Services\AttachmentService;
use Illuminate\Console\Command;

class CleanupTempCommand extends Command
{
    protected $signature = 'cits:cleanup-temp';

    protected $description = '清理 7 天前的暫存附件/分段/上傳工作階段與 30 天前的草稿';

    public function handle(AttachmentService $service): int
    {
        $r = $service->cleanupTemp();
        $this->info("temp attachments: {$r['attachments']}, sessions: {$r['sessions']}, drafts: {$r['drafts']}");

        return self::SUCCESS;
    }
}

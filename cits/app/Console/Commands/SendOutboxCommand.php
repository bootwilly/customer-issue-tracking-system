<?php

namespace App\Console\Commands;

use App\Services\OutboxService;
use Illuminate\Console\Command;

class SendOutboxCommand extends Command
{
    protected $signature = 'cits:send-outbox {--limit=20}';

    protected $description = 'Send pending notification mails from notification_outbox';

    public function handle(OutboxService $svc): int
    {
        $r = $svc->sendBatch((int) $this->option('limit'));
        $this->line(json_encode($r, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}

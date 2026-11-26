<?php

namespace App\Console\Commands;

use App\Services\AuthLogService;
use Illuminate\Console\Command;

class PurgeAuthLogsCommand extends Command
{
    protected $signature = 'cits:purge-auth-logs';

    protected $description = '刪除超過保留天數（system_settings auth_log.keep_days，0 = 永久保留）的登入紀錄';

    public function handle(AuthLogService $service): int
    {
        $n = $service->purge();
        $this->info("auth logs purged: {$n} (keep {$service->keepDays()} days)");

        return self::SUCCESS;
    }
}

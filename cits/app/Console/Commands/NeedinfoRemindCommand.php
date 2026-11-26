<?php

namespace App\Console\Commands;

use App\Services\Collab\NeedinfoService;
use Illuminate\Console\Command;

class NeedinfoRemindCommand extends Command
{
    protected $signature = 'cits:needinfo-remind';

    protected $description = '提醒超過 needinfo.remind_days 天仍未補充資料的客戶單（客戶與提出要求的 PM，各提醒一次）';

    public function handle(NeedinfoService $service): int
    {
        $this->info('reminded: '.$service->remind());

        return self::SUCCESS;
    }
}

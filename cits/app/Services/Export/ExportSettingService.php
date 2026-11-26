<?php

namespace App\Services\Export;

use App\Repositories\SettingRepository;

/** Admin-editable export settings (system_settings export.*). */
class ExportSettingService
{
    public const MAX_ROWS_KEY = 'export.max_rows';

    public function __construct(private SettingRepository $settings, private XlsxExportService $xlsx) {}

    public function maxRows(): int
    {
        return $this->xlsx->maxRows();
    }

    public function setMaxRows(int $rows): void
    {
        $this->settings->set(self::MAX_ROWS_KEY, (string) $rows);
    }
}

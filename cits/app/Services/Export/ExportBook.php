<?php

namespace App\Services\Export;

/**
 * Whole workbook. $info: label => value shown on the "匯出資訊" sheet (filters in human words, etc.).
 * $listKey: short code used for audit (e.g. client_tickets).
 */
final class ExportBook
{
    /**
     * @param  list<ExportSheet>  $sheets
     * @param  array<string,string>  $info
     */
    public function __construct(
        public string $listKey,
        public string $filename,
        public array $sheets,
        public array $info = [],
    ) {}
}

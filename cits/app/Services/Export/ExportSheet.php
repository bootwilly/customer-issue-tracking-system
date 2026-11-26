<?php

namespace App\Services\Export;

/**
 * One list worksheet.
 * $columns: key => header label (already filtered/ordered by ExportColumnService).
 * $rows: each row is key => value (scalar | DateTimeInterface | null | ExportLink).
 * $details: optional, same order/count as $rows; when given, the $linkColumn cell links to the record's block
 * on a "<name>詳細" sheet, and each block links back.
 */
final class ExportSheet
{
    /**
     * @param  array<string,string>  $columns
     * @param  list<array<string,mixed>>  $rows
     * @param  list<DetailBlock>|null  $details
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $rows,
        public ?array $details = null,
        public ?string $linkColumn = null,
    ) {}
}

<?php

namespace App\Services\Export;

/**
 * Detail block of one record on the detail sheet.
 * $fields: label => value (scalar|DateTimeInterface|null|ExportLink).
 * $sections: heading => list of lines (string|ExportLink). Empty sections are skipped.
 */
final class DetailBlock
{
    /**
     * @param  array<string,mixed>  $fields
     * @param  array<string,list<string|ExportLink>>  $sections
     */
    public function __construct(
        public string $title,
        public array $fields = [],
        public array $sections = [],
        public ?string $url = null,
    ) {}
}

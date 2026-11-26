<?php

namespace App\Services\Export;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Repositories\EventRepository;
use App\Services\WorkflowService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Hyperlink;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Renders an ExportBook to .xlsx (streamed to a temp file, then downloaded and deleted).
 * Layout per list sheet: header row (frozen + auto filter); optional "<name>詳細" sheet with one block per record,
 * the list's link column jumps to the block and the block title jumps back. Last sheet: 匯出資訊.
 */
class XlsxExportService
{
    private const TZ = 'Asia/Taipei';

    private const MAX_CELL = 32000; // Excel hard limit is 32767 characters per cell

    private Style $head;

    private Style $bold;

    private Style $link;

    private Style $wrap;

    private Style $dateTime;

    private Style $date;

    public function __construct(private WorkflowService $wf, private EventRepository $events)
    {
        $this->head = (new Style)->withFontBold(true)->withBackgroundColor('E0E7FF');
        $this->bold = (new Style)->withFontBold(true)->withBackgroundColor('F1F5F9');
        $this->link = (new Style)->withFontColor('4F46E5')->withFontUnderline(true);
        $this->wrap = (new Style)->withShouldWrapText(true);
        $this->dateTime = (new Style)->withFormat('yyyy-mm-dd hh:mm');
        $this->date = (new Style)->withFormat('yyyy-mm-dd');
    }

    /** Admin-adjustable limit (system_settings export.max_rows). */
    public function maxRows(): int
    {
        return max(1, (int) $this->wf->setting('export.max_rows', '5000'));
    }

    /** Callers fetch maxRows()+1 rows and pass the count here; over the limit is an error, never a silent cut. */
    public function assertWithinLimit(int $count): void
    {
        $max = $this->maxRows();
        if ($count > $max) {
            throw new BusinessException("符合條件的資料超過匯出上限 {$max} 筆，請縮小篩選範圍後再匯出。");
        }
    }

    public function download(ExportBook $book, User $user, int $projectId, string $projectName): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'cits_xlsx_');
        $this->write($book, $path, $user, $projectName);

        $this->events->log($projectId, 'export', $projectId, 'export', null, null, $user->id, [
            'list' => $book->listKey,
            'rows' => array_sum(array_map(fn (ExportSheet $s) => count($s->rows), $book->sheets)),
            'filters' => $book->info,
        ]);

        return response()->download($path, $book->filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** Public for tests: writes the workbook to $path. */
    public function write(ExportBook $book, string $path, User $user, string $projectName): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $used = [];
        $first = true;
        foreach ($book->sheets as $sheet) {
            $listName = $this->sheetName($sheet->name, $used);
            $detailName = $sheet->details !== null ? $this->sheetName(mb_substr($sheet->name, 0, 28).'詳細', $used) : null;
            $blockRows = $detailName !== null ? $this->blockStartRows($sheet->details) : [];

            $ws = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $first = false;
            $ws->setName($listName);
            $ws->setSheetView((new SheetView)->withFreezeRow(2));
            $keys = array_keys($sheet->columns);
            foreach ($keys as $i => $k) {
                $ws->setColumnWidth($k === $sheet->linkColumn || $k === 'title' ? 40 : 16, $i + 1);
            }
            $writer->addRow(new Row(array_map(fn ($h) => new StringCell($h, $this->head), array_values($sheet->columns))));
            foreach ($sheet->rows as $r => $row) {
                $cells = [];
                foreach ($keys as $k) {
                    $v = $row[$k] ?? null;
                    if ($detailName !== null && $k === $sheet->linkColumn && isset($blockRows[$r])) {
                        $cells[] = new StringCell($this->text($this->plain($v)), $this->link, hyperlinkUrl: Hyperlink::toCell('A'.$blockRows[$r], $detailName));
                    } else {
                        $cells[] = $this->cell($v);
                    }
                }
                $writer->addRow(new Row($cells));
            }
            if ($keys !== []) {
                $ws->setAutoFilter(new AutoFilter(0, 1, count($keys) - 1, max(1, count($sheet->rows) + 1)));
            }

            if ($detailName !== null) {
                $linkIdx = array_search($sheet->linkColumn, $keys, true);
                $backCol = $this->colLetter($linkIdx === false ? 0 : $linkIdx);
                $dw = $writer->addNewSheetAndMakeItCurrent();
                $dw->setName($detailName);
                $dw->setColumnWidth(16, 1);
                $dw->setColumnWidth(100, 2);
                foreach ($sheet->details as $r => $block) {
                    foreach ($this->blockRows($block, Hyperlink::toCell($backCol.($r + 2), $listName)) as $row) {
                        $writer->addRow($row);
                    }
                }
            }
        }

        $info = $writer->addNewSheetAndMakeItCurrent();
        $info->setName($this->sheetName('匯出資訊', $used));
        $info->setColumnWidth(18, 1);
        $info->setColumnWidth(80, 2);
        $pairs = ['匯出時間' => CarbonImmutable::now(self::TZ)->format('Y-m-d H:i'), '匯出者' => $user->name.'（'.$user->account.'）', '專案' => $projectName] + $book->info;
        foreach ($book->sheets as $sheet) {
            $pairs['筆數：'.$sheet->name] = (string) count($sheet->rows);
        }
        foreach ($pairs as $label => $value) {
            $writer->addRow(new Row([new StringCell((string) $label, $this->bold), new StringCell($this->text((string) $value), $this->wrap)]));
        }
        $writer->close();
    }

    /** @param list<DetailBlock> $details @return array<int,int> record index => 1-based start row on the detail sheet */
    private function blockStartRows(array $details): array
    {
        $out = [];
        $row = 1;
        foreach ($details as $i => $b) {
            $out[$i] = $row;
            $row += $this->blockHeight($b);
        }

        return $out;
    }

    private function blockHeight(DetailBlock $b): int
    {
        $h = 1 + count($b->fields) + 1; // title + fields + trailing blank
        foreach ($b->sections as $lines) {
            if ($lines !== []) {
                $h += 1 + count($lines);
            }
        }

        return $h;
    }

    /** @return list<Row> exactly blockHeight() rows */
    private function blockRows(DetailBlock $b, Hyperlink $back): array
    {
        $rows = [];
        $title = new StringCell($this->text($b->title), $this->bold);
        $rows[] = new Row([$title, new StringCell('← 回清單', $this->link, hyperlinkUrl: $back)]);
        foreach ($b->fields as $label => $value) {
            $rows[] = new Row([new StringCell((string) $label, $this->bold), $this->cell($value)]);
        }
        foreach ($b->sections as $heading => $lines) {
            if ($lines === []) {
                continue;
            }
            $rows[] = new Row([new StringCell((string) $heading, $this->head), new EmptyCell(null, $this->head)]);
            foreach ($lines as $line) {
                $rows[] = new Row([new EmptyCell(null), $this->cell($line, $this->wrap)]);
            }
        }
        $rows[] = new Row([new EmptyCell(null)]);

        return $rows;
    }

    private function cell(mixed $v, ?Style $style = null): Cell
    {
        if ($v instanceof ExportLink) {
            return new StringCell($this->text($v->text), $this->link, hyperlinkUrl: Hyperlink::toUrl($v->url));
        }
        if ($v instanceof DateTimeInterface) {
            $c = CarbonImmutable::instance($v);
            // openspout serialises the wall-clock fields: date-only values (00:00) keep their date, timestamps shown in Taipei time
            if ($c->format('H:i:s') === '00:00:00') {
                return new DateTimeCell($c, $this->date);
            }

            return new DateTimeCell($c->setTimezone(self::TZ), $this->dateTime);
        }
        if (is_bool($v)) {
            return new StringCell($v ? '是' : '否', $style);
        }
        if (is_int($v) || is_float($v)) {
            return new NumericCell($v, $style);
        }
        if ($v === null || $v === '') {
            return new EmptyCell(null, $style);
        }

        return new StringCell($this->text((string) $v), $style);
    }

    private function plain(mixed $v): string
    {
        return $v instanceof ExportLink ? $v->text : (string) ($v ?? '');
    }

    /** Formula-injection guard + Excel cell length limit. */
    private function text(string $s): string
    {
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $s = "'".$s;
        }

        return mb_strlen($s) > self::MAX_CELL ? mb_substr($s, 0, self::MAX_CELL).'…（內容過長已截斷）' : $s;
    }

    /** Excel sheet name: max 31 chars, no []:*?/\ , unique (case-insensitive). */
    private function sheetName(string $name, array &$used): string
    {
        $base = mb_substr(trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], '_', $name)) ?: 'Sheet', 0, 31);
        $n = $base;
        for ($i = 2; in_array(mb_strtolower($n), $used, true); $i++) {
            $n = mb_substr($base, 0, 31 - strlen((string) $i) - 1).'_'.$i;
        }
        $used[] = mb_strtolower($n);

        return $n;
    }

    private function colLetter(int $index): string
    {
        $s = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26).$s;
        }

        return $s;
    }

    /** Download filename: {project code}_{list label}_{yyyyMMdd-HHmm}.xlsx */
    public function filename(string $projectCode, string $label): string
    {
        $safe = fn (string $s) => preg_replace('~[\\\\/:*?"<>|\s]+~u', '_', $s);

        return $safe($projectCode).'_'.$safe($label).'_'.CarbonImmutable::now(self::TZ)->format('Ymd-Hi').'.xlsx';
    }
}

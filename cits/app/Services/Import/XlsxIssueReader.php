<?php

namespace App\Services\Import;

use App\Exceptions\BusinessException;
use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * Reads an .xlsx package directly (ZipArchive + DOM): cell values, internal hyperlinks, and every drawing object with
 * its absolute position in EMU (a:xfrm, group transforms applied), so pictures and the shapes drawn over them can be
 * composed into one image in the browser. File parsing only: no database access.
 */
class XlsxIssueReader
{
    public const EMU_PER_PX = 9525;

    private const EMU_PER_PT = 12700;

    private const NS = [
        'm' => 'http://schemas.openxmlformats.org/spreadsheetml/2006/main',
        'r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'pr' => 'http://schemas.openxmlformats.org/package/2006/relationships',
        'xdr' => 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing',
        'a' => 'http://schemas.openxmlformats.org/drawingml/2006/main',
        'mc' => 'http://schemas.openxmlformats.org/markup-compatibility/2006',
    ];

    private const MAX_ENTRIES = 5000;

    private const MAX_XML_BYTES = 50 * 1024 * 1024;

    private const MAX_MEDIA_BYTES = 30 * 1024 * 1024;

    /** Preset geometries the browser composer draws; any other shape is drawn as its outline box and reported. */
    public const GEOMS = ['rect', 'roundRect', 'ellipse', 'diamond', 'triangle', 'line', 'straightConnector1',
        'bentConnector2', 'bentConnector3', 'bentConnector4', 'bentConnector5', 'curvedConnector2', 'curvedConnector3',
        'curvedConnector4', 'curvedConnector5', 'flowChartProcess', 'flowChartPredefinedProcess', 'flowChartAlternateProcess',
        'flowChartDecision', 'flowChartTerminator', 'flowChartConnector', 'rightArrow', 'leftArrow', 'upArrow', 'downArrow'];

    public const IMAGE_MIME = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'bmp' => 'image/bmp', 'webp' => 'image/webp'];

    private const PRESET_COLORS = ['black' => '000000', 'white' => 'FFFFFF', 'red' => 'FF0000', 'green' => '008000',
        'blue' => '0000FF', 'yellow' => 'FFFF00', 'orange' => 'FFA500', 'gray' => '808080', 'purple' => '800080'];

    /** Built-in number formats that are dates (incl. the East Asian ones 27-36, 50-58). */
    private const DATE_FORMAT_IDS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58];

    private ZipArchive $zip;

    /** @var string[] */
    private array $strings = [];

    /** @var array<string, string> sheet name => part path */
    private array $sheets = [];

    /** @var array<int, true> cellXfs index => date format */
    private array $dateStyles = [];

    /** @var array<string, string> theme color name => RRGGBB */
    private array $theme = [];

    private bool $date1904 = false;

    public function __construct(string $path, int $maxUnzipBytes)
    {
        $this->zip = new ZipArchive;
        if ($this->zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new BusinessException('無法開啟檔案，請上傳 .xlsx 格式的 Excel 檔。');
        }
        if ($this->zip->numFiles > self::MAX_ENTRIES) {
            throw new BusinessException('檔案內容過多，無法匯入。');
        }
        $total = 0;
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $total += (int) ($this->zip->statIndex($i)['size'] ?? 0);
        }
        if ($total > $maxUnzipBytes) {
            throw new BusinessException('檔案解壓後超過 '.intdiv($maxUnzipBytes, 1048576).' MB，請拆成多個檔案匯入。');
        }
        $this->loadWorkbook();
    }

    public function close(): void
    {
        $this->zip->close();
    }

    /** @return string[] sheet names in workbook order */
    public function sheetNames(): array
    {
        return array_keys($this->sheets);
    }

    public function hasSheet(string $name): bool
    {
        return isset($this->sheets[$name]);
    }

    /**
     * @return array{rows: array<int, array<string, string>>, links: array<string, string>}
     *                                                                                      rows: row number => column letters => value (dates as Y-m-d); links: cell => internal location
     */
    public function sheetCells(string $name): array
    {
        $x = $this->xpath($this->sheets[$name]);
        $rows = [];
        foreach ($x->query('//m:sheetData/m:row/m:c') as $c) {
            /** @var DOMElement $c */
            $ref = $c->getAttribute('r');
            if (! preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                continue;
            }
            $v = $this->cellValue($x, $c);
            if ($v !== '') {
                $rows[(int) $m[2]][$m[1]] = $v;
            }
        }
        ksort($rows);
        $links = [];
        foreach ($x->query('//m:hyperlinks/m:hyperlink') as $h) {
            /** @var DOMElement $h */
            $loc = $h->getAttribute('location');
            $cell = explode(':', $h->getAttribute('ref'))[0];
            if ($loc !== '' && $cell !== '') {
                $links[$cell] = $loc;
            } elseif ($cell !== '' && $h->hasAttributeNS(self::NS['r'], 'id')) {
                $links[$cell] = '';   // external link: reported, never followed
            }
        }

        return ['rows' => $rows, 'links' => $links];
    }

    /**
     * Text cells with their position, and every drawing object of a sheet.
     *
     * @return array{texts: list<array{row:int, col:int, text:string, x:int, y:int}>, objects: list<array<string, mixed>>}
     */
    public function sheetContent(string $name): array
    {
        $path = $this->sheets[$name];
        $x = $this->xpath($path);
        $grid = $this->grid($x);
        $texts = [];
        foreach ($x->query('//m:sheetData/m:row/m:c') as $c) {
            /** @var DOMElement $c */
            if (! preg_match('/^([A-Z]+)(\d+)$/', $c->getAttribute('r'), $m)) {
                continue;
            }
            $v = trim($this->cellValue($x, $c));
            if ($v === '') {
                continue;
            }
            $col = $this->colIndex($m[1]);
            $row = (int) $m[2];
            $texts[] = ['row' => $row, 'col' => $col, 'text' => $v, 'x' => $grid['colX']($col), 'y' => $grid['rowY']($row - 1)];
        }
        $objects = [];
        foreach ($this->rels($path) as $rel) {
            if ($rel['type'] === 'drawing' && $this->exists($rel['target'])) {
                $objects = array_merge($objects, $this->drawing($rel['target'], $grid));
            }
        }

        return ['texts' => $texts, 'objects' => $objects];
    }

    /** @return array{0: string, 1: string}|null bytes and mime of a picture inside xl/ */
    public function media(string $path): ?array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! str_starts_with($path, 'xl/') || str_contains($path, '..') || ! isset(self::IMAGE_MIME[$ext]) || ! $this->exists($path)) {
            return null;
        }
        $bytes = $this->read($path, self::MAX_MEDIA_BYTES);

        return [$bytes, self::IMAGE_MIME[$ext]];
    }

    /** Cheap fingerprint of a package part (CRC32 from the zip directory) for change detection. */
    public function fingerprint(string $path): string
    {
        $s = $this->zip->statName($path);

        return $s ? sprintf('%08x-%d', $s['crc'], $s['size']) : '';
    }

    /** Value of the cell right below the one holding $label on a sheet (template version marker). */
    public function valueBelow(string $sheet, string $label): ?string
    {
        if (! $this->hasSheet($sheet)) {
            return null;
        }
        $rows = $this->sheetCells($sheet)['rows'];
        foreach ($rows as $r => $cols) {
            foreach ($cols as $col => $v) {
                if (trim($v) === $label) {
                    return isset($rows[$r + 1][$col]) ? trim($rows[$r + 1][$col]) : null;
                }
            }
        }

        return null;
    }

    // ---- package ----

    private function exists(string $path): bool
    {
        return $this->zip->locateName($path) !== false;
    }

    private function read(string $path, int $limit = self::MAX_XML_BYTES): string
    {
        $data = $this->zip->getFromName($path, $limit + 1);
        if ($data === false) {
            throw new BusinessException('檔案內容不完整，請用 Excel 另存新檔後再匯入。');
        }
        if (strlen($data) > $limit) {
            throw new BusinessException('檔案內容過大，無法匯入。');
        }

        return $data;
    }

    private function xpath(string $path): DOMXPath
    {
        $xml = $this->read($path);
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new BusinessException('檔案格式不正確。');
        }
        $doc = new DOMDocument;
        if (! @$doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new BusinessException('檔案格式不正確，請用 Excel 另存新檔後再匯入。');
        }
        $x = new DOMXPath($doc);
        foreach (self::NS as $p => $uri) {
            $x->registerNamespace($p, $uri);
        }

        return $x;
    }

    /** @return list<array{id:string, type:string, target:string, external:bool}> */
    private function rels(string $part): array
    {
        $dir = dirname($part) === '.' ? '' : dirname($part);
        $relPath = ($dir === '' ? '' : $dir.'/').'_rels/'.basename($part).'.rels';
        if (! $this->exists($relPath)) {
            return [];
        }
        $out = [];
        foreach ($this->xpath($relPath)->query('/pr:Relationships/pr:Relationship') as $r) {
            /** @var DOMElement $r */
            $external = $r->getAttribute('TargetMode') === 'External';
            $target = $r->getAttribute('Target');
            if (! $external) {
                $target = str_starts_with($target, '/') ? ltrim($target, '/') : $this->normalize(($dir === '' ? '' : $dir.'/').$target);
            }
            $type = $r->getAttribute('Type');
            $out[] = ['id' => $r->getAttribute('Id'), 'type' => substr($type, strrpos($type, '/') + 1), 'target' => $target, 'external' => $external];
        }

        return $out;
    }

    private function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') {
                array_pop($parts);
            } elseif ($seg !== '.' && $seg !== '') {
                $parts[] = $seg;
            }
        }

        return implode('/', $parts);
    }

    private function loadWorkbook(): void
    {
        $wbPath = 'xl/workbook.xml';
        foreach ($this->rels('') as $r) {
            if ($r['type'] === 'officeDocument') {
                $wbPath = $r['target'];
            }
        }
        if (! $this->exists($wbPath)) {
            throw new BusinessException('這不是 Excel 活頁簿（.xlsx）。');
        }
        $rels = [];
        foreach ($this->rels($wbPath) as $r) {
            $rels[$r['id']] = $r;
        }
        $x = $this->xpath($wbPath);
        $this->date1904 = in_array(strtolower((string) $x->evaluate('string(/m:workbook/m:workbookPr/@date1904)')), ['1', 'true'], true);
        foreach ($x->query('/m:workbook/m:sheets/m:sheet') as $s) {
            /** @var DOMElement $s */
            $rel = $rels[$s->getAttributeNS(self::NS['r'], 'id')] ?? null;
            if ($rel && $rel['type'] === 'worksheet' && $this->exists($rel['target'])) {
                $this->sheets[$s->getAttribute('name')] = $rel['target'];
            }
        }
        foreach ($rels as $r) {
            if ($r['type'] === 'sharedStrings' && $this->exists($r['target'])) {
                $sx = $this->xpath($r['target']);
                foreach ($sx->query('/m:sst/m:si') as $si) {
                    $t = '';
                    foreach ($sx->query('.//m:t[not(ancestor::m:rPh)]', $si) as $n) {
                        $t .= $n->textContent;
                    }
                    $this->strings[] = $t;
                }
            } elseif ($r['type'] === 'styles' && $this->exists($r['target'])) {
                $this->loadStyles($r['target']);
            } elseif ($r['type'] === 'theme' && $this->exists($r['target'])) {
                $this->loadTheme($r['target']);
            }
        }
    }

    private function loadStyles(string $path): void
    {
        $x = $this->xpath($path);
        $custom = [];
        foreach ($x->query('/m:styleSheet/m:numFmts/m:numFmt') as $f) {
            /** @var DOMElement $f */
            $code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $f->getAttribute('formatCode'));
            $custom[(int) $f->getAttribute('numFmtId')] = (bool) preg_match('/[yde]/i', (string) $code);
        }
        $i = 0;
        foreach ($x->query('/m:styleSheet/m:cellXfs/m:xf') as $xf) {
            /** @var DOMElement $xf */
            $id = (int) $xf->getAttribute('numFmtId');
            if (in_array($id, self::DATE_FORMAT_IDS, true) || ($custom[$id] ?? false)) {
                $this->dateStyles[$i] = true;
            }
            $i++;
        }
    }

    private function loadTheme(string $path): void
    {
        $x = $this->xpath($path);
        foreach ($x->query('//a:clrScheme/*') as $el) {
            /** @var DOMElement $el */
            $c = $x->query('a:srgbClr/@val | a:sysClr/@lastClr', $el)->item(0);
            if ($c) {
                $this->theme[$el->localName] = strtoupper($c->nodeValue);
            }
        }
    }

    private function cellValue(DOMXPath $x, DOMElement $c): string
    {
        $t = $c->getAttribute('t');
        if ($t === 'inlineStr') {
            $s = '';
            foreach ($x->query('.//m:is//m:t[not(ancestor::m:rPh)]', $c) as $n) {
                $s .= $n->textContent;
            }

            return $this->clean($s);
        }
        $v = $x->query('m:v', $c)->item(0)?->textContent;
        if ($v === null) {
            return '';
        }

        return match ($t) {
            's' => $this->clean($this->strings[(int) $v] ?? ''),
            'str' => $this->clean($v),
            'b' => $v === '1' ? 'TRUE' : 'FALSE',
            'e' => '',
            default => isset($this->dateStyles[(int) $c->getAttribute('s')]) && is_numeric($v) ? $this->serialDate((float) $v) : $v,
        };
    }

    private function clean(string $s): string
    {
        return str_replace(["\r\n", "\r"], "\n", $s);
    }

    private function serialDate(float $serial): string
    {
        $days = (int) floor($serial) + ($this->date1904 ? 1462 : 0);
        $base = new \DateTimeImmutable('1899-12-30');
        $d = $base->modify("+{$days} days");
        $secs = (int) round(($serial - floor($serial)) * 86400);

        return $secs > 0 ? $d->modify("+{$secs} seconds")->format('Y-m-d H:i') : $d->format('Y-m-d');
    }

    private function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    /**
     * Sheet grid in EMU: column left edges and row top edges (0-based), from column widths and row heights.
     *
     * @return array{colX: callable(int): int, rowY: callable(int): int}
     */
    private function grid(DOMXPath $x): array
    {
        $fmt = $x->query('//m:sheetFormatPr')->item(0);
        $defRowPt = $fmt instanceof DOMElement && $fmt->getAttribute('defaultRowHeight') !== '' ? (float) $fmt->getAttribute('defaultRowHeight') : 15.0;
        $defColChars = $fmt instanceof DOMElement && $fmt->getAttribute('defaultColWidth') !== '' ? (float) $fmt->getAttribute('defaultColWidth') : null;
        $mdw = 7; // max digit width (px) of the default font: column widths are approximate, used for ordering only
        $charsToPx = fn (float $w) => (int) floor(((256 * $w + floor(128 / $mdw)) / 256) * $mdw);
        $defColPx = $defColChars !== null ? $charsToPx($defColChars) : 64;
        $cols = [];
        foreach ($x->query('//m:cols/m:col') as $c) {
            /** @var DOMElement $c */
            $px = $c->getAttribute('hidden') === '1' ? 0 : $charsToPx((float) $c->getAttribute('width'));
            for ($i = (int) $c->getAttribute('min'); $i <= min((int) $c->getAttribute('max'), 16384); $i++) {
                $cols[$i - 1] = $px;
                if ($i > 2000) {
                    break;
                }
            }
        }
        $rows = [];
        foreach ($x->query('//m:sheetData/m:row') as $r) {
            /** @var DOMElement $r */
            if ($r->getAttribute('hidden') === '1') {
                $rows[(int) $r->getAttribute('r') - 1] = 0.0;
            } elseif ($r->getAttribute('ht') !== '') {
                $rows[(int) $r->getAttribute('r') - 1] = (float) $r->getAttribute('ht');
            }
        }
        $colX = function (int $col) use ($cols, $defColPx): int {
            $px = 0;
            for ($i = 0; $i < $col; $i++) {
                $px += $cols[$i] ?? $defColPx;
            }

            return $px * self::EMU_PER_PX;
        };
        $rowY = function (int $row) use ($rows, $defRowPt): int {
            $pt = $row * $defRowPt;
            foreach ($rows as $i => $h) {
                if ($i < $row) {
                    $pt += $h - $defRowPt;
                }
            }

            return (int) round($pt * self::EMU_PER_PT);
        };

        return ['colX' => $colX, 'rowY' => $rowY];
    }

    // ---- drawings ----

    /** @return list<array<string, mixed>> */
    private function drawing(string $path, array $grid): array
    {
        $x = $this->xpath($path);
        $rels = [];
        foreach ($this->rels($path) as $r) {
            $rels[$r['id']] = $r;
        }
        $out = [];
        foreach ($x->query('/xdr:wsDr/*') as $anchor) {
            /** @var DOMElement $anchor */
            if (! in_array($anchor->localName, ['twoCellAnchor', 'oneCellAnchor', 'absoluteAnchor'], true)) {
                continue;
            }
            $box = $this->anchorBox($x, $anchor, $grid);
            foreach ($this->objectElements($x, $anchor) as $el) {
                $this->walk($x, $el, [0.0, 0.0, 1.0, 1.0], $rels, $box, $out);
            }
        }

        return $out;
    }

    /** Object elements of an anchor; mc:AlternateContent resolves to its Fallback, else its first Choice. */
    private function objectElements(DOMXPath $x, DOMElement $parent): array
    {
        $out = [];
        foreach ($parent->childNodes as $n) {
            if (! $n instanceof DOMElement) {
                continue;
            }
            if ($n->namespaceURI === self::NS['mc'] && $n->localName === 'AlternateContent') {
                $branch = $x->query('mc:Fallback', $n)->item(0) ?? $x->query('mc:Choice', $n)->item(0);
                if ($branch instanceof DOMElement) {
                    $out = array_merge($out, $this->objectElements($x, $branch));
                }
            } elseif (in_array($n->localName, ['pic', 'sp', 'cxnSp', 'grpSp', 'graphicFrame', 'contentPart'], true)) {
                $out[] = $n;
            }
        }

        return $out;
    }

    /** Box of an anchor from its cells (fallback when an object has no a:xfrm). @return int[]|null [x, y, w, h] */
    private function anchorBox(DOMXPath $x, DOMElement $a, array $grid): ?array
    {
        $pt = function (?DOMElement $m) use ($x, $grid): ?array {
            if (! $m) {
                return null;
            }
            $v = fn (string $n) => (int) $x->evaluate("string(xdr:$n)", $m);

            return [$grid['colX']($v('col')) + $v('colOff'), $grid['rowY']($v('row')) + $v('rowOff')];
        };
        $from = $pt($x->query('xdr:from', $a)->item(0));
        if ($a->localName === 'absoluteAnchor') {
            $pos = $x->query('xdr:pos', $a)->item(0);
            $from = $pos instanceof DOMElement ? [(int) $pos->getAttribute('x'), (int) $pos->getAttribute('y')] : null;
        }
        if (! $from) {
            return null;
        }
        if ($a->localName === 'twoCellAnchor') {
            $to = $pt($x->query('xdr:to', $a)->item(0));

            return $to ? [$from[0], $from[1], max(0, $to[0] - $from[0]), max(0, $to[1] - $from[1])] : null;
        }
        $ext = $x->query('xdr:ext', $a)->item(0);

        return $ext instanceof DOMElement ? [$from[0], $from[1], (int) $ext->getAttribute('cx'), (int) $ext->getAttribute('cy')] : null;
    }

    /**
     * Flatten one object (groups recursively) into absolute boxes. $t = [a, b, c, d]: X = a + x*c, Y = b + y*d.
     *
     * @param  list<array<string, mixed>>  $out
     */
    private function walk(DOMXPath $x, DOMElement $el, array $t, array $rels, ?array $anchorBox, array &$out): void
    {
        $name = $el->localName;
        if ($name === 'grpSp') {
            $xf = $x->query('xdr:grpSpPr/a:xfrm', $el)->item(0);
            if ($xf instanceof DOMElement) {
                [$gx, $gy, $gw, $gh] = $this->xfrmBox($x, $xf);
                $chOff = $x->query('a:chOff', $xf)->item(0);
                $chExt = $x->query('a:chExt', $xf)->item(0);
                $cx = $chOff instanceof DOMElement ? (int) $chOff->getAttribute('x') : $gx;
                $cy = $chOff instanceof DOMElement ? (int) $chOff->getAttribute('y') : $gy;
                $cw = $chExt instanceof DOMElement ? max(1, (int) $chExt->getAttribute('cx')) : max(1, $gw);
                $ch = $chExt instanceof DOMElement ? max(1, (int) $chExt->getAttribute('cy')) : max(1, $gh);
                if ($anchorBox) {
                    // top-level group: its child space maps onto the anchor box (the anchor is what Excel draws)
                    [$gx, $gy, $gw, $gh] = $anchorBox;
                    $t = [$gx - $cx * $gw / $cw, $gy - $cy * $gh / $ch, $gw / $cw, $gh / $ch];
                } else {
                    $sx = $gw / $cw;
                    $sy = $gh / $ch;
                    $t = [$t[0] + ($gx - $cx * $sx) * $t[2], $t[1] + ($gy - $cy * $sy) * $t[3], $t[2] * $sx, $t[3] * $sy];
                }
            }
            foreach ($this->objectElements($x, $el) as $child) {
                $this->walk($x, $child, $t, $rels, null, $out);
            }

            return;
        }

        $xfPath = $name === 'graphicFrame' ? 'xdr:xfrm' : 'xdr:spPr/a:xfrm';
        $xf = $x->query($xfPath, $el)->item(0);
        $rot = $xf instanceof DOMElement ? (int) $xf->getAttribute('rot') / 60000 : 0;
        $flipH = $xf instanceof DOMElement && $xf->getAttribute('flipH') === '1';
        $flipV = $xf instanceof DOMElement && $xf->getAttribute('flipV') === '1';
        if ($anchorBox) {
            // top-level object: the cell anchor is authoritative; a:xfrm is often stale (rows resized, sheet copied)
            $box = $anchorBox;
        } elseif ($xf instanceof DOMElement) {
            [$ox, $oy, $ow, $oh] = $this->xfrmBox($x, $xf);
            $box = [(int) round($t[0] + $ox * $t[2]), (int) round($t[1] + $oy * $t[3]), (int) round($ow * $t[2]), (int) round($oh * $t[3])];
        } else {
            return;
        }
        [$bx, $by, $bw, $bh] = $box;
        $base = ['x' => $bx, 'y' => $by, 'w' => $bw, 'h' => $bh, 'rot' => $rot, 'flipH' => $flipH, 'flipV' => $flipV];

        if ($name === 'pic') {
            $blip = $x->query('xdr:blipFill/a:blip', $el)->item(0);
            $rel = $blip instanceof DOMElement ? ($rels[$blip->getAttributeNS(self::NS['r'], 'embed')] ?? null) : null;
            $media = $rel && ! $rel['external'] ? $rel['target'] : null;
            $ext = $media ? strtolower(pathinfo($media, PATHINFO_EXTENSION)) : '';
            if (! $media || ! isset(self::IMAGE_MIME[$ext]) || ! $this->exists($media)) {
                $out[] = $base + ['kind' => 'unsupported', 'reason' => $media ? strtoupper($ext).' 圖片' : '外部連結圖片'];

                return;
            }
            $src = $x->query('xdr:blipFill/a:srcRect', $el)->item(0);
            $crop = ['l' => 0, 't' => 0, 'r' => 0, 'b' => 0];
            if ($src instanceof DOMElement) {
                foreach ($crop as $k => $_) {
                    $crop[$k] = (int) $src->getAttribute($k) / 100000;
                }
            }
            $out[] = $base + ['kind' => 'pic', 'media' => $media, 'crop' => $crop];

            return;
        }
        if ($name === 'graphicFrame' || $name === 'contentPart') {
            $uri = (string) $x->evaluate('string(.//a:graphicData/@uri)', $el);
            $out[] = $base + ['kind' => 'unsupported', 'reason' => str_contains($uri, 'chart') ? '圖表' : (str_contains($uri, 'diagram') ? 'SmartArt' : '物件')];

            return;
        }

        // sp / cxnSp
        $spPr = $x->query('xdr:spPr', $el)->item(0);
        $style = $x->query('xdr:style', $el)->item(0);
        $geom = (string) $x->evaluate('string(xdr:spPr/a:prstGeom/@prst)', $el);
        if ($geom === '') {
            $geom = $x->query('xdr:spPr/a:custGeom', $el)->length ? 'custom' : 'rect';
        }
        $txBox = $x->evaluate('string(xdr:nvSpPr/xdr:cNvSpPr/@txBox)', $el) === '1';
        $ln = $spPr instanceof DOMElement ? $x->query('a:ln', $spPr)->item(0) : null;
        $out[] = $base + [
            'kind' => 'shape',
            'geom' => $txBox && $geom === 'custom' ? 'rect' : $geom,
            'stroke' => $this->stroke($x, $ln, $style, $name === 'cxnSp'),
            'fill' => $name === 'cxnSp' ? null : $this->fill($x, $spPr, $style),
            'text' => $name === 'sp' ? $this->text($x, $el, $style) : null,
        ];
    }

    /** @return int[] [x, y, w, h] of an a:xfrm (EMU) */
    private function xfrmBox(DOMXPath $x, DOMElement $xf): array
    {
        $off = $x->query('a:off', $xf)->item(0);
        $ext = $x->query('a:ext', $xf)->item(0);

        return [
            $off instanceof DOMElement ? (int) $off->getAttribute('x') : 0, $off instanceof DOMElement ? (int) $off->getAttribute('y') : 0,
            $ext instanceof DOMElement ? (int) $ext->getAttribute('cx') : 0, $ext instanceof DOMElement ? (int) $ext->getAttribute('cy') : 0,
        ];
    }

    /** @return array{color:string, alpha:float, width:int, dash:string, head:string, tail:string}|null */
    private function stroke(DOMXPath $x, ?\DOMNode $ln, ?\DOMNode $style, bool $connector): ?array
    {
        $color = null;
        $width = null;
        if ($ln instanceof DOMElement) {
            if ($x->query('a:noFill', $ln)->length) {
                return null;
            }
            $color = $this->colorOf($x, $x->query('a:solidFill', $ln)->item(0)) ?? $this->colorOf($x, $x->query('a:gradFill//a:gs', $ln)->item(0));
            $width = $ln->getAttribute('w') !== '' ? (int) $ln->getAttribute('w') : null;
        }
        $ref = $style instanceof DOMElement ? $x->query('a:lnRef', $style)->item(0) : null;
        $idx = $ref instanceof DOMElement ? (int) $ref->getAttribute('idx') : 0;
        if ($color === null && $idx > 0) {
            $color = $this->colorOf($x, $ref);
        }
        if ($color === null) {
            if (! $ln instanceof DOMElement && ! $connector) {
                return null;
            }
            $color = ['hex' => '#000000', 'alpha' => 1.0];
        }
        $width ??= [9525, 9525, 25400, 38100][min($idx, 3)];
        $end = fn (string $n) => $ln instanceof DOMElement ? ((string) $x->evaluate("string(a:$n/@type)", $ln) ?: 'none') : 'none';

        return [
            'color' => $color['hex'], 'alpha' => $color['alpha'], 'width' => $width,
            'dash' => $ln instanceof DOMElement ? ((string) $x->evaluate('string(a:prstDash/@val)', $ln) ?: 'solid') : 'solid',
            'head' => $end('headEnd'), 'tail' => $end('tailEnd'),
        ];
    }

    /** @return array{color:string, alpha:float}|null */
    private function fill(DOMXPath $x, ?\DOMNode $spPr, ?\DOMNode $style): ?array
    {
        if ($spPr instanceof DOMElement) {
            foreach ($spPr->childNodes as $n) {
                if (! $n instanceof DOMElement) {
                    continue;
                }
                $c = match ($n->localName) {
                    'noFill', 'blipFill' => false,
                    'solidFill' => $this->colorOf($x, $n),
                    'gradFill' => $this->colorOf($x, $x->query('.//a:gs', $n)->item(0)),
                    'pattFill' => $this->colorOf($x, $x->query('a:fgClr', $n)->item(0)),
                    default => null,
                };
                if ($c === false) {
                    return null;
                }
                if ($c !== null) {
                    return ['color' => $c['hex'], 'alpha' => $c['alpha']];
                }
            }
        }
        $ref = $style instanceof DOMElement ? $x->query('a:fillRef', $style)->item(0) : null;
        if ($ref instanceof DOMElement && (int) $ref->getAttribute('idx') > 0 && ($c = $this->colorOf($x, $ref))) {
            return ['color' => $c['hex'], 'alpha' => $c['alpha']];
        }

        return null;
    }

    /** @return array{paras: list<array{text:string, align:string}>, size:float, color:string, bold:bool, anchor:string, ins:int[]}|null */
    private function text(DOMXPath $x, DOMElement $sp, ?\DOMNode $style): ?array
    {
        $body = $x->query('xdr:txBody', $sp)->item(0);
        if (! $body instanceof DOMElement) {
            return null;
        }
        $paras = [];
        $size = null;
        $color = null;
        $bold = false;
        foreach ($x->query('a:p', $body) as $p) {
            $t = '';
            foreach ($p->childNodes as $n) {
                if (! $n instanceof DOMElement) {
                    continue;
                }
                if (in_array($n->localName, ['r', 'fld'], true)) {
                    $t .= (string) $x->evaluate('string(a:t)', $n);
                    $rPr = $x->query('a:rPr', $n)->item(0);
                    if ($rPr instanceof DOMElement && $size === null) {
                        $size = $rPr->getAttribute('sz') !== '' ? (int) $rPr->getAttribute('sz') / 100 : null;
                        $bold = $rPr->getAttribute('b') === '1';
                        $color = $this->colorOf($x, $x->query('a:solidFill', $rPr)->item(0));
                    }
                } elseif ($n->localName === 'br') {
                    $t .= "\n";
                }
            }
            $paras[] = ['text' => $t, 'align' => (string) $x->evaluate('string(a:pPr/@algn)', $p) ?: 'l'];
        }
        if (trim(implode('', array_column($paras, 'text'))) === '') {
            return null;
        }
        if ($color === null && $style instanceof DOMElement) {
            $color = $this->colorOf($x, $x->query('a:fontRef', $style)->item(0));
        }
        $pr = $x->query('a:bodyPr', $body)->item(0);
        $ins = fn (string $a, int $d) => $pr instanceof DOMElement && $pr->getAttribute($a) !== '' ? (int) $pr->getAttribute($a) : $d;

        return [
            'paras' => $paras,
            'size' => $size ?? 11.0,
            'color' => $color['hex'] ?? '#000000',
            'bold' => $bold,
            'anchor' => $pr instanceof DOMElement && $pr->getAttribute('anchor') !== '' ? $pr->getAttribute('anchor') : 't',
            'ins' => [$ins('lIns', 91440), $ins('tIns', 45720), $ins('rIns', 91440), $ins('bIns', 45720)],
        ];
    }

    /** Resolve the color child of $parent (srgb / scheme / sys / preset + lumMod, lumOff, shade, tint, alpha). */
    private function colorOf(DOMXPath $x, ?\DOMNode $parent): ?array
    {
        if (! $parent instanceof DOMElement) {
            return null;
        }
        foreach ($parent->childNodes as $c) {
            if (! $c instanceof DOMElement) {
                continue;
            }
            $hex = match ($c->localName) {
                'srgbClr' => $c->getAttribute('val'),
                'sysClr' => $c->getAttribute('lastClr') ?: '000000',
                'schemeClr' => $this->theme[['tx1' => 'dk1', 'bg1' => 'lt1', 'tx2' => 'dk2', 'bg2' => 'lt2'][$c->getAttribute('val')] ?? $c->getAttribute('val')] ?? null,
                'prstClr' => self::PRESET_COLORS[$c->getAttribute('val')] ?? '000000',
                default => false,
            };
            if ($hex === false) {
                continue;
            }
            $hex ??= $c->getAttribute('val') === 'lt1' || $c->getAttribute('val') === 'bg1' ? 'FFFFFF' : '000000';
            if (! preg_match('/^[0-9A-Fa-f]{6}$/', $hex)) {
                $hex = '000000';
            }

            return $this->modify($x, $c, strtoupper($hex));
        }

        return null;
    }

    private function modify(DOMXPath $x, DOMElement $c, string $hex): array
    {
        $rgb = array_map('hexdec', str_split($hex, 2));
        $alpha = 1.0;
        $mod = [];
        foreach ($c->childNodes as $m) {
            if ($m instanceof DOMElement) {
                $mod[$m->localName] = (int) $m->getAttribute('val') / 100000;
            }
        }
        if (isset($mod['shade'])) {
            $rgb = array_map(fn ($v) => $v * $mod['shade'], $rgb);
        }
        if (isset($mod['tint'])) {
            $rgb = array_map(fn ($v) => $v + (255 - $v) * (1 - $mod['tint']), $rgb);
        }
        if (isset($mod['lumMod']) || isset($mod['lumOff'])) {
            [$h, $s, $l] = $this->toHsl($rgb);
            $l = max(0.0, min(1.0, $l * ($mod['lumMod'] ?? 1) + ($mod['lumOff'] ?? 0)));
            $rgb = $this->fromHsl($h, $s, $l);
        }
        if (isset($mod['alpha'])) {
            $alpha = max(0.0, min(1.0, $mod['alpha']));
        }

        return ['hex' => '#'.implode('', array_map(fn ($v) => sprintf('%02X', max(0, min(255, (int) round($v)))), $rgb)), 'alpha' => $alpha];
    }

    private function toHsl(array $rgb): array
    {
        [$r, $g, $b] = array_map(fn ($v) => $v / 255, $rgb);
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        if ($max === $min) {
            return [0.0, 0.0, $l];
        }
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r => ($g - $b) / $d + ($g < $b ? 6 : 0),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };

        return [$h / 6, $s, $l];
    }

    private function fromHsl(float $h, float $s, float $l): array
    {
        if ($s == 0.0) {
            return [$l * 255, $l * 255, $l * 255];
        }
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $f = function (float $t) use ($p, $q): float {
            $t += $t < 0 ? 1 : ($t > 1 ? -1 : 0);

            return match (true) {
                $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                $t < 1 / 2 => $q,
                $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                default => $p,
            };
        };

        return [$f($h + 1 / 3) * 255, $f($h) * 255, $f($h - 1 / 3) * 255];
    }
}

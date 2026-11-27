<?php

namespace App\Services\Import;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Repositories\EventRepository;
use App\Repositories\IssueImportRepository;
use App\Repositories\MesPageRepository;
use App\Repositories\UnitOfWork;
use App\Services\AttachmentService;
use App\Services\Collab\SimilarService;
use App\Services\IssueService;
use App\Services\WorkflowService;

/**
 * Excel import of PM internal issues from the standard template (see IssueImportTemplateService).
 * preview(): parses the uploaded file, never writes. The browser then composes every picture with the shapes drawn on
 * it into one PNG and uploads it as a temp attachment of the row's context. apply(): re-parses the file and creates the
 * chosen rows in one transaction (or adds an addendum to rows imported before whose content changed).
 */
class IssueImportService
{
    public const FILE_CONTEXT = 'issue:import';

    public const TEMPLATE_VERSION = '1';

    public const VERSION_LABEL = 'template_version';

    public const TITLE_MAX = 200;

    /** Same scale IssueService::checkScale() enforces. */
    public const SEVERITIES = [1, 2, 3, 4, 5];

    /** Fields the importer reads itself; every other column is copied into the "會議紀錄" section. */
    private const CORE = ['no', 'date', 'process_type', 'func', 'mes_page', 'title', 'content', 'detail', 'status', 'severity'];

    private const SOURCE_MAX = 50;

    private const SIMILAR_ROWS = 100;

    /** Loose shapes closer than this (EMU, ~20 px) belong to the same drawing. */
    private const CLUSTER_GAP = 190500;

    private const DRAWING_PAD = 95250;

    public function __construct(
        private WorkflowService $wf,
        private AttachmentService $attachments,
        private IssueService $issues,
        private IssueImportRepository $repo,
        private MesPageRepository $pages,
        private SimilarService $similar,
        private EventRepository $events,
        private UnitOfWork $uow,
    ) {}

    public function authorize(User $u, int $projectId): void
    {
        if (! $u->seesInternal()) {
            throw new BusinessException('沒有權限匯入內部單。');
        }
        $this->wf->authorize($u, 'issue.create', $projectId);
        $this->wf->authorize($u, $this->wf->initial('issue_create_perm', 'pm'), $projectId);
    }

    public function rowContext(int $fileId, string $no): string
    {
        return self::FILE_CONTEXT.":$fileId:$no";
    }

    /** @return string[] */
    public function sources(int $projectId): array
    {
        return $this->repo->sources($projectId);
    }

    /** Parse the uploaded file: rows with state (new / same / changed / skip / error), blocks, warnings. */
    public function preview(User $u, int $projectId, int $fileId, string $source): array
    {
        $this->authorize($u, $projectId);
        $source = $this->cleanSource($source);
        $reader = $this->open($u, $fileId);
        try {
            $rows = $this->parse($reader, $projectId, $source);
        } finally {
            $reader->close();
        }
        $similarLeft = self::SIMILAR_ROWS;
        foreach ($rows as &$r) {
            $r['similar'] = [];
            if ($r['state'] === 'new' && $similarLeft-- > 0) {
                $r['similar'] = array_slice(array_values(array_filter($this->similar->internal($u, $projectId, $r['title']), fn ($s) => $s['type'] === 'issue')), 0, 3);
            }
            unset($r['hash']);
        }
        unset($r);
        $count = array_count_values(array_column($rows, 'state'));

        return [
            'source' => $source,
            'rows' => $rows,
            'counts' => ['new' => $count['new'] ?? 0, 'changed' => $count['changed'] ?? 0, 'same' => $count['same'] ?? 0,
                'skip' => $count['skip'] ?? 0, 'error' => $count['error'] ?? 0],
            'process_types' => collect($this->wf->lookupMap('process_type'))->map(fn ($n, $c) => ['code' => $c, 'name' => $n])->values()->all(),
            'mes_pages' => $this->pages->activePages($projectId)->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
        ];
    }

    /** @return array{0: string, 1: string} picture bytes and mime from the user's uploaded file */
    public function media(User $u, int $projectId, int $fileId, string $path): array
    {
        $this->authorize($u, $projectId);
        $reader = $this->open($u, $fileId);
        try {
            return $reader->media($path) ?? throw new BusinessException('找不到圖片。');
        } finally {
            $reader->close();
        }
    }

    /**
     * @param  list<array{no:string, action:string, images?:int[], process_type?:?string, mes_page_id?:?int}>  $wanted
     * @return list<array{no:string, action:string, issue_id:int, issue_no:string}>
     */
    public function apply(User $u, int $projectId, int $fileId, string $source, array $wanted): array
    {
        $this->authorize($u, $projectId);
        $source = $this->cleanSource($source);
        if ($wanted === []) {
            throw new BusinessException('請勾選要匯入的列。');
        }
        $reader = $this->open($u, $fileId);
        try {
            $rows = collect($this->parse($reader, $projectId, $source))->keyBy('no');
        } finally {
            $reader->close();
        }
        $processTypes = $this->wf->lookupCodes('process_type');

        $plan = [];
        foreach ($wanted as $w) {
            $no = (string) ($w['no'] ?? '');
            $row = $rows->get($no) ?? throw new BusinessException("序號 {$no} 不在檔案中，請重新預覽。");
            $action = (string) ($w['action'] ?? '');
            if (! ($action === 'create' && $row['state'] === 'new') && ! ($action === 'addendum' && $row['state'] === 'changed')) {
                throw new BusinessException("序號 {$no} 的狀態已改變，請重新預覽。");
            }
            $images = array_values(array_map('intval', (array) ($w['images'] ?? [])));
            $need = count(array_filter($row['blocks'], fn ($b) => in_array($b['type'], ['image', 'drawing'], true)));
            if (count($images) !== $need) {
                throw new BusinessException("序號 {$no} 的圖片數量不符，請重新預覽。");
            }
            $own = $this->attachments->tempImageIds($u, $this->rowContext($fileId, $no));
            if (array_diff($images, $own) !== []) {
                throw new BusinessException("序號 {$no} 的圖片無效，請重新預覽。");
            }
            $pt = array_key_exists('process_type', $w) ? ($w['process_type'] ?: null) : $row['process_type'];
            if ($pt !== null && ! in_array($pt, $processTypes, true)) {
                throw new BusinessException("序號 {$no} 的處理類型無效。");
            }
            $plan[] = [
                'row' => $row, 'action' => $action, 'images' => $images, 'process_type' => $pt,
                'mes_page_id' => array_key_exists('mes_page_id', $w) ? ((int) $w['mes_page_id'] ?: null) : $row['mes_page_id'],
            ];
        }

        return $this->uow->run(function () use ($u, $projectId, $fileId, $source, $plan) {
            $done = [];
            foreach ($plan as $p) {
                $row = $p['row'];
                $ctx = $this->rowContext($fileId, $row['no']);
                $html = $this->html($row, $p['images'], $source);
                $existing = $this->repo->lockOne($projectId, $source, $row['no']);
                if ($p['action'] === 'create') {
                    if ($existing) {
                        throw new BusinessException("序號 {$row['no']} 已經匯入過（可能有人同時匯入），請重新預覽。");
                    }
                    $issue = $this->issues->createInternal($u, $projectId, 'pm', [
                        'title' => $row['title'],
                        'process_type' => $p['process_type'],
                        'mes_page_id' => $p['mes_page_id'],
                        'severity' => $row['severity'],
                        'content_html' => $html,
                    ], $ctx);
                    $this->repo->create(['project_id' => $projectId, 'source' => $source, 'source_no' => $row['no'],
                        'issue_id' => $issue->id, 'content_hash' => $row['hash'], 'created_by' => $u->id]);
                    $this->events->log($projectId, 'issue', $issue->id, 'imported', null, null, $u->id, ['source' => $source, 'no' => $row['no']]);
                    $done[] = ['no' => $row['no'], 'action' => 'create', 'issue_id' => $issue->id, 'issue_no' => $issue->issue_no];
                } else {
                    if (! $existing) {
                        throw new BusinessException("序號 {$row['no']} 的匯入紀錄不存在，請重新預覽。");
                    }
                    $this->issues->addAddendum($u, $existing->issue_id, $projectId, $html, $ctx);
                    $this->repo->saveHash($existing, $row['hash']);
                    $done[] = ['no' => $row['no'], 'action' => 'addendum', 'issue_id' => $existing->issue_id, 'issue_no' => $row['existing']['issue_no'] ?? ''];
                }
            }

            return $done;
        });
    }

    // ---- parsing ----

    private function cleanSource(string $source): string
    {
        $s = trim($source);
        if ($s === '' || mb_strlen($s) > self::SOURCE_MAX || ! preg_match('/^[\p{L}\p{N}_.\- ]+$/u', $s)) {
            throw new BusinessException('來源代碼必填，最多 '.self::SOURCE_MAX.' 字，只能用文字、數字、空白、底線、點或減號。');
        }

        return $s;
    }

    private function open(User $u, int $fileId): XlsxIssueReader
    {
        $a = $this->attachments->importFile($u, $fileId, self::FILE_CONTEXT);
        if (! $a || strtolower(pathinfo($a->original_name, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new BusinessException('找不到上傳的 .xlsx 檔，請重新上傳。');
        }
        $path = $this->attachments->absolutePath($a) ?? throw new BusinessException('找不到上傳的檔案，請重新上傳。');

        return new XlsxIssueReader($path, (int) $this->wf->setting('issue_import.max_unzip_mb', '200') * 1048576);
    }

    /** @return list<array<string, mixed>> */
    private function parse(XlsxIssueReader $reader, int $projectId, string $source): array
    {
        $sheets = $this->wf->lookupMap('issue_import_sheet');
        if ($reader->valueBelow($sheets['lists'] ?? '', self::VERSION_LABEL) !== self::TEMPLATE_VERSION) {
            throw new BusinessException('這不是標準匯入範本，或範本版本不符（需要 v'.self::TEMPLATE_VERSION.'）。請從系統下載最新範本。');
        }
        $listSheet = $sheets['list'] ?? '';
        if (! $reader->hasSheet($listSheet)) {
            throw new BusinessException("找不到「{$listSheet}」工作表。");
        }
        $cells = $reader->sheetCells($listSheet);
        $columns = $this->wf->lookupMap('issue_import_column');
        $byName = array_flip($columns);

        // header row: field => column letter; unknown headers become note columns
        $col = [];
        $notes = [];
        foreach ($cells['rows'][1] ?? [] as $letter => $text) {
            $text = trim($text);
            $code = $byName[$text] ?? null;
            if ($code !== null && isset($col[$code])) {
                throw new BusinessException("表頭「{$text}」重複。");
            }
            if ($code !== null && in_array($code, self::CORE, true)) {
                $col[$code] = $letter;
            } elseif ($text !== '') {
                $notes[$letter] = $text;
            }
        }
        $missing = array_filter($this->wf->lookupCodes('issue_import_required'), fn ($c) => ! isset($col[$c]));
        if ($missing) {
            throw new BusinessException('缺少必要欄位：'.implode('、', array_map(fn ($c) => $columns[$c] ?? $c, $missing)).'。請使用標準範本。');
        }

        $dataRows = array_filter($cells['rows'], fn ($r) => $r > 1, ARRAY_FILTER_USE_KEY);
        if (count($dataRows) > (int) $this->wf->setting('issue_import.max_rows', '300')) {
            throw new BusinessException('資料列超過上限 '.$this->wf->setting('issue_import.max_rows', '300').' 筆，請分批匯入。');
        }

        $statusByName = array_flip($this->wf->lookupMap('issue_import_status'));
        $skip = $this->wf->lookupCodes('issue_import_skip');
        $ptByName = array_flip($this->wf->lookupMap('process_type'));
        $pageIds = [];
        foreach ($this->pages->activePages($projectId) as $pg) {
            $pageIds[$pg->name] = isset($pageIds[$pg->name]) ? false : $pg->id;   // false = name used twice
        }
        $existing = $this->repo->bySource($projectId, $source);
        $defaultSeverity = (int) $this->wf->setting('issue_import.default_severity', '3');
        $reserved = array_values($sheets);

        $out = [];
        $seen = [];
        $images = 0;
        foreach ($dataRows as $rowNo => $vals) {
            $get = fn (string $code) => isset($col[$code]) ? trim($vals[$col[$code]] ?? '') : '';
            $errors = [];
            $warnings = [];

            $no = $get('no');
            if (preg_match('/^\d+(\.0+)?$/', $no)) {
                $no = (string) (int) $no;
            } else {
                $errors[] = $no === '' ? "{$columns['no']}必填" : "{$columns['no']}必須是數字";
            }
            if ($no !== '' && isset($seen[$no])) {
                $errors[] = "{$columns['no']} {$no} 重複（第 {$seen[$no]} 列）";
            }
            if ($no !== '') {
                $seen[$no] ??= $rowNo;
            }

            $title = $get('title');
            if ($title === '') {
                $errors[] = "{$columns['title']}必填";
            } elseif (mb_strlen($title) > self::TITLE_MAX) {
                $errors[] = "{$columns['title']}超過 ".self::TITLE_MAX.' 字';
            }
            $content = $get('content');
            if ($content === '') {
                $errors[] = "{$columns['content']}必填";
            }
            if ($get('func') === '') {
                $errors[] = "{$columns['func']}必填";
            }
            $statusLabel = $get('status');
            $status = $statusByName[$statusLabel] ?? null;
            if ($status === null) {
                $errors[] = $statusLabel === '' ? "{$columns['status']}必填" : "{$columns['status']}「{$statusLabel}」不在選項中";
            }

            $sev = $get('severity');
            $severity = $sev === '' ? $defaultSeverity : (int) $sev;
            if ($sev !== '' && (! ctype_digit($sev) || ! in_array($severity, self::SEVERITIES, true))) {
                $errors[] = "{$columns['severity']}必須是 ".min(self::SEVERITIES).'～'.max(self::SEVERITIES);
            }

            $ptName = $get('process_type');
            $pt = $ptName === '' ? null : ($ptByName[$ptName] ?? null);
            if ($ptName !== '' && $pt === null) {
                $warnings[] = "{$columns['process_type']}「{$ptName}」不在選項中，將留空";
            }
            $pageName = $get('mes_page');
            $pageId = $pageName === '' ? null : ($pageIds[$pageName] ?? null);
            if ($pageName !== '' && ! $pageId) {
                $warnings[] = $pageId === false ? "{$columns['mes_page']}「{$pageName}」有同名頁面，請手動選擇" : "{$columns['mes_page']}「{$pageName}」找不到，將留空";
                $pageId = null;
            }

            $detail = null;
            if (isset($col['detail'])) {
                $ref = $col['detail'].$rowNo;
                $loc = $cells['links'][$ref] ?? null;
                if ($loc === '') {
                    $warnings[] = "{$columns['detail']}是外部連結，不會匯入";
                } elseif ($loc !== null && preg_match("/^'?(.+?)'?!/u", $loc, $m)) {
                    $detail = str_replace("''", "'", $m[1]);
                } elseif ($get('detail') !== '' && $reader->hasSheet($get('detail'))) {
                    $detail = $get('detail');
                } elseif ($get('detail') !== '') {
                    $warnings[] = "{$columns['detail']}沒有連到工作表";
                }
                if ($detail !== null && (! $reader->hasSheet($detail) || in_array($detail, $reserved, true))) {
                    $warnings[] = "找不到明細表「{$detail}」";
                    $detail = null;
                }
            }

            $noteList = [];
            foreach ($notes as $letter => $label) {
                $v = trim($vals[$letter] ?? '');
                if ($v !== '') {
                    $noteList[] = ['label' => $label, 'value' => $v];
                }
            }

            $blocks = [];
            $hashBlocks = [];
            if ($detail !== null && ! $errors && ! in_array($status, $skip, true)) {
                [$blocks, $hashBlocks, $blockWarnings] = $this->blocks($reader, $detail, $content);
                $warnings = array_merge($warnings, $blockWarnings);
                $images += count(array_filter($blocks, fn ($b) => in_array($b['type'], ['image', 'drawing'], true)));
            }
            if (preg_match('/[A-Za-z]:\\\\|\\\\\\\\|[^\s"]+\\\\[^\s"]+/u', $content.' '.implode(' ', array_column($noteList, 'value')))) {
                $warnings[] = '內容提到外部檔案路徑，匯入後看不到該檔案';
            }

            $hash = hash('sha256', json_encode([$title, $content, $noteList, $hashBlocks], JSON_UNESCAPED_UNICODE));
            $prev = $no !== '' ? $existing->get($no) : null;
            $state = match (true) {
                $errors !== [] => 'error',
                in_array($status, $skip, true) => 'skip',
                $prev !== null => $prev->content_hash === $hash ? 'same' : 'changed',
                default => 'new',
            };

            $out[] = [
                'no' => $no, 'row' => $rowNo, 'state' => $state, 'title' => $title, 'func' => $get('func'),
                'date' => $get('date'), 'status' => $status, 'status_label' => $statusLabel, 'severity' => $severity,
                'process_type' => $pt, 'mes_page_id' => $pageId, 'content' => $content, 'notes' => $noteList,
                'detail' => $detail, 'blocks' => $blocks, 'errors' => $errors, 'warnings' => $warnings, 'hash' => $hash,
                'existing' => $prev ? ['issue_id' => $prev->issue_id, 'issue_no' => $prev->issue?->issue_no,
                    'url' => route('issues.show', $prev->issue_id)] : null,
            ];
        }
        if ($images > (int) $this->wf->setting('issue_import.max_images', '500')) {
            throw new BusinessException('圖片超過上限 '.$this->wf->setting('issue_import.max_images', '500').' 張，請分批匯入。');
        }

        return $out;
    }

    /**
     * Detail sheet -> ordered blocks (text, table, image with overlays, drawing of loose shapes, unsupported).
     *
     * @return array{0: list<array<string, mixed>>, 1: list<mixed>, 2: string[]}
     */
    private function blocks(XlsxIssueReader $reader, string $sheet, string $content): array
    {
        $c = $reader->sheetContent($sheet);
        $warnings = [];
        $pics = array_values(array_filter($c['objects'], fn ($o) => $o['kind'] === 'pic'));
        $loose = [];
        $overlays = array_fill(0, count($pics), []);
        foreach ($c['objects'] as $o) {
            if ($o['kind'] === 'pic') {
                continue;
            }
            if ($o['kind'] === 'unsupported') {
                $warnings[] = "明細表「{$sheet}」有無法匯入的{$o['reason']}";

                continue;
            }
            if (! in_array($o['geom'], XlsxIssueReader::GEOMS, true)) {
                $warnings[] = "明細表「{$sheet}」有不支援的圖案（{$o['geom']}），只畫出外框";
            }
            $cx = $o['x'] + $o['w'] / 2;
            $cy = $o['y'] + $o['h'] / 2;
            $host = null;
            foreach ($pics as $i => $p) {   // the topmost picture under the shape's centre wins
                if ($cx >= $p['x'] && $cx <= $p['x'] + $p['w'] && $cy >= $p['y'] && $cy <= $p['y'] + $p['h']) {
                    $host = $i;
                }
            }
            if ($host !== null) {
                $overlays[$host][] = $this->relative($o, $pics[$host]['x'], $pics[$host]['y']);
            } else {
                $loose[] = $o;
            }
        }

        $items = [];
        foreach ($pics as $i => $p) {
            $items[] = ['y' => $p['y'], 'x' => $p['x'], 'block' => ['type' => 'image', 'media' => $p['media'], 'w' => $p['w'], 'h' => $p['h'],
                'crop' => $p['crop'], 'rot' => $p['rot'], 'flipH' => $p['flipH'], 'flipV' => $p['flipV'], 'overlays' => $overlays[$i]]];
        }
        foreach ($this->clusters($loose) as $group) {
            $x0 = min(array_column($group, 'x')) - self::DRAWING_PAD;
            $y0 = min(array_column($group, 'y')) - self::DRAWING_PAD;
            $x1 = max(array_map(fn ($o) => $o['x'] + $o['w'], $group)) + self::DRAWING_PAD;
            $y1 = max(array_map(fn ($o) => $o['y'] + $o['h'], $group)) + self::DRAWING_PAD;
            $items[] = ['y' => $y0, 'x' => $x0, 'block' => ['type' => 'drawing', 'w' => $x1 - $x0, 'h' => $y1 - $y0,
                'overlays' => array_map(fn ($o) => $this->relative($o, $x0, $y0), $group)]];
        }
        $byRow = [];
        foreach ($c['texts'] as $t) {
            $byRow[$t['row']][] = $t;
        }
        $norm = fn (string $s) => preg_replace('/\s+/u', '', $s);
        $first = true;
        ksort($byRow);
        foreach ($byRow as $cellsInRow) {
            usort($cellsInRow, fn ($a, $b) => $a['col'] <=> $b['col']);
            if (count($cellsInRow) === 1) {
                $text = $cellsInRow[0]['text'];
                // the first text repeating the 問題內容 cell (often a formula copy of it) is dropped
                if ($first && $norm($text) === $norm($content)) {
                    $first = false;

                    continue;
                }
                $items[] = ['y' => $cellsInRow[0]['y'], 'x' => $cellsInRow[0]['x'], 'block' => ['type' => 'text', 'text' => $text]];
            } else {
                $items[] = ['y' => $cellsInRow[0]['y'], 'x' => $cellsInRow[0]['x'], 'block' => ['type' => 'trow', 'cells' => array_column($cellsInRow, 'text')]];
            }
            $first = false;
        }
        usort($items, fn ($a, $b) => [$a['y'], $a['x']] <=> [$b['y'], $b['x']]);

        $blocks = [];
        foreach ($items as $it) {
            $b = $it['block'];
            if ($b['type'] === 'trow') {
                $last = array_key_last($blocks);
                if ($last !== null && $blocks[$last]['type'] === 'table') {
                    $blocks[$last]['rows'][] = $b['cells'];
                } else {
                    $blocks[] = ['type' => 'table', 'rows' => [$b['cells']]];
                }
            } else {
                $blocks[] = $b;
            }
        }
        $hash = array_map(fn ($b) => $b['type'] === 'image' ? ['image', $reader->fingerprint($b['media']), $b['crop'], $b['overlays']] : $b, $blocks);

        return [$blocks, $hash, array_values(array_unique($warnings))];
    }

    private function relative(array $o, int $x0, int $y0): array
    {
        $o['x'] -= $x0;
        $o['y'] -= $y0;
        unset($o['kind']);

        return $o;
    }

    /**
     * Group loose shapes whose boxes touch (within CLUSTER_GAP): one drawing per group.
     *
     * @param  list<array<string, mixed>>  $shapes
     * @return list<list<array<string, mixed>>>
     */
    private function clusters(array $shapes): array
    {
        $n = count($shapes);
        $parent = range(0, max(0, $n - 1));
        $find = function (int $i) use (&$parent, &$find): int {
            return $parent[$i] === $i ? $i : ($parent[$i] = $find($parent[$i]));
        };
        $g = self::CLUSTER_GAP;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $shapes[$i];
                $b = $shapes[$j];
                if ($a['x'] - $g <= $b['x'] + $b['w'] && $b['x'] - $g <= $a['x'] + $a['w'] && $a['y'] - $g <= $b['y'] + $b['h'] && $b['y'] - $g <= $a['y'] + $a['h']) {
                    $parent[$find($i)] = $find($j);
                }
            }
        }
        $groups = [];
        for ($i = 0; $i < $n; $i++) {
            $groups[$find($i)][] = $shapes[$i];
        }

        return array_values($groups);
    }

    // ---- content ----

    /** @param int[] $images attachment ids, one per image / drawing block in order */
    private function html(array $row, array $images, string $source): string
    {
        $columns = $this->wf->lookupMap('issue_import_column');
        $meta = ["來源：{$source} #{$row['no']}"];
        if ($row['date'] !== '') {
            $meta[] = ($columns['date'] ?? 'date')."：{$row['date']}";
        }
        $meta[] = ($columns['status'] ?? 'status')."：{$row['status_label']}";
        $h = '<p>'.e(implode('｜', $meta)).'</p>'.$this->para($row['content']);
        $k = 0;
        foreach ($row['blocks'] as $b) {
            $h .= match ($b['type']) {
                'text' => $this->para($b['text']),
                'table' => '<table><tbody>'.implode('', array_map(fn ($r) => '<tr>'.implode('', array_map(fn ($c) => '<td>'.nl2br(e($c), false).'</td>', $r)).'</tr>', $b['rows'])).'</tbody></table>',
                'image', 'drawing' => '<p><img src="'.e((string) parse_url(route('attachments.show', $images[$k++]), PHP_URL_PATH)).'" alt=""></p>',
                default => '',
            };
        }
        if ($row['notes']) {
            $h .= '<h3>會議紀錄</h3>';
            foreach ($row['notes'] as $n) {
                $h .= '<p><strong>'.e($n['label']).'</strong>：'.nl2br(e($n['value']), false).'</p>';
            }
        }

        return $h;
    }

    private function para(string $text): string
    {
        return '<p>'.nl2br(e($text), false).'</p>';
    }
}

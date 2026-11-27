<?php

namespace App\Services\Import;

use App\Repositories\MesPageRepository;
use App\Services\WorkflowService;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Validation\CellReference;
use OpenSpout\Writer\XLSX\Validation\Rules\CustomValidationRule;
use OpenSpout\Writer\XLSX\Validation\Rules\ListValidationRule;
use OpenSpout\Writer\XLSX\Validation\Rules\TextLengthValidationRule;
use OpenSpout\Writer\XLSX\Validation\ValidationDisplay;
use OpenSpout\Writer\XLSX\Validation\ValidationOperator;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Downloadable standard template for the PM issue import: headers, sheet names and dropdown values all come from
 * lookups / the project's MES pages at download time, so the lists are never stale.
 */
class IssueImportTemplateService
{
    /** Dropdown validations reach this row, so rows typed later inherit them. */
    private const LAST_ROW = 1000;

    /** Column widths (characters) by field; other columns get the default. Layout only. */
    private const WIDTHS = ['no' => 8, 'date' => 11, 'process_type' => 11, 'func' => 18, 'mes_page' => 16, 'title' => 40,
        'content' => 60, 'detail' => 10, 'status' => 10, 'severity' => 9];

    public function __construct(private WorkflowService $wf, private MesPageRepository $pages) {}

    /** Write the template to $path. */
    public function write(int $projectId, string $path): void
    {
        $sheets = $this->wf->lookupMap('issue_import_sheet');
        $columns = $this->wf->lookupMap('issue_import_column');
        $required = $this->wf->lookupCodes('issue_import_required');
        $codes = array_keys($columns);
        $col = fn (string $code) => array_search($code, $codes, true);

        $lists = [
            [$columns['process_type'] ?? '處理類型', array_values($this->wf->lookupMap('process_type'))],
            [$columns['status'] ?? '狀態', array_values($this->wf->lookupMap('issue_import_status'))],
            [$columns['severity'] ?? '嚴重度', IssueImportService::SEVERITIES],
            [$columns['mes_page'] ?? 'MES頁面', $this->pages->activePages($projectId)->pluck('name')->unique()->values()->all()],
        ];
        $listField = ['process_type', 'status', 'severity', 'mes_page'];

        $options = new Options;
        $display = fn (string $title, string $msg) => new ValidationDisplay(errorTitle: $title, error: $msg);
        foreach ($listField as $i => $field) {
            $c = $col($field);
            if ($c === false || $lists[$i][1] === []) {
                continue;
            }
            $ref = new CellReference($i, 2, $i, count($lists[$i][1]) + 1, true, $sheets['lists']);
            $options->addValidation($c, 2, $c, self::LAST_ROW, new ListValidationRule($ref), $display($lists[$i][0], '請從下拉選單選擇。'));
        }
        if (($c = $col('no')) !== false) {
            $l = $this->letter($c);
            $options->addValidation($c, 2, $c, self::LAST_ROW, new CustomValidationRule("AND(ISNUMBER({$l}2),COUNTIF(\${$l}:\${$l},{$l}2)=1)"),
                $display($columns['no'], '必須是數字且不可重複；建立後請勿更改。'));
        }
        if (($c = $col('title')) !== false) {
            $options->addValidation($c, 2, $c, self::LAST_ROW, new TextLengthValidationRule(ValidationOperator::LessThanOrEqual, IssueImportService::TITLE_MAX),
                $display($columns['title'], '最多 '.IssueImportService::TITLE_MAX.' 字。'));
        }

        $head = (new Style)->withFontBold(true)->withBackgroundColor('E0E7FF');
        $headReq = (new Style)->withFontBold(true)->withBackgroundColor('E0E7FF')->withFontColor('DC2626');
        $bold = (new Style)->withFontBold(true);
        $wrap = (new Style)->withShouldWrapText(true);

        $w = new Writer($options);
        $w->openToFile($path);

        // 1. issue list: header row only
        $ws = $w->getCurrentSheet();
        $ws->setName($sheets['list']);
        $ws->setSheetView((new SheetView)->withFreezeRow(2));
        foreach ($codes as $i => $code) {
            $ws->setColumnWidth(self::WIDTHS[$code] ?? 14, $i + 1);
        }
        $w->addRow(new Row(array_map(fn ($code) => new StringCell($columns[$code], in_array($code, $required, true) ? $headReq : $head), $codes)));

        // 2. instructions
        $hs = $w->addNewSheetAndMakeItCurrent();
        $hs->setName($sheets['help']);
        $hs->setColumnWidth(24, 1);
        $hs->setColumnWidth(110, 2);
        foreach ($this->helpLines($sheets, $columns, $required) as [$a, $b, $isTitle]) {
            $w->addRow(new Row([new StringCell($a, $isTitle ? $bold : null), new StringCell($b, $wrap)]));
        }

        // 3. dropdown values + template version (hidden)
        $ls = $w->addNewSheetAndMakeItCurrent();
        $ls->setName($sheets['lists']);
        $max = max(array_map(fn ($l) => count($l[1]), $lists));
        $verCol = count($lists) + 1;   // one empty column, then the version marker
        for ($r = 0; $r <= $max; $r++) {
            $cells = [];
            foreach ($lists as [$title, $values]) {
                $v = $r === 0 ? $title : ($values[$r - 1] ?? null);
                $cells[] = is_int($v) ? new NumericCell($v, null) : new StringCell((string) $v, $r === 0 ? $bold : null);
            }
            $cells[] = new StringCell('', null);
            $cells[] = new StringCell(match ($r) {
                0 => IssueImportService::VERSION_LABEL,
                1 => IssueImportService::TEMPLATE_VERSION,
                default => '',
            }, null);
            $w->addRow(new Row($cells));
        }
        $ls->setColumnWidth(18, 1, 2, 3, 4);
        $ls->setColumnWidth(18, $verCol + 1);
        $ls->setIsVisible(false);

        $w->close();
    }

    public function filename(): string
    {
        return '內部問題匯入範本_v'.IssueImportService::TEMPLATE_VERSION.'_'.now()->timezone('Asia/Taipei')->format('Ymd').'.xlsx';
    }

    private function letter(int $index): string
    {
        $s = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26).$s;
        }

        return $s;
    }

    /** @return list<array{0:string, 1:string, 2:bool}> */
    private function helpLines(array $sheets, array $columns, array $required): array
    {
        $req = implode('、', array_map(fn ($c) => $columns[$c] ?? $c, $required));
        $skip = implode('、', array_map(fn ($c) => $this->wf->lookupLabel('issue_import_status', $c), $this->wf->lookupCodes('issue_import_skip')));
        $col = fn (string $c) => '「'.($columns[$c] ?? $c).'」';
        $lines = [
            ['CITS 內部問題匯入範本 v'.IssueImportService::TEMPLATE_VERSION, '', true],
            ['', '', false],
            ['基本規則', '', true],
            ['1', "「{$sheets['list']}」一列 = 一張內部單。第 1 列表頭請勿改名或刪除；欄位順序可調整，可自行增加欄位（匯入時放進內容最後的「會議紀錄」段落）。", false],
            ['2', "必填欄位：{$req}（紅字表頭）。", false],
            ['3', $col('no').'必須是數字且不可重複；建立後請勿更改（系統用「來源代碼 + 序號」判斷是否已匯入）。新問題請接續最大序號。', false],
            ['4', $col('status')."為「{$skip}」的列不會匯入；其他列一律建立為新建的內部單。", false],
            ['5', $col('title').'最多 '.IssueImportService::TITLE_MAX.' 字，匯入時直接使用。', false],
            ['6', $col('process_type').'、'.$col('status').'、'.$col('severity').'、'.$col('mes_page').'請用下拉選單；'.$col('mes_page').'可留空，匯入後再指定。'.$col('severity').'留空時用系統預設值。', false],
            ['7', '已匯入的列之後再修改內容，重新匯入時會列為「內容有變更」，可選擇寫成補充說明。', false],
            ['', '', false],
            ['明細表（截圖、補充說明）', '', true],
            ['8', '有截圖或長說明時，新增一張工作表，並在'.$col('detail').'插入超連結指向該表（插入 > 連結 > 這份文件中的位置）。工作表名稱不限。', false],
            ['9', '明細表內的文字與圖片依位置由上而下排入單據內容；同一列有多格文字會轉成表格。', false],
            ['10', '插入圖片請用「放置在儲存格上」，不要用「放置在儲存格中」。', false],
            ['11', '紅框、箭頭、文字方塊、流程圖框可以直接畫在截圖上，匯入時會合成成一張圖片；圖表、SmartArt、EMF 圖片無法匯入，預覽時會提示。', false],
            ['12', '只寫外部檔案路徑時，匯入後看不到檔案，請把需要的圖貼進明細表。', false],
            ['', '', false],
            ['請勿修改', "隱藏的「{$sheets['lists']}」工作表存放下拉選項與範本版本，請勿刪除。", true],
        ];

        return $lines;
    }
}

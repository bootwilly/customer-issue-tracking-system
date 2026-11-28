<?php

namespace App\Services\Export;

use App\Models\Issue;
use App\Models\User;
use App\Repositories\IssueExportRepository;
use App\Repositories\IssueRepository;
use App\Repositories\ProjectRepository;
use App\Services\Dev\DevService;
use App\Services\Pm\PmQueryService;
use App\Services\Test\TestService;
use App\Services\WorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Excel export of internal issues (PM / DEV / Test lists). Rows come from the same filter as the page. */
class IssueExportService
{
    private const TZ = 'Asia/Taipei';

    private const TEST_TABS = ['active' => '測試與上線', 'bounced' => '已退回/新發現'];

    public function __construct(
        private IssueRepository $issues,
        private IssueExportRepository $exportRepo,
        private ProjectRepository $projects,
        private WorkflowService $wf,
        private ExportColumnService $columns,
        private XlsxExportService $xlsx,
        private DevService $dev,
        private TestService $test,
        private PmQueryService $pm,
    ) {}

    public function canExport(User $u, int $projectId): bool
    {
        return $this->wf->can($u, 'data.export', $projectId);
    }

    /**
     * @param  string  $listKey  pm_issues|dev_issues|test_issues
     * @param  array  $f  the exact filter array passed to IssueRepository::paginate
     * @param  (callable(Issue):?string)|null  $weekOf
     */
    public function issueSheet(string $listKey, int $projectId, array $f, string $sheetName = '內部單', ?callable $weekOf = null): ExportSheet
    {
        $rows = $this->issues->listFiltered($projectId, $f, $this->xlsx->maxRows() + 1);
        $this->xlsx->assertWithinLimit($rows->count());

        return $this->buildSheet($projectId, $listKey, $sheetName, $rows, $weekOf);
    }

    /** PM issue list: $f = PmController::index validated filters (stage,status,origin,q,sort). */
    public function pmIssuesSheet(User $u, int $projectId, array $f): ExportSheet
    {
        return $this->issueSheet('pm_issues', $projectId, $this->pm->issueFilters($f));
    }

    public function devBook(User $u, int $projectId, array $in): ExportBook
    {
        $fl = $this->dev->filters($u, $projectId, $in);
        $info = ['頁籤' => DevService::TABS[$fl['tab']]];
        if ($fl['tab'] === 'close') {
            $rows = $this->dev->closeRounds($u, $projectId, $fl, $this->xlsx->maxRows() + 1);
            $this->xlsx->assertWithinLimit($rows->count());
            $sheet = new ExportSheet($info['頁籤'], DevService::CLOSE_COLUMNS, $rows->map(function ($row) {
                $row['issue_no'] = new ExportLink($row['issue_no'], route('issues.show', $row['issue_id']).'#close-tasks');
                unset($row['issue_id']);

                return $row;
            })->all());
            $info['收尾狀態'] = $this->dev->closeStatuses()[$fl['status']];
            $info['關鍵字'] = $fl['q'] !== '' ? $fl['q'] : '無';
            $info['只看我待確認'] = $fl['mine'] ? '是' : '否';

            return $this->book('dev_issues', $projectId, $sheet->name, $sheet, $info);
        }
        if ($fl['tab'] === 'schedule') {
            $groups = $this->dev->scheduleGroups($this->dev->scheduleIssues($u, $projectId, $fl['mine']));
            $weeks = [];
            $all = new Collection;
            foreach ($groups as $label => $list) {
                foreach ($list as $i) {
                    $weeks[$i->id] = $label;
                    $all->push($i);
                }
            }
            $this->xlsx->assertWithinLimit($all->count());
            $sheet = $this->buildSheet($projectId, 'dev_issues', '開發問題', $this->issues->loadExportRelations($all), fn (Issue $i) => $weeks[$i->id] ?? null);
        } else {
            $sheet = $this->issueSheet('dev_issues', $projectId, $fl['f'], '開發問題');
        }

        if ($fl['tab'] !== 'schedule') {
            $info['狀態'] = $fl['status'] ? $this->wf->label('issue', $fl['status']) : '全部';
            $info['關鍵字'] = $fl['q'] !== '' ? $fl['q'] : '無';
            $info['僅逾期'] = $fl['overdue'] ? '是' : '否';
        }
        $info['只看我的'] = $fl['mine'] ? '是' : '否';

        return $this->book('dev_issues', $projectId, '開發問題', $sheet, $info);
    }

    public function testBook(User $u, int $projectId, array $in): ExportBook
    {
        $fl = $this->test->filters($u, $projectId, $in);
        $sheet = $this->issueSheet('test_issues', $projectId, $fl['f'], '測試問題');
        $info = [
            '頁籤' => self::TEST_TABS[$fl['tab']],
            '狀態' => $fl['status'] ? $this->wf->label('issue', $fl['status']) : '全部',
            '關鍵字' => $fl['q'] !== '' ? $fl['q'] : '無',
            '只看我的' => $fl['mine'] ? '是' : '否',
        ];

        return $this->book('test_issues', $projectId, '測試問題', $sheet, $info);
    }

    /** @param array<string,string> $info */
    private function book(string $listKey, int $projectId, string $label, ExportSheet $sheet, array $info): ExportBook
    {
        $code = (string) ($this->projects->find($projectId)?->code ?? 'project');

        return new ExportBook($listKey, $this->xlsx->filename($code, $label), [$sheet], $info);
    }

    /** @param Collection<int,Issue> $issues */
    private function buildSheet(int $projectId, string $listKey, string $sheetName, Collection $issues, ?callable $weekOf): ExportSheet
    {
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $statusMap = $this->wf->statusMap('issue');
        $originMap = $this->wf->lookupMap('issue_origin');
        $processMap = $this->wf->lookupMapAll('process_type'); // disabled types still have a name on old issues

        $resolvers = [
            'issue_no' => fn (Issue $i) => $i->issue_no,
            'title' => fn (Issue $i) => $i->title,
            'origin' => fn (Issue $i) => $originMap[$i->origin] ?? $i->origin,
            'status' => fn (Issue $i) => $this->wf->label('issue', $i->status),
            'stage' => fn (Issue $i) => $i->stage,
            'severity' => fn (Issue $i) => $i->severity === null ? null : (int) $i->severity,
            'difficulty' => fn (Issue $i) => $i->difficulty,
            'process_type' => fn (Issue $i) => $i->process_type ? ($processMap[$i->process_type] ?? $i->process_type) : null,
            'mes_page' => fn (Issue $i) => $i->mesPage?->name,
            'assignee_dev' => fn (Issue $i) => $i->dev?->name,
            'assignee_test' => fn (Issue $i) => $i->tester?->name,
            'planned_due' => fn (Issue $i) => $i->planned_due,
            'current_due' => fn (Issue $i) => $i->current_due,
            'overdue_days' => fn (Issue $i) => $this->overdueDays($i, $today, $statusMap),
            'rework_count' => fn (Issue $i) => (int) $i->rework_count,
            'package' => fn (Issue $i) => $i->package?->name,
            'linked_tickets' => fn (Issue $i) => $i->tickets->pluck('ticket_no')->implode(', '),
            'created_at' => fn (Issue $i) => $i->created_at,
            'updated_at' => fn (Issue $i) => $i->updated_at,
        ];
        if ($weekOf !== null) {
            $resolvers['week'] = fn (Issue $i) => $weekOf($i);
        }

        $cols = $this->columns->columns($listKey, array_keys($resolvers));
        if ($listKey === 'dev_issues') {
            $times = $this->dev->dispatchTimes($projectId, $issues->pluck('id')->all());
            $cols['dispatched_at'] = DevService::DISPATCH_LABEL;
            $resolvers['dispatched_at'] = fn (Issue $i) => $times[$i->id] ?? '—';
        }
        $rows = [];
        foreach ($issues as $i) {
            $row = [];
            foreach (array_keys($cols) as $key) {
                $row[$key] = $resolvers[$key]($i);
            }
            $rows[] = $row;
        }

        $attachments = $this->exportRepo->attachmentsByIssue(
            $issues->mapWithKeys(fn (Issue $i) => [$i->id => $i->comments->pluck('id')->all()])->all()
        );
        $details = $issues->map(fn (Issue $i) => $this->detail($i, $attachments[$i->id] ?? collect()))->all();

        return new ExportSheet($sheetName, $cols, $rows, $details, 'title');
    }

    private function overdueDays(Issue $i, CarbonImmutable $today, Collection $statusMap): ?int
    {
        if ($i->current_due === null || ($statusMap[$i->status]->is_terminal ?? false)) {
            return null;
        }
        $due = CarbonImmutable::parse($i->current_due->toDateString(), self::TZ)->startOfDay();

        return $due->lt($today) ? (int) $due->diffInDays($today) : null;
    }

    private function detail(Issue $i, Collection $attachments): DetailBlock
    {
        $originMap = $this->wf->lookupMap('issue_origin');
        $url = url('/issues/'.$i->id);
        $fields = [
            '狀態' => $this->wf->label('issue', $i->status),
            '階段' => $i->stage,
            '來源' => $originMap[$i->origin] ?? $i->origin,
            '嚴重度' => $i->severity,
            'DEV' => $i->dev?->name,
            'Test' => $i->tester?->name,
            '目前期限' => $i->current_due,
            '網址' => new ExportLink($url, $url),
        ];

        $entries = fn (Collection $list) => $list->flatMap(fn ($e) => array_merge(
            ['['.$this->tw($e->created_at).'] '.($e->author?->name ?? '')],
            HtmlToText::lines($e->content_html),
            [''],
        ))->all();

        $tickets = $i->tickets->map(fn ($t) => "{$t->ticket_no} {$t->title}（".$this->wf->label('ticket', $t->status).'）')->all();
        $files = $attachments->map(fn ($a) => new ExportLink($a->original_name, route('attachments.show', $a->id)))->all();

        return new DetailBlock("{$i->issue_no} {$i->title}", $fields, [
            '內容' => HtmlToText::lines($i->content_html),
            '補充說明' => $this->trimBlank($entries($i->addenda)),
            '內部留言' => $this->trimBlank($entries($i->comments)),
            '關聯客戶單' => $tickets,
            '附件' => $files,
        ], $url);
    }

    /** @param list<string> $lines */
    private function trimBlank(array $lines): array
    {
        while ($lines && end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    private function tw(mixed $dt): string
    {
        return $dt ? CarbonImmutable::instance($dt)->setTimezone(self::TZ)->format('Y-m-d H:i') : '';
    }
}

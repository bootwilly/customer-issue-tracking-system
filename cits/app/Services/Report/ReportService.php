<?php

namespace App\Services\Report;

use App\Models\User;
use App\Repositories\ReportRepository;
use App\Services\WorkflowService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ReportService
{
    private const TZ = 'Asia/Taipei';

    private const DEFAULT_DAYS = 90;

    private const TREND_MONTHS = 6;

    private const TOP_REWORK = 10;

    public function __construct(private ReportRepository $repo, private WorkflowService $wf) {}

    public function canView(User $u, int $pid): bool
    {
        return $u->seesInternal() && $this->wf->can($u, 'issue.view', $pid);
    }

    /** @return array{0: Carbon, 1: Carbon} Taipei start-of-day dates */
    public function period(?string $from, ?string $to): array
    {
        $end = $this->date($to) ?? Carbon::now(self::TZ)->startOfDay();
        $start = $this->date($from) ?? $end->copy()->subDays(self::DEFAULT_DAYS);
        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        return [$start, $end];
    }

    public function build(int $pid, ?string $from, ?string $to): array
    {
        [$start, $end] = $this->period($from, $to);
        $fromUtc = $start->copy()->startOfDay()->utc();
        $toUtc = $end->copy()->endOfDay()->utc();
        $today = Carbon::now(self::TZ)->toDateString();

        $tMap = $this->wf->statusMap('ticket');
        $iMap = $this->wf->statusMap('issue');
        $openIssue = $iMap->where('is_terminal', false)->keys()->all();
        $releasedCodes = $iMap->where('is_released', true)->keys()->all();

        $trendStart = $end->copy()->startOfMonth()->subMonths(self::TREND_MONTHS - 1);
        $since = $trendStart->copy()->utc();
        if ($fromUtc->lessThan($since)) {
            $since = $fromUtc->copy();
        }

        // 1. tickets
        $tCounts = $this->repo->ticketStatusCounts($pid);
        $tickets = $this->repo->ticketDates($pid, $since);
        $inPeriod = fn ($v) => $v && $this->utc($v)->betweenIncluded($fromUtc, $toUtc);
        $opened = $tickets->filter(fn ($t) => $inPeriod($t->created_at));
        $closed = $tickets->filter(fn ($t) => $inPeriod($t->closed_at));
        $ticketSection = [
            'byStatus' => $this->rows($tMap, $tCounts, 'ticket'),
            'total' => array_sum($tCounts),
            'opened' => $opened->count(),
            'closed' => $closed->count(),
            'avgAccept' => $this->avgDays($opened->filter(fn ($t) => $t->accepted_at), 'created_at', 'accepted_at'),
            'avgClose' => $this->avgDays($closed, 'created_at', 'closed_at'),
            'needsAction' => $this->repo->needsPmActionCount($pid),
        ];

        // 2. issues (created in the period)
        $by = fn (string $col) => $this->repo->issueCountsBy($pid, $col, $fromUtc, $toUtc);
        $stageCounts = $by('stage');
        $stages = [];
        foreach ($iMap->pluck('stage')->filter()->unique() as $st) {
            $stages[$st] = ['code' => $st, 'label' => $st, 'count' => $stageCounts[$st] ?? 0];
        }
        foreach ($stageCounts as $st => $n) {
            $stages[$st] ??= ['code' => $st, 'label' => $st, 'count' => $n];
        }
        $originCounts = $by('origin');
        $sevCounts = $by('severity');
        ksort($sevCounts);
        $issueSection = [
            'byStatus' => $this->rows($iMap, $by('status'), 'issue'),
            'byStage' => array_values($stages),
            'byOrigin' => collect($originCounts)->map(fn ($n, $k) => ['code' => $k, 'label' => $this->wf->lookupLabel('issue_origin', (string) $k), 'count' => $n])->values()->all(),
            'bySeverity' => collect($sevCounts)->map(fn ($n, $k) => ['code' => $k, 'label' => $k === '' ? '未設定' : (string) $k, 'count' => $n])->values()->all(),
            'total' => array_sum($originCounts),
        ];

        // 3. overdue
        $overdue = [];
        foreach ($this->repo->overdueIssues($pid, $openIssue, $today) as $i) {
            $name = $i->dev_name ?: '未指派';
            $due = substr((string) $i->current_due, 0, 10);
            $overdue[$name]['name'] = $name;
            $overdue[$name]['issues'][] = [
                'id' => $i->id, 'no' => $i->issue_no, 'title' => $i->title, 'status' => $i->status, 'due' => $due,
                'days' => (int) Carbon::parse($due, self::TZ)->diffInDays(Carbon::parse($today, self::TZ)),
                'url' => url('/issues/'.$i->id),
            ];
        }
        $overdue = collect($overdue)->map(fn ($g) => $g + ['count' => count($g['issues'])])->sortByDesc('count')->values()->all();

        // 4. rework
        $rework = $this->repo->reworkIssues($pid, $fromUtc, $toUtc);
        $reworkSection = [
            'count' => $rework->count(),
            'avg' => $rework->count() ? round($rework->avg('rework_count'), 1) : 0,
            'top' => $rework->take(self::TOP_REWORK)->map(fn ($i) => [
                'id' => $i->id, 'no' => $i->issue_no, 'title' => $i->title, 'status' => $i->status,
                'rework' => (int) $i->rework_count, 'url' => url('/issues/'.$i->id),
            ])->values()->all(),
        ];

        // 5. trend
        $months = [];
        for ($m = $trendStart->copy(); $m->lessThanOrEqualTo($end); $m->addMonth()) {
            $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'tickets_opened' => 0, 'tickets_closed' => 0, 'issues_created' => 0, 'issues_released' => 0];
        }
        $bump = function ($v, string $field) use (&$months) {
            if ($v) {
                $k = $this->utc($v)->timezone(self::TZ)->format('Y-m');
                if (isset($months[$k])) {
                    $months[$k][$field]++;
                }
            }
        };
        foreach ($tickets as $t) {
            $bump($t->created_at, 'tickets_opened');
            $bump($t->closed_at, 'tickets_closed');
        }
        foreach ($this->repo->issueCreatedDates($pid, $since) as $i) {
            $bump($i->created_at, 'issues_created');
        }
        $seen = [];
        foreach ($this->repo->releaseEvents($pid, $releasedCodes, $since)->sortBy('created_at') as $e) {
            if (isset($seen[$e->subject_id])) {
                continue;
            }
            $seen[$e->subject_id] = true;
            $bump($e->created_at, 'issues_released');
        }

        return [
            'from' => $start->toDateString(), 'to' => $end->toDateString(), 'today' => $today,
            'tickets' => $ticketSection, 'issues' => $issueSection, 'overdue' => $overdue,
            'rework' => $reworkSection, 'trend' => array_values($months),
        ];
    }

    /** Generic section blocks for CSV: [title, headers, rows]. */
    public function csvSections(array $d): array
    {
        $t = $d['tickets'];
        $i = $d['issues'];
        $pair = fn (array $rows) => array_map(fn ($r) => [$r['label'], $r['count']], $rows);
        $overdueRows = [];
        foreach ($d['overdue'] as $g) {
            foreach ($g['issues'] as $x) {
                $overdueRows[] = [$g['name'], $x['no'], $x['title'], $x['due'], $x['days']];
            }
        }

        return [
            ['報表期間', ['起', '迄'], [[$d['from'], $d['to']]]],
            ['客戶問題單：狀態分布（目前全部）', ['狀態', '數量'], $pair($t['byStatus'])],
            ['客戶問題單：期間統計', ['項目', '數值'], [
                ['期間開立', $t['opened']], ['期間結案', $t['closed']],
                ['平均受理天數', $t['avgAccept'] ?? '-'], ['平均結案天數', $t['avgClose'] ?? '-'], ['待PM處置', $t['needsAction']],
            ]],
            ['內部問題：依狀態（期間內建立）', ['狀態', '數量'], $pair($i['byStatus'])],
            ['內部問題：依階段', ['階段', '數量'], $pair($i['byStage'])],
            ['內部問題：依來源', ['來源', '數量'], $pair($i['byOrigin'])],
            ['內部問題：依嚴重度', ['嚴重度', '數量'], $pair($i['bySeverity'])],
            ['逾期問題（依開發人員）', ['開發人員', '問題編號', '標題', '預計完工', '逾期天數'], $overdueRows],
            ['開發退回：摘要', ['項目', '數值'], [['退回問題數', $d['rework']['count']], ['平均退回次數', $d['rework']['avg']]]],
            ['開發退回：Top 10', ['問題編號', '標題', '退回次數'], array_map(fn ($r) => [$r['no'], $r['title'], $r['rework']], $d['rework']['top'])],
            ['月趨勢', ['月份', '客戶單開立', '客戶單結案', '問題建立', '問題上線'],
                array_map(fn ($m) => [$m['month'], $m['tickets_opened'], $m['tickets_closed'], $m['issues_created'], $m['issues_released']], $d['trend'])],
        ];
    }

    private function rows(Collection $map, array $counts, string $scope): array
    {
        $rows = [];
        foreach ($map as $code => $st) {
            $rows[] = ['code' => $code, 'label' => $st->name, 'count' => $counts[$code] ?? 0];
        }
        foreach ($counts as $code => $n) {
            if (! $map->has($code)) {
                $rows[] = ['code' => $code, 'label' => $this->wf->label($scope, (string) $code), 'count' => $n];
            }
        }

        return $rows;
    }

    private function avgDays(Collection $rows, string $a, string $b): ?float
    {
        if ($rows->isEmpty()) {
            return null;
        }
        $sum = $rows->sum(fn ($r) => $this->utc($r->$a)->diffInSeconds($this->utc($r->$b), false));

        return round($sum / $rows->count() / 86400, 1);
    }

    private function utc($v): Carbon
    {
        return Carbon::parse($v, 'UTC')->utc();
    }

    private function date(?string $s): ?Carbon
    {
        if (! $s || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d', $s, self::TZ)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}

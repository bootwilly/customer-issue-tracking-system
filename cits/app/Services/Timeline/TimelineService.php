<?php

namespace App\Services\Timeline;

use App\Models\User;
use App\Repositories\TimelineRepository;
use App\Services\WorkflowService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TimelineService
{
    /** Sort key => label (keys are whitelisted in TimelineRepository::SORTS). */
    public const SORT_LABELS = [
        'created' => '提交日', 'last' => '最後動作', 'due' => '預計完工',
        'test_date' => '排程測試', 'release_date' => '預計上線',
    ];

    /** Display labels of issue stages (presentation only). */
    private const STAGE_LABELS = ['PM' => 'PM', 'DEV' => '開發', 'TEST' => '測試', 'RELEASE' => '上線'];

    public function __construct(private TimelineRepository $repo, private WorkflowService $wf) {}

    public function canView(User $u, int $pid): bool
    {
        return $u->seesInternal() && $this->wf->can($u, 'issue.view', $pid);
    }

    /** Default sort follows the user's role. */
    public function defaultSort(User $u, int $pid): string
    {
        return match (true) {
            $this->wf->can($u, 'issue.edit_pm', $pid) => 'created',
            $this->wf->can($u, 'dev.manage', $pid) => 'due',
            $this->wf->can($u, 'test.manage', $pid) => 'test_date',
            default => 'created',
        };
    }

    public function index(User $u, int $pid, ?string $sort): array
    {
        $sort = isset(TimelineRepository::SORTS[$sort]) ? $sort : $this->defaultSort($u, $pid);
        $isDue = TimelineRepository::SORTS[$sort][1];
        $col = TimelineRepository::SORTS[$sort][0];
        $warn = $this->repo->warnDays($pid);
        $today = Carbon::now('Asia/Taipei')->startOfDay();

        $issueMap = $this->wf->statusMap('issue');
        $ticketMap = $this->wf->statusMap('ticket');
        $openIssue = $issueMap->where('is_terminal', false)->keys()->all();
        $openTicket = $ticketMap->where('is_terminal', false)->keys()->all();

        $rows = new Collection;
        foreach ($this->repo->issues($pid, $sort, $openIssue) as $i) {
            $st = $issueMap->get($i->status);
            $holder = ($st?->is_terminal ? '已結束' : (self::STAGE_LABELS[$i->stage] ?? $i->stage))
                .($st?->is_terminal ? '' : $this->assignee($i));
            $rows->push($this->row('issue', $i->id, $i->issue_no, $i->title, $i->status, url('/issues/'.$i->id), $holder,
                $i->{$col}, $col, $isDue, (bool) $st?->is_terminal, $today, $warn));
        }
        foreach ($this->repo->tickets($pid, $sort) as $t) {
            $st = $ticketMap->get($t->status);
            $holder = match (true) {
                (bool) $st?->is_terminal => '已結束',
                $t->accepted_at === null => '待PM接收',
                $st?->client_tab === 'pending' => '客戶確認',
                default => '內部處理中',
            };
            $rows->push($this->row('ticket', $t->id, $t->ticket_no, $t->title, $t->status, route('pm.tickets.show', $t->id), $holder,
                $t->{$col}, $col, $isDue, (bool) $st?->is_terminal, $today, $warn));
        }

        $rows = $isDue ? $rows->sortBy('sort_key')->values() : $rows->sortByDesc('sort_key')->values();
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['group']][] = $r;
        }

        return [
            'sort' => $sort, 'sortLabels' => self::SORT_LABELS, 'isDue' => $isDue, 'groups' => $groups, 'warn' => $warn,
            'chips' => [
                '待接收' => $this->repo->pendingAcceptCount($pid, $openTicket),
                '需處置' => $this->repo->needsActionCount($pid),
                '已過預計完工' => $this->repo->overdueIssueCount($pid, $today->toDateString(), $openIssue),
            ],
        ];
    }

    private function assignee($i): string
    {
        $name = match ($i->stage) {
            'DEV' => $i->dev?->name,
            'TEST', 'RELEASE' => $i->tester?->name ?? $i->dev?->name,
            default => null,
        };

        return $name ? '：'.$name : '';
    }

    private function row(string $kind, int $id, string $no, string $title, string $status, string $url, string $holder,
        $date, string $col, bool $isDue, bool $terminal, Carbon $today, int $warn): array
    {
        $day = $date ? Carbon::parse($this->dateString($date, $col), 'Asia/Taipei')->startOfDay() : null;
        $diff = $day ? (int) $today->diffInDays($day, false) : null; // negative = past
        $group = '更早';
        $text = '';
        $red = false;
        if ($day) {
            if ($isDue) {
                $group = $diff < 0 ? '已逾期' : ($diff <= $warn ? '即將到期' : '之後');
                $text = $diff < 0 ? '已過 '.abs($diff).' 天' : '還有 '.$diff.' 天';
                $red = $diff <= $warn;
            } else {
                $group = match (true) {
                    $diff === 0 => '今天', $diff === -1 => '昨天',
                    $day->greaterThanOrEqualTo($today->copy()->startOfWeek()) => '本週',
                    default => '更早',
                };
                if (! $terminal) {
                    $text = '已等待 '.abs($diff).' 天';
                    $red = abs($diff) >= $warn;
                }
            }
        }
        if ($terminal) {
            $red = false;
        }

        return [
            'kind' => $kind, 'id' => $id, 'no' => $no, 'title' => $title, 'status' => $status, 'url' => $url,
            'holder' => $holder, 'date' => $day?->toDateString(), 'text' => $text, 'red' => $red, 'group' => $group,
            'sort_key' => $day?->toDateString() ?? '',
        ];
    }

    private function dateString($date, string $col): string
    {
        // Pure date columns are midnight values; timestamps are stored in UTC.
        return in_array($col, ['created_at', 'updated_at'], true)
            ? $date->copy()->timezone('Asia/Taipei')->toDateString()
            : $date->toDateString();
    }
}

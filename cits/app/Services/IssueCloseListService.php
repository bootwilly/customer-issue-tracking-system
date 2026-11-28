<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\IssueCloseRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class IssueCloseListService
{
    public const PM = 'pm';

    public const DEV = 'dev';

    public const TEST = 'test';

    public function __construct(private IssueCloseRepository $closeTasks, private WorkflowService $wf, private IssueDispatchService $dispatch) {}

    public function columns(string $audience): array
    {
        $dispatch = match ($audience) {
            self::PM => IssueDispatchService::PM_COLUMNS,
            self::TEST => IssueDispatchService::TEST_COLUMNS,
            self::DEV => ['dispatched_at' => IssueDispatchService::DEV_LABEL],
        };

        return ['issue_no' => '單號', 'title' => '標題'] + $dispatch + [
            'round' => '輪次', 'target' => '結案方式', 'requested_at' => '送出結案時間',
            'status' => '收尾狀態', 'progress' => '已完成／總數', 'pending' => '待確認人員',
        ];
    }

    public function filters(array $in): array
    {
        $selected = is_string($in['status'] ?? null) ? $in['status'] : '';
        $status = isset($this->statuses()[$selected]) ? $selected : 'pending';

        return ['tab' => 'close', 'mine' => ! empty($in['mine']), 'status' => $status,
            'q' => is_string($in['q'] ?? null) ? trim($in['q']) : '', 'overdue' => false, 'f' => null];
    }

    public function page(User $user, int $pid, array $filters, string $audience): array
    {
        return [
            'statusOptions' => $this->statuses(),
            'rounds' => $this->rows($user, $pid, $filters, $audience),
            'closeColumns' => $this->columns($audience),
        ];
    }

    public function statuses(): array
    {
        return ['all' => '全部', 'pending' => $this->wf->label('issue', $this->wf->lookupValue('issue_close_state', 'wrapup'))]
            + $this->wf->lookupMapAll('issue_close_task_status');
    }

    /** Same round filters, order and displayed values for HTML and Excel. */
    public function rows(User $u, int $pid, array $fl, string $audience, ?int $limit = null): Collection|LengthAwarePaginator
    {
        $result = $this->closeTasks->rounds($pid, $fl['status'] === 'all' ? null : $fl['status'], $fl['q'],
            $fl['mine'] ? $u->id : null, (bool) $u->is_admin, $fl['mine'] && $this->wf->can($u, 'release.manage', $pid), $limit, $audience === self::PM ? 'close_page' : 'page');
        $rows = $result instanceof LengthAwarePaginator ? $result->getCollection() : $result;
        $dispatches = match ($audience) {
            self::PM => $this->dispatch->pmTimeline($pid, $rows, atClose: true),
            self::TEST => $this->dispatch->test($pid, $rows->pluck('request_event_id')->all(), atClose: true),
            self::DEV => $this->dispatch->devTimes($pid, $rows->pluck('issue_id')->unique()->values()->all()),
        };
        $roles = $this->wf->lookupMapAll('issue_close_role');
        $statuses = $this->statuses();
        $pending = $this->closeTasks->pendingOwners($rows->where('round_status', 'pending')->pluck('issue_id')->all())
            ->groupBy(fn ($t) => $t->issue_id.'-'.$t->round)
            ->map(fn ($tasks) => $tasks->map(fn ($t) => ($roles[$t->role] ?? $t->role).' '.($t->name ?? '上線負責人'))->implode('、'));
        $rows->transform(fn ($r) => [
            'issue_id' => $r->issue_id, 'issue_no' => $r->issue_no, 'title' => $r->title,
            'round' => (int) $r->round,
            'target' => $this->wf->label('issue', $r->target), 'requested_at' => tw($r->requested_at),
            'status' => $statuses[$r->round_status], 'progress' => $r->done_count.'／'.$r->total,
            'pending' => $pending[$r->issue_id.'-'.$r->round] ?? '—',
        ] + match ($audience) {
            self::PM => $dispatches[$r->request_event_id],
            self::TEST => $dispatches[$r->request_event_id] ?? IssueDispatchService::NO_TEST_DISPATCH,
            self::DEV => ['dispatched_at' => $dispatches[$r->issue_id] ?? '—'],
        });

        return $result;
    }
}

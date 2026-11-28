<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\WorkbenchRepository;
use App\Services\Collab\SavedQueryService;

class WorkbenchService
{
    public const TABS = ['todo' => '我的待辦', 'team' => '團隊待處理', 'schedule' => '我的排程', 'watch' => '我的追蹤'];

    public function __construct(private WorkbenchRepository $repo, private WorkflowService $wf, private SavedQueryService $saved) {}

    public function dashboard(User $user, int $project, string $tab, int $page): array
    {
        // The project middleware has selected the permission team for this request.
        $internal = $user->seesInternal();
        $issue = $internal && $user->can('issue.view');
        $pm = $issue && $user->can('issue.edit_pm');
        $ticket = $user->can('ticket.view');
        $can = [
            'meeting' => $user->can('meeting.view'), 'issue' => $issue,
            'dev' => $issue && $user->can('dev.manage'), 'test' => $issue && $user->can('test.manage'),
            'release' => $issue && $user->can('release.manage'), 'approve' => $issue && $user->can('due.approve'),
            'accept' => $pm && $user->can('ticket.accept'), 'settle' => $pm && $user->can('ticket.settle'),
            'seen' => $issue && $user->can('ticket.needinfo'),
            'answer' => $ticket && $user->isClient() && $user->can('ticket.comment'),
            'confirm' => $ticket && $user->actsAsClient() && $user->can('ticket.close'),
            'watch_ticket' => $ticket && ($issue || $user->actsAsClient()),
        ];
        $states = ['open' => $this->wf->ticketScopeCodes(null)];
        $activeTickets = $this->wf->statusMap('ticket')->where('is_terminal', false)->keys();
        foreach (['accept' => 'ticket.accept', 'confirm' => 'ticket.close'] as $action => $permission) {
            $states[$action] = $can[$action] ? $activeTickets->filter(fn ($status) => $this->wf->targetByPermission('ticket', $status, $permission) !== null)->all() : [];
        }
        $states['answer'] = $activeTickets->diff($states['confirm'])->all();
        foreach (['meeting', 'meeting_item'] as $scope) {
            $states[$scope] = $this->wf->statusMap($scope)->where('is_terminal', false)->keys()->all();
        }
        if ($issue) {
            $active = $this->wf->statusMap('issue')->where('is_terminal', false);
            foreach ($this->wf->lookupMap('stage_role') as $role => $stage) {
                $states[$role] = $active->where('stage', $stage)->keys()->all();
            }
            $states['closing'] = $this->wf->lookupValue('issue_close_state', 'wrapup');
        }
        [$counts, $rows] = $this->repo->page($project, $user->id, $internal, $can, $states, $tab, $page);
        $rows->withPath(route('workbench'))->appends(['tab' => $tab]);
        $today = now('Asia/Taipei')->toDateString();
        foreach ($rows as $row) {
            [$row->label, $scope, $row->url, $row->dateLabel] = match ($row->action) {
                'meeting' => ['追蹤會議事項', 'meeting_item', route('meetings.show', $row->parent_id).'#item-'.$row->id, '事項期限'],
                'approve' => ['審核展延', 'issue', route('pm.index').'#due-review-'.$row->id, null],
                'accept' => ['接收客戶單', 'ticket', route('pm.tickets.show', $row->id), null],
                'review' => ['檢視客戶單', 'ticket', route('pm.tickets.show', $row->id), null],
                'answer' => ['補充客戶資料', 'ticket', route('tickets.show', $row->id), null],
                'confirm' => ['確認結案', 'ticket', route('tickets.show', $row->id), null],
                'watch_ticket' => ['客戶單', 'ticket', route($user->actsAsClient() ? 'tickets.show' : 'pm.tickets.show', $row->id), null],
                'watch_issue' => ['內部單', 'issue', route('issues.show', $row->id), null],
                'close' => ['完成結案收尾', 'issue', route('issues.show', $row->id).'#close-tasks', null],
                'dev' => ['處理開發', 'issue', route('issues.show', $row->id), '預計完工'],
                'test' => ['執行測試', 'issue', route('issues.show', $row->id), '排程測試'],
                'release' => ['更新正式環境', 'issue', route('issues.show', $row->id), '預計上線'],
            };
            $row->statusName = $this->wf->label($scope, $row->status);
            $row->dateFlag = match (true) {
                ! $row->work_date => '',
                $row->work_date < $today => '已逾期',
                $row->work_date === $today => '今天',
                default => '',
            };
        }

        $shortcuts = [];
        foreach (['pm' => '問題管理', 'dev' => '開發管理', 'test' => '測試管理', 'client' => '客戶單'] as $scope => $module) {
            foreach ($this->saved->list($user, $project, $scope) as $saved) {
                $shortcuts[] = ['module' => $module, 'name' => $saved->name, 'url' => $this->saved->url($saved)];
            }
        }

        return compact('counts', 'rows', 'tab', 'shortcuts') + ['tabs' => self::TABS];
    }
}

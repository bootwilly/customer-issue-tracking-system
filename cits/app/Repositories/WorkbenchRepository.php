<?php

namespace App\Repositories;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class WorkbenchRepository
{
    public function __construct(private MeetingRepository $meetings) {}

    private function issues(int $project, string $action, string $date = 'NULL'): Builder
    {
        return DB::table('issues as i')->where('i.project_id', $project)->whereNull('i.deleted_at')
            ->selectRaw("? as action, i.id, i.id as parent_id, i.issue_no as reference, i.title, i.status, DATE($date) as work_date, i.updated_at as touched", [$action]);
    }

    private function tickets(int $project, string $action): Builder
    {
        return DB::table('client_tickets as t')->where('t.project_id', $project)
            ->selectRaw('? as action, t.id, t.id as parent_id, t.ticket_no as reference, t.title, t.status, NULL as work_date, t.updated_at as touched', [$action]);
    }

    private function watched(Builder $query, string $type, string $alias, int $user): Builder
    {
        return $query->join($type.'_watchers as w', 'w.'.$type.'_id', '=', $alias.'.id')->where('w.user_id', $user)
            ->selectSub(DB::table('events as e')->selectRaw('MAX(e.created_at)')
                ->whereColumn('e.project_id', $alias.'.project_id')->where('e.subject_type', $type)
                ->whereColumn('e.subject_id', $alias.'.id'), 'event_at');
    }

    private function issueGroups(array &$groups, int $project, int $user, array $can, array $states): void
    {
        foreach (['dev' => 'i.current_due', 'test' => 'i.test_scheduled_at', 'release' => 'i.release_planned_at'] as $role => $date) {
            if (! $can[$role]) {
                continue;
            }
            $rows = $this->issues($project, $role, $date)->whereIn('i.status', $states[$role] ?? [])->whereNull('i.close_target');
            if ($role === 'release') {
                $groups['team'][] = clone $rows;
                $rows->where('i.assignee_test_id', $user);
            } else {
                $rows->where('i.assignee_'.$role.'_id', $user);
                $groups['todo'][] = clone $rows;
            }
            $groups['schedule'][] = $rows->whereNotNull($date);
        }

        $closing = $this->issues($project, 'close')->where('i.status', $states['closing'])->whereNotNull('i.close_target');
        $tasks = DB::table('issue_close_tasks as c')->selectRaw('1')->whereColumn('c.issue_id', 'i.id')->where('c.status', 'pending');
        $groups['todo'][] = (clone $closing)->whereExists((clone $tasks)->where('c.user_id', $user));
        if ($can['release']) {
            $groups['team'][] = $closing->whereExists($tasks->whereNull('c.user_id')->where('c.role', 'release'));
        }
        if ($can['approve']) {
            $groups['team'][] = DB::table('due_change_requests as d')->join('issues as i', 'i.id', '=', 'd.issue_id')
                ->where('i.project_id', $project)->whereNull('i.deleted_at')->where('d.status', 'pending')
                ->selectRaw('? as action, d.id, i.id as parent_id, i.issue_no as reference, i.title, i.status, NULL as work_date, d.updated_at as touched', ['approve']);
        }
        $groups['watch'][] = $this->watched($this->issues($project, 'watch_issue'), 'issue', 'i', $user);
    }

    private function ticketGroups(array &$groups, int $project, int $user, array $can, array $states): void
    {
        if ($can['accept']) {
            $groups['team'][] = $this->tickets($project, 'accept')->whereIn('t.status', $states['accept'])->whereNull('t.accepted_at')
                ->where(fn ($q) => $q->whereNull('t.edit_lock_user_id')->orWhereNull('t.edit_lock_expires_at')->orWhere('t.edit_lock_expires_at', '<=', now()));
        }
        if ($can['settle'] || $can['accept'] || $can['seen']) {
            $groups['todo'][] = $this->tickets($project, 'review')->where('t.accepted_by', $user)->whereIn('t.status', $states['open'])
                ->where(function ($q) use ($can) {
                    if ($can['settle']) {
                        $q->where('t.needs_pm_action', true);
                    }
                    if ($can['accept']) {
                        $q->orWhereExists(DB::table('ticket_revisions')->selectRaw('1')->whereColumn('ticket_id', 't.id')
                            ->where('rev_no', '>', 1)->whereNull('pm_ack_at'));
                    }
                    if ($can['seen']) {
                        $q->orWhereExists(DB::table('ticket_needinfo')->selectRaw('1')->whereColumn('ticket_id', 't.id')
                            ->where('resolution', 'answered')->whereNull('pm_seen_at'));
                    }
                });
        }
        if ($can['answer']) {
            $groups['todo'][] = $this->tickets($project, 'answer')->where('t.created_by', $user)->whereIn('t.status', $states['answer'])
                ->whereExists(DB::table('ticket_needinfo')->selectRaw('1')->whereColumn('ticket_id', 't.id')->whereNull('resolved_at'));
        }
        if ($can['confirm']) {
            $groups['todo'][] = $this->tickets($project, 'confirm')->where('t.created_by', $user)->whereIn('t.status', $states['confirm']);
        }
        if ($can['watch_ticket']) {
            $groups['watch'][] = $this->watched($this->tickets($project, 'watch_ticket'), 'ticket', 't', $user);
        }
    }

    public function page(int $project, int $user, bool $internal, array $can, array $states, string $tab, int $page): array
    {
        $groups = ['todo' => [], 'team' => [], 'schedule' => [], 'watch' => []];
        if ($can['meeting']) {
            $items = $this->meetings->ownedItemsQuery($project, $user, $states['meeting_item'], $states['meeting'], ! $internal)
                ->toBase()->join('meetings as m', 'm.id', '=', 'meeting_items.meeting_id')
                ->selectRaw('? as action, meeting_items.id, m.id as parent_id, m.title as reference, meeting_items.topic as title,
                    meeting_items.status, DATE(meeting_items.due_date) as work_date, meeting_items.updated_at as touched', ['meeting']);
            $groups['todo'][] = clone $items;
            $groups['schedule'][] = $items->whereNotNull('meeting_items.due_date');
        }
        if ($can['issue']) {
            $this->issueGroups($groups, $project, $user, $can, $states);
        }
        $this->ticketGroups($groups, $project, $user, $can, $states);

        $rows = collect();
        $counts = [];
        foreach ($groups as $key => $sources) {
            $union = array_shift($sources);
            if (! $union) {
                $counts[$key] = 0;

                continue;
            }
            foreach ($sources as $source) {
                $union->unionAll($source);
            }
            $query = DB::query()->fromSub($union, 'items');
            $counts[$key] = $query->count();
            if ($key !== $tab || ! $counts[$key]) {
                continue;
            }
            if ($key === 'watch') {
                $query->select('items.*')->selectRaw('CASE WHEN touched IS NULL OR event_at > touched THEN event_at ELSE touched END as activity_at')->orderByDesc('activity_at');
            } else {
                $query->orderByRaw('CASE WHEN work_date IS NULL THEN 1 ELSE 0 END')->orderBy('work_date');
            }
            $rows = $query->orderBy('action')->orderBy('id')->forPage($page, 20)->get();
        }

        return [$counts, new LengthAwarePaginator($rows, $counts[$tab], 20, $page)];
    }
}

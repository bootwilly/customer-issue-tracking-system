<?php

namespace App\Repositories;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only report queries. Timestamps are returned as stored (UTC strings); aggregation happens in the service. */
class ReportRepository
{
    /** @return array<string,int> status => count (all tickets of the project) */
    public function ticketStatusCounts(int $pid): array
    {
        return DB::table('client_tickets')->where('project_id', $pid)
            ->selectRaw('status as k, count(*) as c')->groupBy('status')->pluck('c', 'k')
            ->map(fn ($v) => (int) $v)->all();
    }

    public function needsPmActionCount(int $pid): int
    {
        return DB::table('client_tickets')->where('project_id', $pid)->where('needs_pm_action', true)->count();
    }

    /** Tickets created or closed since $since (UTC). */
    public function ticketDates(int $pid, CarbonInterface $since): Collection
    {
        return DB::table('client_tickets')->where('project_id', $pid)
            ->where(fn ($q) => $q->where('created_at', '>=', $since)->orWhere('closed_at', '>=', $since))
            ->get(['id', 'created_at', 'accepted_at', 'closed_at']);
    }

    /** @return array<string,int> */
    public function issueCountsBy(int $pid, string $column, CarbonInterface $from, CarbonInterface $to): array
    {
        abort_unless(in_array($column, ['status', 'stage', 'origin', 'severity'], true), 500);

        return DB::table('issues')->where('project_id', $pid)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("$column as k, count(*) as c")->groupBy($column)->pluck('c', 'k')
            ->map(fn ($v) => (int) $v)->all();
    }

    /** Issues created since $since (UTC). */
    public function issueCreatedDates(int $pid, CarbonInterface $since): Collection
    {
        return DB::table('issues')->where('project_id', $pid)->where('created_at', '>=', $since)->get(['id', 'created_at']);
    }

    /** Release events (transition into a released status) since $since (UTC). */
    public function releaseEvents(int $pid, array $releasedCodes, CarbonInterface $since): Collection
    {
        if (! $releasedCodes) {
            return new Collection;
        }

        return DB::table('events')->where('project_id', $pid)->where('subject_type', 'issue')
            ->whereIn('to_status', $releasedCodes)->where('created_at', '>=', $since)
            ->get(['subject_id', 'created_at']);
    }

    /** Open issues past their current due date, with assignee names. */
    public function overdueIssues(int $pid, array $openStatuses, string $today): Collection
    {
        return DB::table('issues')->leftJoin('users', 'users.id', '=', 'issues.assignee_dev_id')
            ->where('issues.project_id', $pid)->whereIn('issues.status', $openStatuses)
            ->whereNotNull('issues.current_due')->whereDate('issues.current_due', '<', $today)
            ->orderBy('issues.current_due')->orderBy('issues.id')
            ->get(['issues.id', 'issues.issue_no', 'issues.title', 'issues.status', 'issues.current_due', 'issues.assignee_dev_id', 'users.name as dev_name']);
    }

    /** Issues created in the period that were reworked at least once. */
    public function reworkIssues(int $pid, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return DB::table('issues')->where('project_id', $pid)->where('rework_count', '>', 0)
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('rework_count')->orderBy('id')
            ->get(['id', 'issue_no', 'title', 'status', 'rework_count']);
    }
}

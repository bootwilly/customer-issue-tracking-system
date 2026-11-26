<?php

namespace App\Repositories;

use App\Models\ClientTicket;
use App\Models\Issue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TimelineRepository
{
    /** Sort key whitelist: key => [column, due-type?, tickets have it?] */
    public const SORTS = [
        'created' => ['created_at', false, true],
        'last' => ['updated_at', false, true],
        'due' => ['current_due', true, false],
        'test_date' => ['test_scheduled_at', true, false],
        'release_date' => ['release_planned_at', true, false],
    ];

    public const LIMIT = 300;

    public function warnDays(int $projectId, int $default = 3): int
    {
        $v = DB::table('project_settings')->where(['project_id' => $projectId, 'key' => 'timeline.warn_days'])->value('value');

        return is_numeric($v) ? (int) $v : $default;
    }

    /**
     * @param  string[]  $openStatuses  non-terminal issue status codes (used by due-type sorts)
     * @return Collection<int, Issue>
     */
    public function issues(int $projectId, string $sort, array $openStatuses): Collection
    {
        [$col, $isDue] = self::SORTS[$sort] ?? self::SORTS['created'];

        return Issue::with(['dev:id,name', 'tester:id,name'])
            ->where('project_id', $projectId)
            ->when($isDue, fn ($q) => $q->whereNotNull($col)->whereIn('status', $openStatuses))
            ->orderBy($col, $isDue ? 'asc' : 'desc')->orderByDesc('id')
            ->limit(self::LIMIT)->get();
    }

    /** @return Collection<int, ClientTicket> */
    public function tickets(int $projectId, string $sort): Collection
    {
        [$col, , $hasIt] = self::SORTS[$sort] ?? self::SORTS['created'];
        if (! $hasIt) {
            return new Collection;
        }

        return ClientTicket::where('project_id', $projectId)
            ->orderByDesc($col)->orderByDesc('id')->limit(self::LIMIT)->get();
    }

    public function pendingAcceptCount(int $projectId, array $openStatuses): int
    {
        return ClientTicket::where('project_id', $projectId)->whereNull('accepted_at')->whereIn('status', $openStatuses)->count();
    }

    public function needsActionCount(int $projectId): int
    {
        return ClientTicket::where('project_id', $projectId)->where('needs_pm_action', true)->count();
    }

    public function overdueIssueCount(int $projectId, string $today, array $openStatuses): int
    {
        return Issue::where('project_id', $projectId)->whereIn('status', $openStatuses)
            ->whereNotNull('current_due')->whereDate('current_due', '<', $today)->count();
    }

    /**
     * Issues that carry a due date (for the DEV schedule list).
     *
     * @param  string[]  $openStatuses
     * @return Collection<int, Issue>
     */
    public function scheduledIssues(int $projectId, array $openStatuses, ?int $devId = null): Collection
    {
        return Issue::with(['dev:id,name'])
            ->where('project_id', $projectId)->whereNotNull('current_due')->whereIn('status', $openStatuses)
            ->when($devId, fn ($q, $v) => $q->where('assignee_dev_id', $v))
            ->orderBy('current_due')->orderBy('id')->limit(self::LIMIT)->get();
    }
}

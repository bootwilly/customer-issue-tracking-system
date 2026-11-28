<?php

namespace App\Repositories;

use App\Models\ActivityEvent;
use App\Models\Issue;
use App\Models\OutboxMail;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WorkflowTransition;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EventRepository
{
    /** First PM-to-DEV handoff, keyed by issue ID or the historical close request's event ID. */
    public function firstDevDispatchTimes(int $projectId, array $ids, string $target, bool $atClose = false): Collection
    {
        if (! $ids) {
            return collect();
        }
        $sources = WorkflowTransition::where(['scope' => 'issue', 'to_code' => $target])
            ->get(['from_code', 'permission'])
            ->filter(fn ($t) => in_array('issue.dispatch', explode('|', $t->permission), true))
            ->pluck('from_code');
        $sent = DB::table('events as sent')->where(['sent.project_id' => $projectId, 'sent.subject_type' => 'issue',
            'sent.event_type' => 'status', 'sent.to_status' => $target])->whereIn('sent.from_status', $sources);

        if ($atClose) {
            $this->beforeClose($sent);
            $first = $sent->whereColumn('sent.subject_id', 'a.subject_id')->select('sent.created_at')
                ->orderBy('sent.created_at')->orderBy('sent.id')->limit(1);

            return DB::table('events as a')->where(['a.project_id' => $projectId, 'a.subject_type' => 'issue', 'a.event_type' => 'close_requested'])
                ->whereIntegerInRaw('a.id', $ids)->select('a.id')->selectSub($first, 'dispatched_at')->get()
                ->whereNotNull('dispatched_at')->pluck('dispatched_at', 'id');
        }

        return $sent->whereIntegerInRaw('sent.subject_id', $ids)->groupBy('sent.subject_id')
            ->selectRaw('sent.subject_id, MIN(sent.created_at) AS dispatched_at')->pluck('dispatched_at', 'subject_id');
    }

    private function beforeClose(Builder $sent): void
    {
        // Event IDs disambiguate multiple workflow changes in the same second.
        $sent->where(fn ($q) => $q->whereColumn('sent.created_at', '<', 'a.created_at')->orWhere(fn ($tie) => $tie
            ->whereColumn('sent.created_at', 'a.created_at')->whereColumn('sent.id', '<', 'a.id')));
    }

    /** Keyed by issue ID for current lists, or by close_requested event ID for historical rounds. */
    public function latestTestDispatches(int $projectId, array $ids, array $testStatuses, array $permissions, bool $atClose): Collection
    {
        if (! $ids) {
            return collect();
        }
        $sources = $this->testHandoffPermissions($testStatuses, $permissions);
        if (! $sources) {
            return collect();
        }

        $anchors = DB::table($atClose ? 'events as a' : 'issues as a')
            ->where('a.project_id', $projectId)->whereIntegerInRaw('a.id', $ids)->select('a.id');
        $last = DB::table('events as sent')->select('sent.id')
            ->whereColumn('sent.project_id', 'a.project_id')
            ->whereColumn('sent.subject_id', $atClose ? 'a.subject_id' : 'a.id')
            ->where(['sent.subject_type' => 'issue', 'sent.event_type' => 'status'])
            ->where(function ($pairs) use ($sources) {
                foreach ($sources as $from => $targets) {
                    $pairs->orWhere(fn ($pair) => $pair->where('sent.from_status', $from)->whereIn('sent.to_status', array_keys($targets)));
                }
            })->orderByDesc('sent.created_at')->orderByDesc('sent.id')->limit(1);
        if ($atClose) {
            $anchors->where(['a.subject_type' => 'issue', 'a.event_type' => 'close_requested']);
            $this->beforeClose($last);
        }

        return DB::query()->fromSub($anchors->selectSub($last, 'dispatch_id'), 'matches')
            ->join('events as e', 'e.id', '=', 'matches.dispatch_id')
            ->get(['matches.id', 'e.created_at', 'e.from_status', 'e.to_status'])
            ->mapWithKeys(fn ($e) => [$e->id => ['dispatched_at' => $e->created_at, 'permission' => $sources[$e->from_status][$e->to_status]]]);
    }

    /** Index configured handoffs by their exact from/to pair, including composite workflow permissions. */
    private function testHandoffPermissions(array $testStatuses, array $permissions): array
    {
        $sources = [];
        $transitions = WorkflowTransition::where('scope', 'issue')->whereIn('to_code', $testStatuses)
            ->get(['from_code', 'to_code', 'permission', 'requires_fields']);
        foreach ($transitions as $transition) {
            if (! in_array('assignee_test_id', $transition->requires_fields ?? [], true)) {
                continue;
            }
            foreach ($permissions as $permission) {
                if (in_array($permission, explode('|', $transition->permission), true)) {
                    $sources[$transition->from_code][$transition->to_code] = $permission;
                    break;
                }
            }
        }

        return $sources;
    }

    public function log(int $projectId, string $subjectType, int $subjectId, string $type, ?string $from, ?string $to, int $actorId, array $payload = []): ActivityEvent
    {
        return ActivityEvent::create([
            'project_id' => $projectId, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'event_type' => $type, 'from_status' => $from, 'to_status' => $to,
            'payload' => $payload ?: null, 'actor_id' => $actorId, 'created_at' => now(),
        ]);
    }

    /** Same event by the same actor since a time (used to avoid one log row per autosave). */
    public function recentExists(string $subjectType, int $subjectId, string $type, int $actorId, \DateTimeInterface $since): bool
    {
        return ActivityEvent::where(['subject_type' => $subjectType, 'subject_id' => $subjectId, 'event_type' => $type, 'actor_id' => $actorId])
            ->where('created_at', '>=', $since)->exists();
    }

    /** @return Collection<int, ActivityEvent> */
    public function forSubject(string $subjectType, int $subjectId): Collection
    {
        return ActivityEvent::with('actor:id,name,account')
            ->where(['subject_type' => $subjectType, 'subject_id' => $subjectId])
            ->orderBy('id')->get();
    }

    public function projectAudit(int $projectId, array $trashEvents, bool $includeImports): Paginator
    {
        return ActivityEvent::with('actor:id,name,account')->where('project_id', $projectId)
            ->where(function ($q) use ($trashEvents, $includeImports) {
                $q->whereIn('subject_type', ['member', 'flow', 'meeting'])
                    ->orWhere(fn ($issue) => $issue->where('subject_type', 'issue')->whereIn('event_type', $trashEvents));
                if ($includeImports) {
                    $q->orWhere(fn ($batch) => $batch->where('subject_type', 'import')->where('event_type', 'imported'))
                        ->orWhere(fn ($legacy) => $legacy->where('subject_type', 'issue')->where('event_type', 'imported')->whereNull('payload->batch_id'));
                }
            })
            ->orderByDesc('id')->simplePaginate(50);
    }

    /** Older import events have an issue ID but no issue number snapshot. */
    public function legacyImportNumbers(int $projectId, array $ids): Collection
    {
        if (! $ids) {
            return collect();
        }
        $numbers = Issue::withTrashed()->where('project_id', $projectId)->whereIn('id', $ids)->pluck('issue_no', 'id');
        $missing = array_diff($ids, $numbers->keys()->all());
        if ($missing) {
            $purged = ActivityEvent::where('project_id', $projectId)->where('subject_type', 'issue')->where('event_type', 'issue_purged')
                ->whereIn('subject_id', $missing)->get(['subject_id', 'payload'])->pluck('payload.issue', 'subject_id');
            $numbers = $numbers->union($purged);
        }

        return $numbers;
    }

    /** User ids in the project holding a permission through any role assigned in that project. */
    public function userIdsWithPermission(int $projectId, string $permission): array
    {
        $teamKey = config('permission.column_names.team_foreign_key', 'project_id');

        return DB::table('model_has_roles as mr')
            ->join('role_has_permissions as rp', 'rp.role_id', '=', 'mr.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->join('users as u', 'u.id', '=', 'mr.model_id')
            ->where('mr.model_type', User::class)
            ->where("mr.$teamKey", $projectId)
            ->where('p.name', $permission)
            ->where('u.status', 'active')
            ->distinct()->pluck('u.id')->all();
    }

    public function notify(array $userIds, ?int $projectId, string $title, ?string $url, bool $mailEnabled): void
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        if (! $userIds) {
            return;
        }
        $now = now();
        $recipients = User::whereIn('id', $userIds)->where('status', 'active')
            // people removed from the project (old assignees, watchers) no longer get its notifications
            ->when($projectId !== null, fn ($q) => $q->where(fn ($w) => $w->where('is_admin', true)
                ->orWhereIn('id', DB::table('project_members')->where('project_id', $projectId)->select('user_id'))));
        foreach ($recipients->get(['id', 'email', 'email_notify']) as $u) {
            UserNotification::create(['user_id' => $u->id, 'project_id' => $projectId, 'title' => $title, 'url' => $url, 'created_at' => $now]);
            if (! $u->email_notify) {
                continue;
            }
            OutboxMail::create([
                'user_id' => $u->id, 'to_email' => $u->email, 'subject' => $title,
                'body_html' => e($title).($url ? '<br><a href="'.e(url($url)).'">'.e(url($url)).'</a>' : ''),
                'status' => $mailEnabled ? 'pending' : 'skipped',
            ]);
        }
    }
}

<?php

namespace App\Repositories;

use App\Models\ActivityEvent;
use App\Models\OutboxMail;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WorkflowTransition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EventRepository
{
    /** First internal status change through a dispatch transition, keyed by issue ID. */
    public function firstDevDispatchTimes(int $projectId, array $issueIds, string $target): Collection
    {
        if (! $issueIds) {
            return collect();
        }
        $sources = WorkflowTransition::where(['scope' => 'issue', 'to_code' => $target])
            ->get(['from_code', 'permission'])
            ->filter(fn ($t) => in_array('issue.dispatch', explode('|', $t->permission), true))
            ->pluck('from_code');

        return DB::table('events')
            ->where(['project_id' => $projectId, 'subject_type' => 'issue', 'event_type' => 'status', 'to_status' => $target])
            ->whereIn('from_status', $sources)->whereIntegerInRaw('subject_id', $issueIds)
            ->groupBy('subject_id')->selectRaw('subject_id, MIN(created_at) AS dispatched_at')
            ->pluck('dispatched_at', 'subject_id');
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

    /**
     * Recent events of a project for the given subject types (project admin audit page).
     *
     * @param  string[]  $subjectTypes
     * @return Collection<int, ActivityEvent>
     */
    /**
     * @param  string[]  $subjectTypes  every event of these subjects
     * @param  array<string, string[]>  $only  subject type => only these event types
     */
    public function forProject(int $projectId, array $subjectTypes, int $limit = 200, array $only = []): Collection
    {
        return ActivityEvent::with('actor:id,name,account')
            ->where('project_id', $projectId)
            ->where(function ($q) use ($subjectTypes, $only) {
                $q->whereIn('subject_type', $subjectTypes);
                foreach ($only as $type => $events) {
                    $q->orWhere(fn ($w) => $w->where('subject_type', $type)->whereIn('event_type', $events));
                }
            })
            ->orderByDesc('id')->limit($limit)->get();
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

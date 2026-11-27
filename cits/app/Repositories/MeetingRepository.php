<?php

namespace App\Repositories;

use App\Models\Issue;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\MeetingComment;
use App\Models\MeetingItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MeetingRepository
{
    private const LIST_COLUMNS = ['id', 'project_id', 'type', 'title', 'start_at', 'end_at', 'location', 'status', 'customer_visible',
        'published_at', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at', 'deleted_by'];

    /**
     * Live meetings of a project.
     *
     * @param  string[]|null  $statuses  null = every status
     * @param  bool  $sharedOnly  only meetings visible to clients
     * @param  bool  $searchContent  keyword also matches record content (internal users)
     * @return Collection<int, Meeting>
     */
    /** @param array{type?:?string,from?:?\DateTimeInterface,to?:?\DateTimeInterface} $f type code, start_at range (UTC, inclusive) */
    public function list(int $projectId, ?array $statuses, bool $sharedOnly, ?string $keyword, bool $searchContent, bool $ascending, int $limit, array $f = []): Collection
    {
        $like = $keyword !== null && $keyword !== '' ? '%'.addcslashes($keyword, '\%_').'%' : null;

        return Meeting::with(['creator:id,name,user_type', 'editor:id,name'])
            ->select(self::LIST_COLUMNS)
            ->where('project_id', $projectId)->whereNull('deleted_at')
            ->when($statuses !== null, fn ($q) => $q->whereIn('status', $statuses ?: ['']))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('start_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('start_at', '<=', $v))
            ->when($sharedOnly, fn ($q) => $q->where('customer_visible', true))
            ->when($like, fn ($q) => $q->where(function ($w) use ($like, $searchContent) {
                $w->where('title', 'like', $like);
                if ($searchContent) {
                    $w->orWhere('notes_html', 'like', $like)
                        ->orWhereIn('id', MeetingItem::select('meeting_id')->where(fn ($i) => $i->where('topic', 'like', $like)
                            ->orWhere('discussion', 'like', $like)->orWhere('conclusion', 'like', $like)));
                }
            }))
            ->orderBy('start_at', $ascending ? 'asc' : 'desc')->orderBy('id', $ascending ? 'asc' : 'desc')
            ->limit($limit)->get();
    }

    /** @return Collection<int, Meeting> soft-deleted meetings of a project, newest deletion first */
    public function trashed(int $projectId): Collection
    {
        return Meeting::with('deleter:id,name')->select(self::LIST_COLUMNS)
            ->where('project_id', $projectId)->whereNotNull('deleted_at')
            ->orderByDesc('deleted_at')->get();
    }

    public function find(int $id): ?Meeting
    {
        return Meeting::with(['creator:id,name,user_type', 'editor:id,name', 'prev:id,title,start_at'])->whereNull('deleted_at')->find($id);
    }

    public function findTrashed(int $id): ?Meeting
    {
        return Meeting::whereNotNull('deleted_at')->find($id);
    }

    /** Row lock (caller runs inside UnitOfWork). */
    public function findForUpdate(int $id): ?Meeting
    {
        return Meeting::whereKey($id)->whereNull('deleted_at')->lockForUpdate()->first();
    }

    public function create(array $data): Meeting
    {
        return Meeting::create($data);
    }

    public function save(Meeting $m, array $data): Meeting
    {
        $m->fill($data)->save();

        return $m;
    }

    /** Bump updated_at / updated_by (item edits change the meeting's "last updated"). */
    public function touch(int $meetingId, int $by): void
    {
        Meeting::whereKey($meetingId)->update(['updated_by' => $by, 'updated_at' => now()]);
    }

    /** Hard delete; attendees, items, comments and reads go by FK cascade. */
    public function purge(Meeting $m): void
    {
        $m->delete();
    }

    // ---- attendees ----

    /** @return Collection<int, MeetingAttendee> */
    /**
     * Export: attendees / items / comments of many meetings at once, grouped by meeting_id.
     *
     * @param  int[]  $ids
     * @return array{attendees: Collection, items: Collection, comments: Collection}
     */
    public function exportParts(array $ids): array
    {
        $ids = $ids ?: [0];

        return [
            'attendees' => MeetingAttendee::with('user:id,name,user_type')->whereIn('meeting_id', $ids)->orderBy('id')->get()->groupBy('meeting_id'),
            'items' => MeetingItem::with(['owner:id,name', 'issue:id,issue_no,title,status'])->whereIn('meeting_id', $ids)
                ->orderBy('sort')->orderBy('id')->get()->groupBy('meeting_id'),
            'comments' => MeetingComment::with('author:id,name,user_type')->whereIn('meeting_id', $ids)->orderBy('id')->get()->groupBy('meeting_id'),
            'notes' => Meeting::whereIn('id', $ids)->get(['id', 'notes_html', 'internal_html'])->keyBy('id'),
        ];
    }

    public function attendees(int $meetingId): Collection
    {
        return MeetingAttendee::with('user:id,name,user_type')->where('meeting_id', $meetingId)->orderBy('id')->get();
    }

    public function isAttendee(int $meetingId, int $userId): bool
    {
        return MeetingAttendee::where(['meeting_id' => $meetingId, 'user_id' => $userId])->exists();
    }

    /**
     * Replace the attendee list.
     *
     * @param  int[]  $userIds
     * @param  string[]  $externalNames
     */
    public function syncAttendees(int $meetingId, array $userIds, array $externalNames): void
    {
        MeetingAttendee::where('meeting_id', $meetingId)->whereNotNull('user_id')->whereNotIn('user_id', $userIds ?: [0])->delete();
        MeetingAttendee::where('meeting_id', $meetingId)->whereNull('user_id')->whereNotIn('external_name', $externalNames ?: [''])->delete();
        $have = MeetingAttendee::where('meeting_id', $meetingId)->get(['user_id', 'external_name']);
        $now = now();
        foreach (array_diff($userIds, $have->pluck('user_id')->filter()->all()) as $uid) {
            MeetingAttendee::create(['meeting_id' => $meetingId, 'user_id' => $uid, 'created_at' => $now]);
        }
        foreach (array_diff($externalNames, $have->pluck('external_name')->filter()->all()) as $name) {
            MeetingAttendee::create(['meeting_id' => $meetingId, 'external_name' => $name, 'created_at' => $now]);
        }
    }

    // ---- items ----

    /** @return Collection<int, MeetingItem> */
    public function items(int $meetingId): Collection
    {
        return MeetingItem::with(['owner:id,name', 'issue:id,issue_no,title,status', 'carriedFrom:id,meeting_id', 'carriedFrom.meeting:id,start_at'])
            ->where('meeting_id', $meetingId)->orderBy('sort')->orderBy('id')->get();
    }

    public function item(int $meetingId, int $itemId): ?MeetingItem
    {
        return MeetingItem::with(['owner:id,name,user_type,is_admin', 'issue:id,issue_no,title,status'])->where(['meeting_id' => $meetingId, 'id' => $itemId])->first();
    }

    public function maxItemSort(int $meetingId): int
    {
        return (int) MeetingItem::where('meeting_id', $meetingId)->max('sort');
    }

    public function createItem(array $data): MeetingItem
    {
        return MeetingItem::create($data);
    }

    public function saveItem(MeetingItem $i, array $data): MeetingItem
    {
        $i->fill($data)->save();

        return $i;
    }

    public function deleteItem(MeetingItem $i): void
    {
        $i->delete();
    }

    /**
     * Items linked to an issue, in live meetings (newest meeting first).
     *
     * @return Collection<int, MeetingItem>
     */
    public function itemsForIssue(int $projectId, int $issueId): Collection
    {
        return $this->itemsForIssues($projectId, [$issueId])->sortByDesc(fn ($i) => $i->meeting?->start_at)->values();
    }

    /**
     * Items linked to any of the issues, in live meetings of the project (oldest meeting first).
     *
     * @param  int[]  $issueIds
     * @return Collection<int, MeetingItem>
     */
    public function itemsForIssues(int $projectId, array $issueIds): Collection
    {
        if (! $issueIds) {
            return collect();
        }

        return MeetingItem::with(['meeting:id,title,start_at,status', 'owner:id,name'])
            ->whereIn('issue_id', $issueIds)
            ->whereIn('meeting_id', Meeting::select('id')->where('project_id', $projectId)->whereNull('deleted_at'))
            ->get()->sortBy(fn ($i) => [$i->meeting?->start_at?->getTimestamp() ?? 0, $i->id])->values();
    }

    /** Another item of the meeting already linked to the issue. */
    public function itemWithIssue(int $meetingId, int $issueId, ?int $exceptId): ?MeetingItem
    {
        return MeetingItem::where(['meeting_id' => $meetingId, 'issue_id' => $issueId])
            ->when($exceptId, fn ($q, $id) => $q->whereKeyNot($id))->first(['id', 'topic', 'sort']);
    }

    /** Row lock on one item (caller runs inside UnitOfWork). */
    public function lockItem(int $meetingId, int $itemId): ?MeetingItem
    {
        return MeetingItem::where(['meeting_id' => $meetingId, 'id' => $itemId])->lockForUpdate()->first();
    }

    /** @return int[] ids of the meeting's items */
    public function itemIds(int $meetingId): array
    {
        return MeetingItem::where('meeting_id', $meetingId)->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Items a user is responsible for, in live meetings of the given statuses.
     *
     * @param  string[]  $itemStatuses
     * @param  string[]  $meetingStatuses
     * @return Collection<int, MeetingItem>
     */
    public function ownedItems(int $projectId, int $userId, array $itemStatuses, array $meetingStatuses, bool $sharedOnly): Collection
    {
        return MeetingItem::with(['meeting:id,title,start_at,status,customer_visible,published_at,created_by', 'meeting.creator:id,user_type', 'issue:id,issue_no'])
            ->where('owner_id', $userId)->whereIn('status', $itemStatuses ?: [''])
            ->whereIn('meeting_id', Meeting::select('id')->where('project_id', $projectId)->whereNull('deleted_at')
                ->whereIn('status', $meetingStatuses ?: [''])->when($sharedOnly, fn ($q) => $q->where('customer_visible', true)))
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')->orderBy('due_date')->orderBy('id')
            ->get();
    }

    // ---- comments ----

    /** @return Collection<int, MeetingComment> oldest first, deleted included (caller decides what to show) */
    public function comments(int $meetingId): Collection
    {
        return MeetingComment::with(['author:id,name,user_type', 'deleter:id,name'])->where('meeting_id', $meetingId)->orderBy('id')->get();
    }

    public function comment(int $meetingId, int $id): ?MeetingComment
    {
        return MeetingComment::where(['meeting_id' => $meetingId, 'id' => $id])->first();
    }

    public function createComment(array $data): MeetingComment
    {
        return MeetingComment::create($data);
    }

    public function saveComment(MeetingComment $c, array $data): MeetingComment
    {
        $c->fill($data)->save();

        return $c;
    }

    /**
     * Comments by others newer than the user's last visit, per meeting.
     *
     * @param  int[]  $meetingIds
     * @return array<int,int> meeting id => count
     */
    public function unreadCounts(array $meetingIds, int $userId, bool $includeInternal): array
    {
        if (! $meetingIds) {
            return [];
        }

        return DB::table('meeting_comments as c')
            ->leftJoin('meeting_reads as r', fn ($j) => $j->on('r.meeting_id', '=', 'c.meeting_id')->where('r.user_id', $userId))
            ->whereIn('c.meeting_id', $meetingIds)->where('c.user_id', '<>', $userId)->whereNull('c.deleted_at')
            ->when(! $includeInternal, fn ($q) => $q->where('c.is_internal', false))
            ->where(fn ($w) => $w->whereNull('r.read_at')->orWhereColumn('c.created_at', '>', 'r.read_at'))
            ->groupBy('c.meeting_id')->selectRaw('c.meeting_id AS mid, COUNT(*) AS n')
            ->pluck('n', 'mid')->map(fn ($n) => (int) $n)->all();
    }

    public function markRead(int $meetingId, int $userId): void
    {
        DB::table('meeting_reads')->updateOrInsert(['meeting_id' => $meetingId, 'user_id' => $userId], ['read_at' => now()]);
    }

    // ---- issues ----

    /** Live issue of a project by its number (case-insensitive). */
    public function issueByNo(int $projectId, string $issueNo): ?Issue
    {
        return Issue::where('project_id', $projectId)->whereRaw('UPPER(issue_no) = ?', [mb_strtoupper($issueNo)])->first(['id', 'project_id', 'issue_no', 'title', 'status']);
    }

    /** @return int[] issue ids already linked to items of a meeting */
    public function linkedIssueIds(int $meetingId): array
    {
        return MeetingItem::where('meeting_id', $meetingId)->whereNotNull('issue_id')->pluck('issue_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /**
     * Issues of a project by number / title, newest activity first.
     *
     * @param  string[]  $statuses
     * @return Collection<int, Issue>
     */
    public function searchIssues(int $projectId, ?string $keyword, array $statuses, int $limit): Collection
    {
        $like = $keyword !== null ? '%'.addcslashes($keyword, '\%_').'%' : null;

        return Issue::with('dev:id,name')->where('project_id', $projectId)->whereIn('status', $statuses ?: [''])
            ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('issue_no', 'like', $like)->orWhere('title', 'like', $like)))
            ->orderByDesc('updated_at')->orderByDesc('id')->limit($limit)
            ->get(['id', 'project_id', 'issue_no', 'title', 'status', 'stage', 'assignee_dev_id', 'updated_at']);
    }

    /**
     * @param  int[]  $ids
     * @return Collection<int, Issue> in the given order
     */
    public function issuesByIds(int $projectId, array $ids): Collection
    {
        $rows = Issue::where('project_id', $projectId)->whereIn('id', $ids ?: [0])->get(['id', 'project_id', 'issue_no', 'title', 'status'])->keyBy('id');

        return collect($ids)->map(fn ($id) => $rows->get($id))->filter()->values();
    }

    public function issue(int $projectId, int $issueId): ?Issue
    {
        return Issue::where('project_id', $projectId)->whereKey($issueId)->first(['id', 'project_id', 'issue_no', 'title', 'status']);
    }
}

<?php

namespace App\Services\Meeting;

use App\Exceptions\BusinessException;
use App\Models\Attachment;
use App\Models\Issue;
use App\Models\Meeting;
use App\Models\MeetingComment;
use App\Models\MeetingItem;
use App\Models\User;
use App\Repositories\EventRepository;
use App\Repositories\MeetingRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\UnitOfWork;
use App\Services\AttachmentService;
use App\Services\Collab\WatchService;
use App\Services\ContentService;
use App\Services\IssueCloseService;
use App\Services\IssueService;
use App\Services\WorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Meeting records (docs/meeting-system.md).
 *  - Internal users see every meeting of the project; clients see meetings with customer_visible (client-created ones always are).
 *  - Content of a shared internal meeting reaches clients only once published (a status flagged is_released was reached);
 *    client-created meetings are never held back. internal_html and internal comments never reach clients.
 *  - Editing: meeting.manage, or meeting.create + (creator or attendee); clients only on client-created meetings.
 *  - Status via workflow scope "meeting" (terminal = no editing), item status via scope "meeting_item".
 */
class MeetingService
{
    private const SCOPE = 'meeting';

    private const ITEM_SCOPE = 'meeting_item';

    private const TZ = 'Asia/Taipei';

    private const COMMENT_MAX = 2000;

    private const TEXT_MAX = 5000;

    private const EXTERNAL_MAX = 30;

    /** attachments.owner_type of discussion item pictures */
    private const IMAGE_OWNER = 'meeting_item';

    public function __construct(
        private MeetingRepository $repo,
        private EventRepository $events,
        private ProjectRepository $projects,
        private ContentService $content,
        private WorkflowService $wf,
        private UnitOfWork $uow,
        private WatchService $watch,
        private AttachmentService $files,
        private IssueCloseService $close,
        private IssueService $issues,
    ) {}

    // ---- permissions ----

    public function canView(User $u, int $pid): bool
    {
        return $this->wf->can($u, 'meeting.view', $pid);
    }

    public function canCreate(User $u, int $pid): bool
    {
        return $this->wf->can($u, 'meeting.create', $pid);
    }

    public function canManage(User $u, int $pid): bool
    {
        return $this->wf->can($u, 'meeting.manage', $pid);
    }

    public function canDelete(User $u, int $pid): bool
    {
        return $this->wf->can($u, 'meeting.delete', $pid);
    }

    /** May open "share with clients" when creating / editing (internal users with meeting.share). */
    public function canShare(User $u, int $pid): bool
    {
        return $u->seesInternal() && $this->wf->can($u, 'meeting.share', $pid);
    }

    public function visibleTo(User $u, Meeting $m): bool
    {
        return $u->seesInternal() || $m->customer_visible;
    }

    /** Discussion details and the record: held back from clients until a shared internal meeting is published. */
    public function contentVisible(User $u, Meeting $m): bool
    {
        return $u->seesInternal() || $m->isClientOrigin() || $m->published_at !== null;
    }

    public function isEditable(Meeting $m): bool
    {
        return ! $this->wf->status(self::SCOPE, $m->status)->is_terminal;
    }

    private function isParticipant(User $u, Meeting $m): bool
    {
        return $m->created_by === $u->id || $this->repo->isAttendee($m->id, $u->id);
    }

    /** Editing rights ignoring the status. */
    private function mayEdit(User $u, Meeting $m): bool
    {
        if ($this->canManage($u, $m->project_id)) {
            return true;
        }
        if (! $this->canCreate($u, $m->project_id) || ! $this->visibleTo($u, $m)) {
            return false;
        }
        if (! $u->seesInternal() && ! $m->isClientOrigin()) {
            return false;
        }

        return $this->isParticipant($u, $m);
    }

    public function canEdit(User $u, Meeting $m): bool
    {
        return $this->isEditable($m) && $this->mayEdit($u, $m);
    }

    /** Cancel / re-open: the creator or a manager. */
    public function canCancel(User $u, Meeting $m): bool
    {
        return $this->canManage($u, $m->project_id) || ($m->created_by === $u->id && $this->canCreate($u, $m->project_id));
    }

    /** Toggle client visibility of an internal meeting. */
    public function canToggleShare(User $u, Meeting $m): bool
    {
        return ! $m->isClientOrigin() && $this->canShare($u, $m->project_id) && $this->isEditable($m) && $this->mayEdit($u, $m);
    }

    public function canComment(User $u, Meeting $m): bool
    {
        return $this->visibleTo($u, $m) && $this->wf->can($u, 'meeting.comment', $m->project_id);
    }

    private function assertEdit(User $u, Meeting $m): void
    {
        if (! $this->mayEdit($u, $m)) {
            throw new BusinessException('沒有權限編輯這場會議。');
        }
        if (! $this->isEditable($m)) {
            throw new BusinessException('會議已「'.$this->statusLabel($m->status).'」，不能編輯。');
        }
    }

    // ---- lookups ----

    /** @return array<string,string> */
    public function types(): array
    {
        return $this->wf->lookupMap('meeting_type');
    }

    public function typeLabel(?string $type): string
    {
        return $this->wf->lookupMapAll('meeting_type')[$type] ?? (string) $type;
    }

    public function statusLabel(string $status): string
    {
        return $this->wf->label(self::SCOPE, $status);
    }

    /** @return array<string,string> item status code => label */
    public function itemStatuses(): array
    {
        return $this->wf->statusMap(self::ITEM_SCOPE)->map(fn ($s) => $s->name)->all();
    }

    /** @return string[] */
    private function statusCodes(callable $filter, string $scope = self::SCOPE): array
    {
        return $this->wf->statusMap($scope)->filter($filter)->keys()->all();
    }

    /** @return string[] not finished yet: neither terminal nor published */
    private function openStatuses(): array
    {
        return $this->statusCodes(fn ($s) => ! $s->is_terminal && ! $s->is_released);
    }

    /** @return string[] item statuses still being followed (carried to the next meeting) */
    private function openItemStatuses(): array
    {
        return $this->statusCodes(fn ($s) => ! $s->is_terminal, self::ITEM_SCOPE);
    }

    /** Project members (active) that can be attendees / owners. */
    public function members(int $pid): Collection
    {
        $p = $this->projects->find($pid);

        return $p ? $this->projects->members($p)->filter(fn (User $m) => $m->isActive())->values() : collect();
    }

    // ---- reading ----

    /** @return array<string,string> list tab => label */
    public function tabs(): array
    {
        return ['open' => '未完成', 'done' => '已完成', 'cancelled' => '取消', 'mine' => '我的待辦', 'all' => '全部'];
    }

    /**
     * Normalized list filters from the query string: type (lookup meeting_type), status (workflow scope meeting),
     * from / to (Y-m-d, Asia/Taipei dates of start_at). Unknown values are dropped.
     *
     * @return array{type:?string,status:?string,from:?string,to:?string}
     */
    public function filters(array $in): array
    {
        $date = function ($v) {
            $v = is_string($v) ? trim($v) : '';
            $d = $v !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;

            return $d && $d->format('Y-m-d') === $v ? $v : null;
        };
        $type = (string) ($in['type'] ?? '');
        $status = (string) ($in['status'] ?? '');
        $f = [
            'type' => array_key_exists($type, $this->wf->lookupMapAll('meeting_type')) ? $type : null,
            'status' => $this->wf->statusMap(self::SCOPE)->has($status) ? $status : null,
            'from' => $date($in['from'] ?? null),
            'to' => $date($in['to'] ?? null),
        ];
        if ($f['from'] && $f['to'] && $f['from'] > $f['to']) {
            [$f['from'], $f['to']] = [$f['to'], $f['from']];
        }

        return $f;
    }

    /** @return array<string,string> status code => label (filter options) */
    public function statuses(): array
    {
        return $this->wf->statusMap(self::SCOPE)->map(fn ($s) => $s->name)->all();
    }

    /** Statuses of a tab narrowed by the status filter; null = no status restriction. */
    private function tabStatuses(string $tab, ?string $status): array
    {
        [$statuses, $asc] = match ($tab) {
            'open' => [$this->openStatuses(), true],
            'done' => [$this->statusCodes(fn ($s) => $s->is_released), false],
            'cancelled' => [$this->statusCodes(fn ($s) => $s->is_terminal), false],
            default => [null, false],
        };
        if ($status !== null) {
            $statuses = $statuses === null || in_array($status, $statuses, true) ? [$status] : [];
        }

        return [$statuses, $asc];
    }

    /** start_at bounds in UTC for the repository. */
    private function range(array $f): array
    {
        return [
            'type' => $f['type'] ?? null,
            'from' => ! empty($f['from']) ? CarbonImmutable::parse($f['from'], self::TZ)->startOfDay()->utc() : null,
            'to' => ! empty($f['to']) ? CarbonImmutable::parse($f['to'], self::TZ)->endOfDay()->utc() : null,
        ];
    }

    /**
     * @param  array{type?:?string,status?:?string,from?:?string,to?:?string}  $f  see filters()
     * @return array{meetings: Collection, unread: array<int,int>, items: Collection}
     */
    public function list(User $u, int $pid, string $tab, ?string $keyword, array $f = []): array
    {
        $keyword = $keyword !== null ? mb_substr(trim($keyword), 0, 100) : null;
        $shared = ! $u->seesInternal();
        if ($tab === 'mine') {
            $items = $this->repo->ownedItems($pid, $u->id, $this->openItemStatuses(), $this->statusCodes(fn ($s) => ! $s->is_terminal), $shared);

            return ['meetings' => collect(), 'unread' => [], 'items' => $items];
        }
        [$statuses, $asc] = $this->tabStatuses($tab, $f['status'] ?? null);
        $rows = $this->repo->list($pid, $statuses, $shared, $keyword ?: null, ! $shared, $asc, 300, $this->range($f));

        return ['meetings' => $rows, 'unread' => $this->repo->unreadCounts($rows->pluck('id')->all(), $u->id, ! $shared), 'items' => collect()];
    }

    /**
     * Meetings for the Excel export (same rules as the list), each with what this user may see:
     * attendees, items (details only when the record is visible, issue links internal only), comments (internal ones
     * internal only, deleted ones as placeholders), notes and internal notes.
     *
     * @return list<array{meeting: Meeting, attendees: Collection, items: Collection, comments: Collection, notes: ?string, internal: ?string, content: bool}>
     */
    public function exportRows(User $u, int $pid, string $tab, ?string $keyword, array $f, int $limit): array
    {
        $keyword = $keyword !== null ? mb_substr(trim($keyword), 0, 100) : null;
        $shared = ! $u->seesInternal();
        [$statuses, $asc] = $this->tabStatuses($tab, $f['status'] ?? null);

        return $this->exportParts($u, $this->repo->list($pid, $statuses, $shared, $keyword ?: null, ! $shared, $asc, $limit, $this->range($f)));
    }

    /** One meeting for the single export (visibility checked by find()). */
    public function exportOne(User $u, int $pid, int $id): array
    {
        return $this->exportParts($u, collect([$this->find($u, $pid, $id)]))[0];
    }

    private function exportParts(User $u, Collection $meetings): array
    {
        $parts = $this->repo->exportParts($meetings->pluck('id')->all());
        $internal = $u->seesInternal();
        $out = [];
        foreach ($meetings as $m) {
            $full = $this->contentVisible($u, $m);
            $items = $parts['items']->get($m->id, collect())->each(function (MeetingItem $i) use ($internal, $full) {
                if (! $internal) {
                    $i->setRelation('issue', null);
                }
                if (! $full) {
                    // same as the page before the notes are published: topic only
                    $i->discussion = null;
                    $i->conclusion = null;
                    $i->owner_id = null;
                    $i->setRelation('owner', null);
                    $i->due_date = null;
                }
            });
            $out[] = [
                'meeting' => $m,
                'attendees' => $parts['attendees']->get($m->id, collect()),
                'items' => $items,
                'comments' => $parts['comments']->get($m->id, collect())->filter(fn (MeetingComment $c) => $internal || ! $c->is_internal)->values(),
                'notes' => $full ? $parts['notes']->get($m->id)?->notes_html : null,
                'internal' => $internal ? $parts['notes']->get($m->id)?->internal_html : null,
                'content' => $full,
            ];
        }

        return $out;
    }

    /** Live meeting of the current project the user may open. */
    public function find(User $u, int $pid, int $id): Meeting
    {
        $m = $this->repo->find($id);
        if (! $m || $m->project_id !== $pid || ! $this->visibleTo($u, $m)) {
            throw new BusinessException('找不到這場會議。');
        }

        return $m;
    }

    public function attendees(Meeting $m): Collection
    {
        return $this->repo->attendees($m->id);
    }

    /** Items as the user may see them: details hidden until published, issue links internal only. */
    public function items(User $u, Meeting $m): Collection
    {
        $full = $this->contentVisible($u, $m);

        return $this->repo->items($m->id)->each(function (MeetingItem $i) use ($u, $full) {
            if (! $u->seesInternal()) {
                $i->setRelation('issue', null);
            }
            if (! $full) {
                $i->discussion = null;
                $i->conclusion = null;
            }
        });
    }

    /**
     * Comment tree the user may see: top level oldest first, each with ->replies.
     * Deleted ones stay as placeholders; their text only for meeting.delete holders.
     */
    public function comments(User $u, Meeting $m): Collection
    {
        $seeDeleted = $this->canDelete($u, $m->project_id);
        $rows = $this->repo->comments($m->id)
            ->filter(fn (MeetingComment $c) => $u->seesInternal() || ! $c->is_internal)
            ->each(function (MeetingComment $c) use ($seeDeleted) {
                $c->can_see_deleted = $seeDeleted;
            });
        $replies = $rows->whereNotNull('parent_id')->groupBy('parent_id');

        return $rows->whereNull('parent_id')->values()->each(fn ($c) => $c->setRelation('replies', $replies->get($c->id, collect())->values()));
    }

    public function markRead(User $u, Meeting $m): void
    {
        $this->repo->markRead($m->id, $u->id);
    }

    /** Status buttons the user may use on this meeting. */
    public function statusTargets(User $u, Meeting $m): Collection
    {
        return $this->wf->allowedTargets(self::SCOPE, $m->status, $u, $m->project_id)
            ->filter(fn ($t) => $this->isCancelMove($m->status, $t['code']) ? $this->canCancel($u, $m) : $this->canEdit($u, $m))
            ->values();
    }

    private function isCancelMove(string $from, string $to): bool
    {
        return $this->wf->status(self::SCOPE, $from)->is_terminal || $this->wf->status(self::SCOPE, $to)->is_terminal;
    }

    /** Meetings still open that the user may add an issue to (issue page "排入會議"). */
    public function schedulableFor(User $u, int $pid): Collection
    {
        if (! $u->seesInternal()) {
            return collect();
        }

        return $this->repo->list($pid, $this->openStatuses(), false, null, false, true, 50)
            ->filter(fn (Meeting $m) => $this->canEdit($u, $m))->values();
    }

    /** Discussion items linked to an issue (issue page, internal users). */
    public function itemsForIssue(User $u, int $pid, int $issueId): Collection
    {
        return ! $u->seesInternal() || ! $this->canView($u, $pid) ? collect() : $this->repo->itemsForIssue($pid, $issueId);
    }

    /**
     * Issue page "會議紀錄": the issue's items grouped into discussion threads. A thread follows carried_from_id
     * (排下次會議 copies an open item); an item carried from one not linked to this issue starts its own thread.
     * Threads newest activity first, items inside oldest first.
     *
     * @return Collection<int, array{items: Collection<int, MeetingItem>, latest: MeetingItem, conclusion: ?string}>
     */
    public function issueThreads(User $u, int $pid, int $issueId): Collection
    {
        if (! $u->seesInternal() || ! $this->canView($u, $pid)) {
            return collect();
        }
        $items = $this->repo->itemsForIssues($pid, [$issueId]);
        $byId = $items->keyBy('id');
        $root = function (MeetingItem $i) use ($byId): int {
            $seen = [];
            while ($i->carried_from_id && $byId->has($i->carried_from_id) && ! isset($seen[$i->id])) {
                $seen[$i->id] = true;
                $i = $byId->get($i->carried_from_id);
            }

            return $i->id;
        };

        return $items->groupBy($root)->map(fn (Collection $g) => [
            'items' => $g->values(),
            'latest' => $g->last(),
            'conclusion' => $g->reverse()->first(fn ($i) => (string) $i->conclusion !== '')?->conclusion,
        ])->sortByDesc(fn ($t) => $t['latest']->meeting?->start_at?->getTimestamp() ?? 0)->values();
    }

    /**
     * Meeting page "歷次討論": for each item linked to an issue, the issue's items in OTHER meetings (oldest first).
     * Internal users only: clients never see issue links nor other meetings through them.
     *
     * @param  Collection<int, MeetingItem>  $items
     * @return array<int, Collection<int, MeetingItem>> item id => other items
     */
    public function itemHistory(User $u, Meeting $m, Collection $items): array
    {
        $issueIds = $items->pluck('issue_id')->filter()->unique()->values()->all();
        if (! $u->seesInternal() || ! $issueIds) {
            return [];
        }
        $all = $this->repo->itemsForIssues($m->project_id, $issueIds)->groupBy('issue_id');
        $out = [];
        foreach ($items as $i) {
            if ($i->issue_id) {
                $out[$i->id] = $all->get($i->issue_id, collect())->reject(fn ($x) => $x->meeting_id === $m->id)->values();
            }
        }

        return $out;
    }

    public function issueStatusLabel(string $status): string
    {
        return $this->wf->label('issue', $status);
    }

    /** Whether the item's linked issue reached a terminal status (closed / won't fix...). */
    /** The item points at an issue that is in the recycle bin (the SoftDeletes scope hides it; a purge sets issue_id null). */
    public function issueTrashed(?MeetingItem $i): bool
    {
        return $i !== null && $i->issue_id !== null && $i->issue === null;
    }

    public function issueClosed(?MeetingItem $i): bool
    {
        return $i?->issue !== null && $this->wf->status('issue', $i->issue->status)->is_terminal;
    }

    // ---- validation helpers ----

    private function cleanText(?string $s, int $max, string $label, bool $required = false): ?string
    {
        $s = trim(str_replace(["\r\n", "\r"], "\n", (string) $s));
        if ($s === '') {
            if ($required) {
                throw new BusinessException("請輸入{$label}。");
            }

            return null;
        }
        if (mb_strlen($s) > $max) {
            throw new BusinessException("{$label}最多 {$max} 個字。");
        }

        return $s;
    }

    private function parseTime(?string $s, string $label, bool $required): ?CarbonImmutable
    {
        if ($s === null || trim($s) === '') {
            if ($required) {
                throw new BusinessException("請輸入{$label}。");
            }

            return null;
        }
        try {
            return CarbonImmutable::parse($s, self::TZ)->utc();
        } catch (\Throwable) {
            throw new BusinessException("{$label}格式不正確。");
        }
    }

    private function assertType(string $type): void
    {
        if (! array_key_exists($type, $this->types())) {
            throw new BusinessException('請選擇會議類型。');
        }
    }

    /** @return int[] valid member ids */
    private function memberIds(int $pid, array $ids): array
    {
        return array_values(array_intersect(array_unique(array_map('intval', $ids)), $this->members($pid)->pluck('id')->all()));
    }

    /** @return string[] one external attendee per line (name / company) */
    private function externalNames(?string $text): array
    {
        $names = collect(preg_split('/\n/', str_replace("\r", '', (string) $text)))
            ->map(fn ($n) => mb_substr(trim(preg_replace('/\s+/u', ' ', $n)), 0, 100))->filter()->unique()->values()->all();
        if (count($names) > self::EXTERNAL_MAX) {
            throw new BusinessException('外部與會者最多 '.self::EXTERNAL_MAX.' 位。');
        }

        return $names;
    }

    /**
     * Header fields shared by create / update.
     *
     * @return array{data: array, users: int[], externals: string[]}
     */
    private function header(int $pid, array $in, bool $startRequired): array
    {
        $type = (string) ($in['type'] ?? '');
        $this->assertType($type);
        $start = $this->parseTime($in['start_at'] ?? null, '會議時間', $startRequired);
        $end = $this->parseTime($in['end_at'] ?? null, '結束時間', false);
        if ($start && $end && $end->lessThan($start)) {
            throw new BusinessException('結束時間不能早於開始時間。');
        }

        return [
            'data' => array_filter([
                'type' => $type,
                'title' => $this->cleanText($in['title'] ?? null, 200, '會議主題', true),
                'start_at' => $start,
                'end_at' => $end,
                'location' => $this->cleanText($in['location'] ?? null, 200, '地點'),
            ], fn ($v, $k) => $v !== null || in_array($k, ['end_at', 'location'], true), ARRAY_FILTER_USE_BOTH),
            'users' => $this->memberIds($pid, (array) ($in['attendee_ids'] ?? [])),
            'externals' => $this->externalNames($in['external_attendees'] ?? null),
        ];
    }

    // ---- writing: meetings ----

    /**
     * New meeting. $in: type, title, start_at, end_at, location, attendee_ids[], external_attendees, topics (one per line),
     * customer_visible, mode ("scheduled" | "now": lookup meeting_initial).
     */
    public function create(User $u, int $pid, array $in): Meeting
    {
        $this->wf->authorize($u, 'meeting.create', $pid);
        $mode = (string) ($in['mode'] ?? 'scheduled');
        $status = $this->wf->initial('meeting_initial', $mode);
        $now = $status !== $this->wf->initial('meeting_initial', 'scheduled');
        if ($now && trim((string) ($in['start_at'] ?? '')) === '') {
            $in['start_at'] = CarbonImmutable::now(self::TZ)->format('Y-m-d H:i');
        }
        $h = $this->header($pid, $in, true);
        $topics = collect(preg_split('/\n/', str_replace("\r", '', (string) ($in['topics'] ?? ''))))
            ->map(fn ($t) => mb_substr(trim($t), 0, 300))->filter()->values();
        $visible = $u->isClient() || ($this->canShare($u, $pid) && ! empty($in['customer_visible']));
        $issues = $this->prefillIssues($u, $pid, (array) ($in['issue_ids'] ?? []));

        return $this->uow->run(function () use ($u, $pid, $h, $status, $visible, $topics, $issues) {
            $m = $this->repo->create($h['data'] + [
                'project_id' => $pid, 'status' => $status, 'customer_visible' => $visible,
                'created_by' => $u->id, 'updated_by' => $u->id,
            ]);
            $users = $this->memberIds($pid, array_merge($h['users'], [$u->id]));
            $this->repo->syncAttendees($m->id, $users, $h['externals']);
            $itemStatus = $this->wf->initial('meeting_item_initial', 'default');
            $sort = 0;
            foreach ($issues as $i) {
                $this->repo->createItem(['meeting_id' => $m->id, 'sort' => $sort += 10, 'topic' => mb_substr($i->issue_no.' '.$i->title, 0, 300),
                    'issue_id' => $i->id, 'status' => $itemStatus, 'created_by' => $u->id, 'updated_by' => $u->id]);
            }
            foreach ($topics as $topic) {
                $this->repo->createItem(['meeting_id' => $m->id, 'sort' => $sort += 10, 'topic' => $topic, 'status' => $itemStatus,
                    'created_by' => $u->id, 'updated_by' => $u->id]);
            }
            $this->markPublished($m, $status, $u);
            $this->events->log($pid, 'meeting', $m->id, 'meeting_created', null, $status, $u->id, ['title' => $m->title]);

            return $m;
        });
    }

    public function update(User $u, int $pid, int $id, array $in): Meeting
    {
        $m = $this->find($u, $pid, $id);
        $this->assertEdit($u, $m);
        $h = $this->header($pid, $in, true);

        return $this->uow->run(function () use ($u, $pid, $m, $h) {
            $this->repo->save($m, $h['data'] + ['updated_by' => $u->id]);
            $this->repo->syncAttendees($m->id, $h['users'], $h['externals']);
            $this->events->log($pid, 'meeting', $m->id, 'meeting_updated', null, null, $u->id, ['title' => $m->title]);

            return $m;
        });
    }

    public function share(User $u, int $pid, int $id, bool $visible): Meeting
    {
        $m = $this->find($u, $pid, $id);
        if (! $this->canToggleShare($u, $m)) {
            throw new BusinessException('沒有權限變更這場會議的開放設定。');
        }
        if ($m->customer_visible === $visible) {
            return $m;
        }
        $this->repo->save($m, ['customer_visible' => $visible, 'updated_by' => $u->id]);
        $this->events->log($pid, 'meeting', $m->id, $visible ? 'meeting_shared' : 'meeting_unshared', null, null, $u->id, ['title' => $m->title]);

        return $m;
    }

    public function changeStatus(User $u, int $pid, int $id, string $to): Meeting
    {
        $m = $this->find($u, $pid, $id);
        $from = $m->status;
        $allowed = $this->isCancelMove($from, $to) ? $this->canCancel($u, $m) : $this->canEdit($u, $m);
        if (! $allowed) {
            throw new BusinessException('沒有權限變更這場會議的狀態。');
        }
        $this->wf->assertTransition(self::SCOPE, $from, $to, $u, $pid);

        return $this->uow->run(function () use ($u, $pid, $m, $from, $to) {
            $this->repo->save($m, ['status' => $to, 'updated_by' => $u->id]);
            $this->markPublished($m, $to, $u);
            $this->events->log($pid, 'meeting', $m->id, 'meeting_status', $from, $to, $u->id, ['title' => $m->title]);

            return $m;
        });
    }

    /** First time a published status is reached the record becomes visible to clients of a shared meeting. */
    private function markPublished(Meeting $m, string $status, User $u): void
    {
        if ($m->published_at === null && $this->wf->status(self::SCOPE, $status)->is_released) {
            $this->repo->save($m, ['published_at' => now()]);
            $this->announceConclusions($m, $u);
        }
    }

    /**
     * Once per meeting (first publish): every linked issue with a conclusion gets a timeline entry and its watchers
     * a notification. Later edits of the conclusion are not announced again.
     */
    private function announceConclusions(Meeting $m, User $u): void
    {
        $items = $this->repo->items($m->id)->filter(fn (MeetingItem $i) => $i->issue_id && trim((string) $i->conclusion) !== '');
        if ($items->isEmpty()) {
            return;
        }
        $issues = $this->repo->issuesByIds($m->project_id, $items->pluck('issue_id')->unique()->values()->all())->keyBy('id');
        foreach ($items as $i) {
            $issue = $issues->get($i->issue_id);
            if (! $issue) {
                continue;
            }
            $this->events->log($m->project_id, 'issue', $issue->id, 'meeting_discussed', null, null, $u->id, [
                'meeting' => $m->title, 'meeting_id' => $m->id, 'item_id' => $i->id, 'conclusion' => mb_substr((string) $i->conclusion, 0, 200),
            ]);
            $this->watch->notifyIssue($issue, "{$issue->issue_no} 在會議「{$m->title}」有結論", $u->id, []);
        }
    }

    /** Record + internal notes (rich text). $baseRev = notes_rev the form was opened with (optimistic lock). */
    public function saveNotes(User $u, int $pid, int $id, ?string $notes, ?string $internal, ?int $baseRev): Meeting
    {
        $m = $this->find($u, $pid, $id);
        $this->assertEdit($u, $m);
        $notes = $this->cleanHtml($notes);
        $internal = ! $u->seesInternal() ? $m->internal_html : $this->cleanHtml($internal);

        return $this->uow->run(function () use ($u, $pid, $m, $notes, $internal, $baseRev) {
            $m = $this->repo->findForUpdate($m->id) ?? throw new BusinessException('找不到這場會議。');
            if ($baseRev !== null && $baseRev !== $m->notes_rev) {
                throw new BusinessException('會議紀錄已被其他人更新，你的修改沒有存入（編輯器草稿仍保留在這台電腦），請重新整理後再修改。');
            }
            if ($notes === $m->notes_html && $internal === $m->internal_html) {
                return $m;
            }
            $this->repo->save($m, ['notes_html' => $notes, 'internal_html' => $internal, 'notes_rev' => $m->notes_rev + 1, 'updated_by' => $u->id]);
            $this->content->bind((string) $notes, 'meeting', $m->id, $this->notesContext($m->id, false));
            $this->content->clearDraft($u->id, $this->notesContext($m->id, false));
            if ($u->seesInternal()) {
                $this->content->bind((string) $internal, 'meeting_internal', $m->id, $this->notesContext($m->id, true));
                $this->content->clearDraft($u->id, $this->notesContext($m->id, true));
            }
            $this->events->log($pid, 'meeting', $m->id, 'meeting_notes', null, null, $u->id, ['title' => $m->title]);

            return $m;
        });
    }

    /** Editor draft / attachment context keys. */
    public function notesContext(int $meetingId, bool $internal): string
    {
        return 'meeting:'.($internal ? 'internal' : 'notes').':'.$meetingId;
    }

    private function cleanHtml(?string $html): ?string
    {
        $html = $this->content->clean((string) $html);

        return $this->content->isBlank($html) ? null : $html;
    }

    /**
     * Schedule the follow-up meeting: same type / title / place / attendees, open items carried over.
     * The new meeting belongs to the user (a client's follow-up is client-created, hence shared).
     */
    public function scheduleNext(User $u, int $pid, int $id, ?string $startAt, bool $skipClosed = false): Meeting
    {
        $this->wf->authorize($u, 'meeting.create', $pid);
        $prev = $this->find($u, $pid, $id);
        $start = $this->parseTime($startAt, '下次會議時間', true);
        $end = $prev->end_at ? $start->addSeconds($prev->start_at->diffInSeconds($prev->end_at)) : null;
        $visible = $u->isClient() || ($prev->customer_visible && $this->canShare($u, $pid));
        $open = $this->openItemStatuses();
        $full = $this->contentVisible($u, $prev);

        return $this->uow->run(function () use ($u, $pid, $prev, $start, $end, $visible, $open, $full, $skipClosed) {
            $status = $this->wf->initial('meeting_initial', 'scheduled');
            $m = $this->repo->create([
                'project_id' => $pid, 'type' => $prev->type, 'title' => $prev->title, 'start_at' => $start, 'end_at' => $end,
                'location' => $prev->location, 'status' => $status, 'customer_visible' => $visible, 'prev_meeting_id' => $prev->id,
                'created_by' => $u->id, 'updated_by' => $u->id,
            ]);
            $att = $this->repo->attendees($prev->id);
            $this->repo->syncAttendees($m->id, $this->memberIds($pid, array_merge($att->pluck('user_id')->filter()->all(), [$u->id])),
                $att->pluck('external_name')->filter()->values()->all());
            $itemStatus = $this->wf->initial('meeting_item_initial', 'default');
            $k = 0;
            // $skipClosed: items whose internal issue is already closed stay behind (internal users only see issue links)
            $carry = $this->repo->items($prev->id)->filter(fn ($i) => in_array($i->status, $open, true)
                && ! ($skipClosed && $u->seesInternal() && $this->issueClosed($i)));
            $images = $full ? $this->files->ownedBy(self::IMAGE_OWNER, $carry->pluck('id')->all()) : collect();
            foreach ($carry as $i) {
                $new = $this->repo->createItem([
                    'meeting_id' => $m->id, 'sort' => (++$k) * 10, 'topic' => $i->topic, 'issue_id' => $i->issue_id,
                    'owner_id' => $full ? $i->owner_id : null, 'due_date' => $full ? $i->due_date : null,
                    'status' => $itemStatus, 'carried_from_id' => $i->id, 'created_by' => $u->id, 'updated_by' => $u->id,
                ]);
                foreach ($images->get($i->id, collect()) as $a) {
                    $this->files->copyBound($a, self::IMAGE_OWNER, $new->id);
                }
            }
            $this->events->log($pid, 'meeting', $m->id, 'meeting_created', null, $status, $u->id, ['title' => $m->title, 'prev_meeting_id' => $prev->id]);

            return $m;
        });
    }

    // ---- writing: items ----

    private function editableMeeting(User $u, int $pid, int $id): Meeting
    {
        $m = $this->find($u, $pid, $id);
        $this->assertEdit($u, $m);

        return $m;
    }

    public function closeOptions(User $u, int $pid, ?MeetingItem $item): array
    {
        if (! $u->seesInternal()) {
            return [];
        }
        $issue = $item?->issue;
        $can = $issue && $this->close->mayRequest($u, $pid) && ! $this->issueClosed($item) && $issue->close_target === null;

        return [
            'can_close_issue' => $can,
            'issue_closing' => $issue?->close_target !== null,
            'needs_client_note' => $can && $issue->tickets_count > 0,
            'close_wrapup' => $can ? $this->close->preview($issue) : '',
        ];
    }

    public function closeIssue(User $u, int $pid, int $id, int $itemId, ?string $clientNote): MeetingItem
    {
        return $this->uow->run(function () use ($u, $pid, $id, $itemId, $clientNote) {
            $this->repo->findForUpdate($id);
            $m = $this->editableMeeting($u, $pid, $id);
            if (! $this->close->mayRequest($u, $pid)) {
                throw new BusinessException('沒有權限執行此操作。');
            }
            $this->repo->lockItem($id, $itemId);
            $item = $this->findItem($m, $itemId);
            if (! $item->issue_id || $this->issueTrashed($item)) {
                throw new BusinessException('請先連結有效的內部單。');
            }
            if (trim((string) $item->conclusion) === '') {
                throw new BusinessException('請先填寫結論再結案');
            }
            $issue = $this->close->request($u, $pid, $item->issue_id, $this->close->meetingTarget(), $item->conclusion, $clientNote, $itemId);
            $this->events->log($pid, 'issue', $issue->id, 'closed_by_meeting', null, null, $u->id,
                ['meeting' => $m->title, 'meeting_id' => $id, 'item_id' => $itemId]);

            $done = $this->wf->allowedTargets(self::ITEM_SCOPE, $item->status, $u, $pid)
                ->first(fn ($t) => $this->wf->status(self::ITEM_SCOPE, $t['code'])->is_terminal);
            if ($done) {
                $this->wf->assertTransition(self::ITEM_SCOPE, $item->status, $done['code'], $u, $pid);
                $this->repo->saveItem($item, ['status' => $done['code'], 'updated_by' => $u->id]);
            }
            $this->itemChanged($u, $m, $item->topic);

            return $this->findItem($m, $itemId);
        });
    }

    private function findItem(Meeting $m, int $itemId): MeetingItem
    {
        return $this->repo->item($m->id, $itemId) ?? throw new BusinessException('找不到這個討論事項。');
    }

    /**
     * Validated item fields from input; only keys present in $in are returned.
     *
     * @return array<string,mixed>
     */
    private function itemFields(User $u, Meeting $m, array $in, ?MeetingItem $item): array
    {
        $d = [];
        if (array_key_exists('topic', $in) || ! $item) {
            $d['topic'] = $this->cleanText($in['topic'] ?? null, 300, '問題點', true);
        }
        foreach (['discussion' => '討論內容', 'conclusion' => '結論'] as $k => $label) {
            if (array_key_exists($k, $in)) {
                $d[$k] = $this->cleanText($in[$k], self::TEXT_MAX, $label);
            }
        }
        if (array_key_exists('owner_id', $in)) {
            $owner = (int) $in['owner_id'];
            if ($owner && ! $this->memberIds($m->project_id, [$owner])) {
                throw new BusinessException('負責人必須是專案成員。');
            }
            $d['owner_id'] = $owner ?: null;
        }
        if (array_key_exists('due_date', $in)) {
            $due = trim((string) $in['due_date']);
            if ($due !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
                throw new BusinessException('期限格式不正確。');
            }
            $d['due_date'] = $due !== '' ? $due : null;
        }
        if (array_key_exists('status', $in) && $item && $in['status'] !== $item->status) {
            $this->wf->assertTransition(self::ITEM_SCOPE, $item->status, (string) $in['status'], $u, $m->project_id);
            $d['status'] = (string) $in['status'];
        }
        if (array_key_exists('issue_no', $in) && $u->seesInternal()
            && ! (trim((string) $in['issue_no']) === '' && $this->issueTrashed($item))) { // empty box = hidden deleted issue, not a clear
            $no = trim((string) $in['issue_no']);
            $d['issue_id'] = $no === '' ? null
                : ($this->repo->issueByNo($m->project_id, $no)?->id ?? throw new BusinessException("找不到問題單「{$no}」。"));
            // one item per issue per meeting (other meetings may discuss it again)
            if ($d['issue_id'] && $d['issue_id'] !== $item?->issue_id && ($dup = $this->repo->itemWithIssue($m->id, $d['issue_id'], $item?->id))) {
                throw new BusinessException("問題單「{$no}」已在本會議的「{$dup->topic}」事項中。");
            }
        }

        return $d;
    }

    public function addItem(User $u, int $pid, int $id, array $in): MeetingItem
    {
        $m = $this->editableMeeting($u, $pid, $id);
        $d = $this->itemFields($u, $m, $in, null);

        return $this->uow->run(function () use ($u, $m, $d) {
            $i = $this->repo->createItem($d + ['meeting_id' => $m->id, 'sort' => $this->repo->maxItemSort($m->id) + 10,
                'status' => $this->wf->initial('meeting_item_initial', 'default'), 'created_by' => $u->id, 'updated_by' => $u->id]);
            $this->itemChanged($u, $m, $i->topic);

            return $this->repo->item($m->id, $i->id);
        });
    }

    public function updateItem(User $u, int $pid, int $id, int $itemId, array $in): MeetingItem
    {
        $m = $this->editableMeeting($u, $pid, $id);
        $i = $this->findItem($m, $itemId);
        $d = $this->itemFields($u, $m, $in, $i);

        return $this->uow->run(function () use ($u, $m, $i, $d) {
            $i->fill($d);
            if ($i->isDirty()) {
                $this->repo->saveItem($i, ['updated_by' => $u->id]);
                $this->itemChanged($u, $m, $i->topic);
            }

            return $this->repo->item($m->id, $i->id);
        });
    }

    public function deleteItem(User $u, int $pid, int $id, int $itemId): void
    {
        $m = $this->editableMeeting($u, $pid, $id);
        $i = $this->findItem($m, $itemId);
        $this->uow->run(function () use ($u, $m, $i) {
            $images = $this->files->ownedBy(self::IMAGE_OWNER, [$i->id])->get($i->id, collect());
            $this->repo->deleteItem($i);
            $this->files->deleteFiles($images);
            $this->itemChanged($u, $m, $i->topic, true);
        });
    }

    /** Swap with the neighbour above (-1) or below (+1); sorts are renumbered 10, 20, 30... */
    public function moveItem(User $u, int $pid, int $id, int $itemId, int $dir): void
    {
        $m = $this->editableMeeting($u, $pid, $id);
        $items = $this->repo->items($m->id)->values();
        $k = $items->search(fn ($x) => $x->id === $itemId);
        if ($k === false) {
            throw new BusinessException('找不到這個討論事項。');
        }
        $j = $k + ($dir < 0 ? -1 : 1);
        if ($j < 0 || $j >= $items->count()) {
            return;
        }
        $order = $items->all();
        [$order[$k], $order[$j]] = [$order[$j], $order[$k]];
        $this->uow->run(function () use ($order, $m, $u) {
            foreach ($order as $n => $x) {
                if ($x->sort !== ($n + 1) * 10) {
                    $this->repo->saveItem($x, ['sort' => ($n + 1) * 10]);
                }
            }
            $this->repo->touch($m->id, $u->id);
        });
    }

    /** Item edits change the meeting's "last updated"; after publishing they are also logged (once per user per 10 minutes). */
    private function itemChanged(User $u, Meeting $m, string $topic, bool $deleted = false): void
    {
        $this->repo->touch($m->id, $u->id);
        if ($m->published_at !== null && ! $this->events->recentExists('meeting', $m->id, 'meeting_items', $u->id, now()->subMinutes(10))) {
            $this->events->log($m->project_id, 'meeting', $m->id, 'meeting_items', null, null, $u->id, ['title' => $m->title, 'topic' => $topic, 'deleted' => $deleted]);
        }
    }

    // ---- item pictures ----

    public function imagesMax(): int
    {
        return max(1, (int) config('meeting.item_images_max', 10));
    }

    /** Upload context of item pictures (POST /uploads/image). */
    public function imageContext(int $meetingId): string
    {
        return 'meeting:item:'.$meetingId;
    }

    /**
     * Pictures per item as the user may see them: like discussion / conclusion, held back until the record is open.
     *
     * @param  Collection<int, MeetingItem>  $items  items of $m
     * @return Collection<int, Collection> item id => attachments
     */
    public function itemImages(User $u, Meeting $m, Collection $items): Collection
    {
        return $this->contentVisible($u, $m) ? $this->files->ownedBy(self::IMAGE_OWNER, $items->pluck('id')->all()) : collect();
    }

    /** Pictures of items of other meetings ("歷次討論", internal users only). @param array<int, Collection> $history */
    public function historyImages(User $u, array $history): Collection
    {
        $ids = collect($history)->flatten(1)->pluck('id')->unique()->values()->all();

        return $u->seesInternal() ? $this->files->ownedBy(self::IMAGE_OWNER, $ids) : collect();
    }

    /** @return Collection<int, Attachment> the item's pictures after the change */
    public function addItemImage(User $u, int $pid, int $id, int $itemId, int $attachmentId): Collection
    {
        $m = $this->editableMeeting($u, $pid, $id);

        return $this->uow->run(function () use ($u, $m, $itemId, $attachmentId) {
            $i = $this->repo->lockItem($m->id, $itemId) ?? throw new BusinessException('找不到這個討論事項。');
            if ($this->files->countOwned(self::IMAGE_OWNER, $i->id) >= $this->imagesMax()) {
                throw new BusinessException('每個討論事項最多 '.$this->imagesMax().' 張圖片。');
            }
            $this->files->bindImage($u, $attachmentId, self::IMAGE_OWNER, $i->id);
            $this->itemChanged($u, $m, $i->topic);

            return $this->files->ownedBy(self::IMAGE_OWNER, [$i->id])->get($i->id, collect());
        });
    }

    /** Annotated version replaces the picture in place (same position). */
    public function replaceItemImage(User $u, int $pid, int $id, int $itemId, int $imageId, int $attachmentId): Collection
    {
        $m = $this->editableMeeting($u, $pid, $id);

        return $this->uow->run(function () use ($u, $m, $itemId, $imageId, $attachmentId) {
            [$i, $old] = $this->ownedImage($m, $itemId, $imageId);
            $this->files->replaceImage($u, $old, $attachmentId);
            $this->itemChanged($u, $m, $i->topic);

            return $this->files->ownedBy(self::IMAGE_OWNER, [$i->id])->get($i->id, collect());
        });
    }

    public function removeItemImage(User $u, int $pid, int $id, int $itemId, int $imageId): Collection
    {
        $m = $this->editableMeeting($u, $pid, $id);

        return $this->uow->run(function () use ($u, $m, $itemId, $imageId) {
            [$i, $old] = $this->ownedImage($m, $itemId, $imageId);
            $this->files->deleteFiles([$old]);
            $this->itemChanged($u, $m, $i->topic);

            return $this->files->ownedBy(self::IMAGE_OWNER, [$i->id])->get($i->id, collect());
        });
    }

    /** @return array{0: MeetingItem, 1: Attachment} */
    private function ownedImage(Meeting $m, int $itemId, int $imageId): array
    {
        $i = $this->repo->lockItem($m->id, $itemId) ?? throw new BusinessException('找不到這個討論事項。');
        $a = $this->files->ownedBy(self::IMAGE_OWNER, [$i->id])->get($i->id, collect())->firstWhere('id', $imageId)
            ?? throw new BusinessException('找不到這張圖片。');

        return [$i, $a];
    }

    // ---- item -> new internal issue ----

    /**
     * Origins the user may create an internal issue as (lookup issue_create_perm: origin code => permission).
     *
     * @return array<string,string> origin code => label
     */
    public function issueOrigins(User $u, int $pid): array
    {
        if (! $u->seesInternal() || ! $this->wf->can($u, 'issue.create', $pid)) {
            return [];
        }
        $out = [];
        foreach ($this->wf->lookupMap('issue_create_perm') as $origin => $perm) {
            if ($this->wf->can($u, $perm, $pid)) {
                $out[$origin] = $this->wf->lookupLabel('issue_origin', $origin);
            }
        }

        return $out;
    }

    /** "轉內部單" on items of this meeting: internal editors who may create some kind of internal issue. */
    public function canConvert(User $u, Meeting $m): bool
    {
        return $u->seesInternal() && $this->canEdit($u, $m) && $this->issueOrigins($u, $m->project_id) !== [];
    }

    /** Editor draft / upload context of the new issue's content. */
    public function issueDraftContext(int $itemId): string
    {
        return 'issue:meeting-item:'.$itemId;
    }

    /** @return array{0: Meeting, 1: MeetingItem} */
    private function convertible(User $u, int $pid, int $id, int $itemId): array
    {
        $m = $this->editableMeeting($u, $pid, $id);
        if (! $this->canConvert($u, $m)) {
            throw new BusinessException('沒有權限建立內部單。');
        }
        $i = $this->findItem($m, $itemId);
        if ($this->issueTrashed($i)) {
            throw new BusinessException('這個討論事項連結的內部單在回收筒，請專案管理員還原或永久刪除後再轉。');
        }
        if ($i->issue_id) {
            throw new BusinessException('這個討論事項已經連結內部單 '.($i->issue?->issue_no ?? '').'。');
        }

        return [$m, $i];
    }

    /**
     * Prefilled form: title = topic, content = source line + discussion + conclusion + the item's pictures
     * (copied as the user's unsaved uploads of the draft context, so the issue owns its own copies).
     *
     * @return array{meeting: Meeting, item: MeetingItem, no: int, title: string, content_html: string, context: string, origins: array<string,string>}
     */
    public function issueDraft(User $u, int $pid, int $id, int $itemId): array
    {
        [$m, $i] = $this->convertible($u, $pid, $id, $itemId);
        $context = $this->issueDraftContext($i->id);
        $no = $this->itemNo($m, $i);
        $text = fn (string $label, ?string $v) => trim((string) $v) === '' ? ''
            : '<p><strong>'.e($label).'</strong><br>'.nl2br(e(trim((string) $v)), false).'</p>';
        $date = CarbonImmutable::instance($m->start_at)->setTimezone(self::TZ)->format('Y-m-d');
        $html = '<p>來源：<a href="'.e(route('meetings.show', $m->id).'#item-'.$i->id).'">會議「'.e($m->title).'」（'.$date.'）第 '.$no.' 項</a></p>'
            .$text('討論內容', $i->discussion).$text('結論', $i->conclusion);
        foreach ($this->files->ownedBy(self::IMAGE_OWNER, [$i->id])->get($i->id, collect()) as $a) {
            $copy = $this->files->copyAsTemp($u, $a, $context);
            $html .= '<p><img src="'.e(route('attachments.show', $copy->id)).'" alt="附圖"></p>';
        }

        return ['meeting' => $m, 'item' => $i, 'no' => $no, 'title' => mb_substr($i->topic, 0, 200), 'content_html' => $html,
            'context' => $context, 'origins' => $this->issueOrigins($u, $pid)];
    }

    /** Position of the item on its meeting (1-based, as shown). */
    private function itemNo(Meeting $m, MeetingItem $i): int
    {
        return (int) $this->repo->items($m->id)->search(fn ($x) => $x->id === $i->id) + 1;
    }

    /**
     * Create the internal issue (IssueService::createInternal does the permission checks of the origin) and link the
     * item to it in the same transaction; the item lock keeps a double submit from creating two issues.
     */
    public function convertItem(User $u, int $pid, int $id, int $itemId, array $in): Issue
    {
        [$m, $i] = $this->convertible($u, $pid, $id, $itemId);
        $origin = (string) ($in['origin'] ?? '');
        if (! array_key_exists($origin, $this->issueOrigins($u, $pid))) {
            throw new BusinessException('請選擇內部單來源。');
        }
        $no = $this->itemNo($m, $i);

        return $this->uow->run(function () use ($u, $pid, $m, $i, $in, $origin, $no) {
            $locked = $this->repo->lockItem($m->id, $i->id) ?? throw new BusinessException('找不到這個討論事項。');
            if ($locked->issue_id) {
                throw new BusinessException('這個討論事項已經連結內部單。');
            }
            $issue = $this->issues->createInternal($u, $pid, $origin, $in, $this->issueDraftContext($i->id));
            $this->repo->saveItem($locked, ['issue_id' => $issue->id, 'updated_by' => $u->id]);
            if ($i->owner?->seesInternal()) {
                $this->watch->autoWatchIssue($issue, [$i->owner_id]); // the item's owner follows the new issue
            }
            $this->events->log($pid, 'issue', $issue->id, 'from_meeting', null, null, $u->id,
                ['meeting' => $m->title, 'meeting_id' => $m->id, 'item_id' => $i->id, 'item' => $no]);
            $this->itemChanged($u, $m, $i->topic);

            return $issue;
        });
    }

    // ---- issues from 問題管理 ----

    /** Internal editors with issue access may pick issues as discussion items. */
    public function canPickIssues(User $u, Meeting $m): bool
    {
        return $u->seesInternal() && $this->canEdit($u, $m) && $this->wf->can($u, 'issue.view', $m->project_id);
    }

    private function pickableMeeting(User $u, int $pid, int $id): Meeting
    {
        $m = $this->editableMeeting($u, $pid, $id);
        if (! $this->canPickIssues($u, $m)) {
            throw new BusinessException('沒有權限加入問題單。');
        }

        return $m;
    }

    /**
     * Issues a new meeting starts with (問題管理 → 排入新會議): internal users with issue access only, this project only.
     *
     * @param  int[]  $issueIds
     */
    public function prefillIssues(User $u, int $pid, array $issueIds): Collection
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $issueIds)))), 0, 100);
        if (! $ids || ! $u->seesInternal() || ! $this->wf->can($u, 'issue.view', $pid)) {
            return collect();
        }

        return $this->repo->issuesByIds($pid, $ids);
    }

    /** @return array<string,string> issue stage filter (workflow_statuses.stage values, status order) */
    public function issueStages(): array
    {
        return $this->wf->statusMap('issue')->pluck('stage')->filter()->unique()->mapWithKeys(fn ($s) => [$s => $s])->all();
    }

    /**
     * Issues of the project for the picker. $openOnly = non-terminal statuses; $stage = one workflow stage.
     *
     * @return array<int, array{id:int, issue_no:string, title:string, status:string, stage:?string, dev:?string, linked:bool}>
     */
    public function searchIssues(User $u, int $pid, int $id, ?string $keyword, bool $openOnly, ?string $stage): array
    {
        $m = $this->pickableMeeting($u, $pid, $id);
        $statuses = $this->wf->statusMap('issue')
            ->filter(fn ($s) => (! $openOnly || ! $s->is_terminal) && ($stage === null || $stage === '' || $s->stage === $stage))
            ->keys()->all();
        $keyword = $keyword !== null ? mb_substr(trim($keyword), 0, 100) : '';
        $linked = $this->repo->linkedIssueIds($m->id);

        return $this->repo->searchIssues($pid, $keyword !== '' ? $keyword : null, $statuses, 100)
            ->map(fn ($i) => [
                'id' => $i->id, 'issue_no' => $i->issue_no, 'title' => $i->title,
                'status' => $this->wf->label('issue', $i->status), 'stage' => $i->stage, 'dev' => $i->dev?->name,
                'linked' => in_array($i->id, $linked, true),
            ])->values()->all();
    }

    /**
     * Add issues as discussion items (topic = number + title); issues already on this meeting are skipped.
     *
     * @param  int[]  $issueIds
     * @return int items added
     */
    public function addIssues(User $u, int $pid, int $id, array $issueIds): int
    {
        $m = $this->pickableMeeting($u, $pid, $id);
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $issueIds)))), 0, 100);
        if (! $ids) {
            throw new BusinessException('請勾選問題單。');
        }
        $linked = $this->repo->linkedIssueIds($m->id);
        $issues = $this->repo->issuesByIds($pid, $ids)->reject(fn ($i) => in_array($i->id, $linked, true));
        if ($issues->isEmpty()) {
            return 0;
        }

        return $this->uow->run(function () use ($u, $m, $issues) {
            $sort = $this->repo->maxItemSort($m->id);
            $status = $this->wf->initial('meeting_item_initial', 'default');
            foreach ($issues as $i) {
                $this->repo->createItem(['meeting_id' => $m->id, 'sort' => $sort += 10, 'topic' => mb_substr($i->issue_no.' '.$i->title, 0, 300),
                    'issue_id' => $i->id, 'status' => $status, 'created_by' => $u->id, 'updated_by' => $u->id]);
            }
            $this->itemChanged($u, $m, $issues->pluck('issue_no')->implode(', '));

            return $issues->count();
        });
    }

    /** Issue page: put one issue on an open meeting's agenda. */
    public function addIssue(User $u, int $pid, int $meetingId, int $issueId): Meeting
    {
        $m = $this->pickableMeeting($u, $pid, $meetingId);
        if ($this->addIssues($u, $pid, $m->id, [$issueId]) === 0) {
            throw new BusinessException($this->repo->issue($pid, $issueId) ? '這張單已在該會議的討論事項中。' : '找不到這張問題單。');
        }

        return $m;
    }

    // ---- comments ----

    public function addComment(User $u, int $pid, int $id, ?string $body, ?int $parentId, bool $internal): MeetingComment
    {
        $m = $this->find($u, $pid, $id);
        if (! $this->canComment($u, $m)) {
            throw new BusinessException('沒有權限留言。');
        }
        $body = $this->cleanText($body, self::COMMENT_MAX, '留言內容', true);
        $internal = $u->seesInternal() && $internal;
        if ($parentId) {
            $parent = $this->repo->comment($m->id, $parentId);
            if (! $parent || $parent->parent_id !== null || $parent->deleted_at !== null || (! $u->seesInternal() && $parent->is_internal)) {
                throw new BusinessException('找不到要回覆的留言。');
            }
            $internal = $internal || $parent->is_internal; // replies to an internal thread stay internal
        }

        return $this->repo->createComment(['meeting_id' => $m->id, 'parent_id' => $parentId ?: null, 'user_id' => $u->id,
            'body' => $body, 'is_internal' => $internal]);
    }

    private function ownComment(User $u, int $pid, int $id, int $commentId): array
    {
        $m = $this->find($u, $pid, $id);
        $c = $this->repo->comment($m->id, $commentId);
        if (! $c || $c->user_id !== $u->id || $c->deleted_at !== null) {
            throw new BusinessException('只能修改自己的留言。');
        }

        return [$m, $c];
    }

    public function editComment(User $u, int $pid, int $id, int $commentId, ?string $body): MeetingComment
    {
        [, $c] = $this->ownComment($u, $pid, $id, $commentId);
        $body = $this->cleanText($body, self::COMMENT_MAX, '留言內容', true);
        if ($body === $c->body) {
            return $c;
        }

        return $this->repo->saveComment($c, ['body' => $body, 'edited_at' => now()]);
    }

    /** Soft delete: shown as "已刪除"; the text stays readable for meeting.delete holders. */
    public function deleteComment(User $u, int $pid, int $id, int $commentId): void
    {
        [$m, $c] = $this->ownComment($u, $pid, $id, $commentId);
        $this->repo->saveComment($c, ['deleted_at' => now(), 'deleted_by' => $u->id]);
        $this->events->log($pid, 'meeting', $m->id, 'meeting_comment_deleted', null, null, $u->id, ['title' => $m->title, 'comment_id' => $c->id]);
    }

    // ---- calendar ----

    /** iCalendar (.ics) of one meeting. */
    public function ics(User $u, int $pid, int $id, string $url, string $host): string
    {
        $m = $this->find($u, $pid, $id);
        $fmt = fn ($dt) => CarbonImmutable::instance($dt)->utc()->format('Ymd\THis\Z');
        $esc = fn (string $s) => str_replace(['\\', "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\\;'], $s);
        $names = $this->attendees($m)->map(fn ($a) => $a->label())->filter()->implode('、');
        $desc = ($names !== '' ? '與會者：'.$names."\n" : '').$url;
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//CITS//Meetings//ZH-TW', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:meeting-'.$m->id.'@'.$host,
            'DTSTAMP:'.$fmt(now()),
            'DTSTART:'.$fmt($m->start_at),
            'DTEND:'.$fmt($m->end_at ?? $m->start_at->copy()->addHour()),
            'SUMMARY:'.$esc($m->title),
            'LOCATION:'.$esc((string) $m->location),
            'DESCRIPTION:'.$esc($desc),
            'URL:'.$url,
            'STATUS:'.($this->wf->status(self::SCOPE, $m->status)->is_terminal ? 'CANCELLED' : 'CONFIRMED'),
            'END:VEVENT', 'END:VCALENDAR',
        ];

        // RFC 5545: CRLF, lines folded at 75 octets
        return implode("\r\n", array_map(fn ($l) => $this->fold($l), $lines))."\r\n";
    }

    private function fold(string $line): string
    {
        $out = '';
        $cur = '';
        foreach (mb_str_split($line) as $ch) {
            if (strlen($cur.$ch) > 74) {
                $out .= $cur."\r\n ";
                $cur = '';
            }
            $cur .= $ch;
        }

        return $out.$cur;
    }

    public function filename(Meeting $m): string
    {
        return preg_replace('~[\\\\/:*?"<>|\s]+~u', '_', $m->title).'_'.CarbonImmutable::instance($m->start_at)->setTimezone(self::TZ)->format('Ymd').'.ics';
    }

    // ---- delete / trash ----

    public function delete(User $u, int $pid, int $id): void
    {
        $this->wf->authorize($u, 'meeting.delete', $pid);
        $m = $this->find($u, $pid, $id);
        $this->repo->save($m, ['deleted_at' => now(), 'deleted_by' => $u->id]);
        $this->events->log($pid, 'meeting', $m->id, 'meeting_deleted', null, null, $u->id, ['title' => $m->title]);
    }

    public function trashed(User $u, int $pid): Collection
    {
        $this->wf->authorize($u, 'project.trash', $pid);

        return $this->repo->trashed($pid);
    }

    private function findTrashed(int $pid, int $id): Meeting
    {
        $m = $this->repo->findTrashed($id);
        if (! $m || $m->project_id !== $pid) {
            throw new BusinessException('回收筒裡找不到這場會議。');
        }

        return $m;
    }

    public function restore(User $u, int $pid, int $id): Meeting
    {
        $this->wf->authorize($u, 'project.trash', $pid);
        $m = $this->findTrashed($pid, $id);
        $this->repo->save($m, ['deleted_at' => null, 'deleted_by' => null]);
        $this->events->log($pid, 'meeting', $m->id, 'meeting_restored', null, null, $u->id, ['title' => $m->title]);

        return $m;
    }

    /** Permanent delete; the caller must type the exact title. */
    public function purge(User $u, int $pid, int $id, string $confirmTitle): void
    {
        $this->wf->authorize($u, 'project.trash', $pid);
        $m = $this->findTrashed($pid, $id);
        if (trim($confirmTitle) !== $m->title) {
            throw new BusinessException('輸入的標題不符，未刪除。');
        }
        $this->uow->run(function () use ($u, $pid, $m) {
            $this->events->log($pid, 'meeting', $m->id, 'meeting_purged', null, null, $u->id, ['title' => $m->title, 'start_at' => (string) $m->start_at]);
            $images = $this->files->ownedBy(self::IMAGE_OWNER, $this->repo->itemIds($m->id))->flatten(1);
            $this->repo->purge($m);
            $this->files->deleteFiles($images);
        });
    }
}

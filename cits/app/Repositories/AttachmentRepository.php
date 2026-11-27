<?php

namespace App\Repositories;

use App\Models\Attachment;
use App\Models\ClientTicket;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Meeting;
use App\Models\MeetingItem;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AttachmentRepository
{
    public function find(int $id): ?Attachment
    {
        return Attachment::query()->find($id);
    }

    public function boundMarkdown(int $meetingId, string $ownerType, array $ids): Collection
    {
        return Attachment::query()->where('state', 'bound')->where('owner_type', $ownerType)
            ->where('owner_id', $meetingId)->where('disk_path', 'like', '%.md')
            ->whereIntegerInRaw('id', $ids)->get()->keyBy('id');
    }

    public function meetingDocuments(int $meetingId, array $owners): Collection
    {
        return Attachment::query()->where('state', 'bound')->where('owner_id', $meetingId)
            ->whereIn('owner_type', $owners)->where('draft_context_key', Attachment::MEETING_DOCUMENTS)
            ->orderBy('id')->get();
    }

    public function bindMeetingDocuments(array $ids, int $userId, int $meetingId, string $owner): void
    {
        Attachment::query()->whereIntegerInRaw('id', $ids)->where('uploaded_by', $userId)
            ->where('draft_context_key', Attachment::MEETING_DOCUMENTS)->where('state', 'temp')
            ->update(['state' => 'bound', 'owner_type' => $owner, 'owner_id' => $meetingId]);
    }

    public function create(array $data): Attachment
    {
        return Attachment::query()->create($data);
    }

    public function setting(string $key): ?string
    {
        return SystemSetting::query()->whereKey($key)->value('value');
    }

    /** 解析 owner 所屬專案 id */
    public function projectIdForOwner(string $ownerType, int $ownerId): ?int
    {
        $id = match ($ownerType) {
            'ticket' => ClientTicket::query()->whereKey($ownerId)->value('project_id'),
            'issue' => Issue::query()->whereKey($ownerId)->value('project_id'),
            'ticket_comment' => ($t = TicketComment::query()->whereKey($ownerId)->value('ticket_id'))
                ? ClientTicket::query()->whereKey($t)->value('project_id') : null,
            'issue_comment' => ($i = IssueComment::query()->whereKey($ownerId)->value('issue_id'))
                ? Issue::query()->whereKey($i)->value('project_id') : null,
            'meeting', 'meeting_internal' => Meeting::query()->whereKey($ownerId)->whereNull('deleted_at')->value('project_id'),
            'meeting_item' => ($m = $this->meetingIdForItem($ownerId))
                ? Meeting::query()->whereKey($m)->whereNull('deleted_at')->value('project_id') : null,
            default => null,
        };

        return $id === null ? null : (int) $id;
    }

    /** Whether a meeting's record is open to the project's clients (shared, and published or client-created). */
    public function meetingOpenToClients(int $meetingId): bool
    {
        return Meeting::query()->whereKey($meetingId)->whereNull('deleted_at')->where('customer_visible', true)
            ->where(fn ($q) => $q->whereNotNull('published_at')
                ->orWhereIn('created_by', User::query()->select('id')->where('user_type', 'client')))
            ->exists();
    }

    public function meetingIdForItem(int $itemId): ?int
    {
        $id = MeetingItem::query()->whereKey($itemId)->value('meeting_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Bound attachments of owners of one type, oldest first.
     *
     * @param  int[]  $ownerIds
     * @return Collection<int, Attachment>
     */
    public function owned(string $ownerType, array $ownerIds): Collection
    {
        return Attachment::query()->where('state', 'bound')->where('owner_type', $ownerType)
            ->whereIntegerInRaw('owner_id', $ownerIds ?: [0])->orderBy('id')->get();
    }

    public function countOwned(string $ownerType, int $ownerId): int
    {
        return Attachment::query()->where('state', 'bound')->where('owner_type', $ownerType)->where('owner_id', $ownerId)->count();
    }

    /** The user's own unsaved picture (temp, image mime). */
    public function tempImage(int $id, int $userId): ?Attachment
    {
        return Attachment::query()->whereKey($id)->where('state', 'temp')->where('uploaded_by', $userId)
            ->where('mime', 'like', 'image/%')->first();
    }

    public function bind(Attachment $a, string $ownerType, int $ownerId): void
    {
        $a->fill(['state' => 'bound', 'owner_type' => $ownerType, 'owner_id' => $ownerId])->save();
    }

    /** Point $old at $new's file (same id, same place in its owner's list); $new's row goes. */
    public function takeFile(Attachment $old, Attachment $new): void
    {
        $old->fill($new->only(['disk_path', 'original_name', 'mime', 'size', 'sha256', 'uploaded_by']))->save();
        $new->delete();
    }

    /** New row for the same stored file (files are shared by sha256, so nothing is copied on disk). */
    public function duplicate(Attachment $a, array $data): Attachment
    {
        return Attachment::query()->create($data + $a->only(['disk_path', 'original_name', 'mime', 'size', 'sha256', 'uploaded_by']));
    }

    public function projectIsActive(int $projectId): bool
    {
        return Project::query()->whereKey($projectId)->where('status', 'active')->exists();
    }

    public function isProjectMember(int $userId, int $projectId): bool
    {
        return DB::table('project_members')
            ->where('user_id', $userId)->where('project_id', $projectId)->exists();
    }

    /** @param list<int> $ids */
    public function bindTemp(array $ids, int $userId, string $ownerType, int $ownerId, string $context): void
    {
        if ($ids === []) {
            return;
        }
        Attachment::query()->whereIn('id', $ids)
            ->where('state', 'temp')->where('uploaded_by', $userId)
            ->where(function ($q) use ($context, $ownerType, $ownerId) {
                $q->where('mime', '!=', 'text/markdown')->orWhere('disk_path', 'not like', '%.md')
                    ->orWhereNull('draft_context_key')->orWhere('draft_context_key', 'not like', 'meeting:%');
                if (in_array($ownerType, ['meeting', 'meeting_internal'], true)) {
                    $expected = 'meeting:'.($ownerType === 'meeting' ? 'notes' : 'internal').':'.$ownerId;
                    if ($context === $expected) {
                        $q->orWhere('draft_context_key', $expected);
                    }
                }
            })
            ->update(['state' => 'bound', 'owner_type' => $ownerType, 'owner_id' => $ownerId, 'updated_at' => now()]);
    }

    /** @return Collection<int, Attachment> */
    public function tempByContext(int $userId, string $context): Collection
    {
        return Attachment::query()->where('state', 'temp')
            ->where('uploaded_by', $userId)->where('draft_context_key', $context)->get();
    }

    /** @return Collection<int, Attachment> */
    public function tempOlderThan(\DateTimeInterface $cutoff): Collection
    {
        return Attachment::query()->where('state', 'temp')->where('created_at', '<', $cutoff)->get();
    }

    public function countByPath(string $diskPath): int
    {
        return Attachment::query()->where('disk_path', $diskPath)->count();
    }

    /** @param list<int> $ids */
    public function deleteByIds(array $ids): void
    {
        if ($ids !== []) {
            Attachment::query()->whereIn('id', $ids)->delete();
        }
    }
}

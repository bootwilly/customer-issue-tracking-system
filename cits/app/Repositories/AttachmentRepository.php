<?php

namespace App\Repositories;

use App\Models\Attachment;
use App\Models\ClientTicket;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Meeting;
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

    public function isProjectMember(int $userId, int $projectId): bool
    {
        return DB::table('project_members')
            ->where('user_id', $userId)->where('project_id', $projectId)->exists();
    }

    /** @param list<int> $ids */
    public function bindTemp(array $ids, int $userId, string $ownerType, int $ownerId): void
    {
        if ($ids === []) {
            return;
        }
        Attachment::query()->whereIn('id', $ids)
            ->where('state', 'temp')->where('uploaded_by', $userId)
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

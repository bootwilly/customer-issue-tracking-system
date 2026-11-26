<?php

namespace App\Repositories;

use App\Models\TicketNeedinfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class NeedinfoRepository
{
    public function open(int $ticketId, bool $lock = false): ?TicketNeedinfo
    {
        return TicketNeedinfo::where('ticket_id', $ticketId)->whereNull('resolved_at')
            ->when($lock, fn ($q) => $q->lockForUpdate())->orderByDesc('id')->first();
    }

    /** @return Collection<int, TicketNeedinfo> newest first */
    public function history(int $ticketId): Collection
    {
        return TicketNeedinfo::with(['requester:id,name', 'resolver:id,name'])->where('ticket_id', $ticketId)->orderByDesc('id')->get();
    }

    public function create(array $data): TicketNeedinfo
    {
        return TicketNeedinfo::create($data);
    }

    public function save(TicketNeedinfo $n, array $data): TicketNeedinfo
    {
        $n->fill($data)->save();

        return $n;
    }

    /** Answered by the client and not yet opened by a PM. */
    public function markSeen(int $ticketId): void
    {
        TicketNeedinfo::where('ticket_id', $ticketId)->whereNotNull('resolved_at')->whereNull('pm_seen_at')
            ->where('resolution', 'answered')->update(['pm_seen_at' => now()]);
    }

    /** @param int[] $ticketIds @return array<int, string> ticket id => waiting|answered */
    public function stateOf(array $ticketIds): array
    {
        if (! $ticketIds) {
            return [];
        }
        $out = [];
        foreach (TicketNeedinfo::whereIn('ticket_id', $ticketIds)->where(fn ($q) => $this->waiting($q)->orWhere(fn ($w) => $this->answered($w)))
            ->get(['ticket_id', 'resolved_at']) as $n) {
            // an open request wins over an older unseen answer
            $out[$n->ticket_id] = $n->resolved_at === null ? 'waiting' : ($out[$n->ticket_id] ?? 'answered');
        }

        return $out;
    }

    /** Ticket ids of the project in a needinfo state (waiting = open request, answered = client replied, PM not seen yet). */
    public function ticketIds(int $projectId, string $state): array
    {
        return TicketNeedinfo::query()
            ->whereHas('ticket', fn ($q) => $q->where('project_id', $projectId))
            ->where(fn ($q) => $state === 'waiting' ? $this->waiting($q) : $this->answered($q))
            ->distinct()->pluck('ticket_id')->all();
    }

    /** @return array{waiting:int, answered:int} */
    public function counts(int $projectId, array $ticketStatuses): array
    {
        $base = fn () => TicketNeedinfo::query()->whereHas('ticket', fn ($q) => $q->where('project_id', $projectId)->whereIn('status', $ticketStatuses));

        return [
            'waiting' => $base()->where(fn ($q) => $this->waiting($q))->distinct()->count('ticket_id'),
            'answered' => $base()->where(fn ($q) => $this->answered($q))->distinct()->count('ticket_id'),
        ];
    }

    /** @return Collection<int, TicketNeedinfo> open requests older than the cut-off that were not reminded yet */
    public function dueForReminder(\DateTimeInterface $before, int $limit = 200): Collection
    {
        return TicketNeedinfo::with('ticket:id,project_id,ticket_no,title,created_by,status')
            ->whereNull('resolved_at')->whereNull('reminded_at')->where('requested_at', '<', $before)
            ->orderBy('id')->limit($limit)->get();
    }

    private function waiting(Builder $q): Builder
    {
        return $q->whereNull('resolved_at');
    }

    private function answered(Builder $q): Builder
    {
        return $q->whereNotNull('resolved_at')->where('resolution', 'answered')->whereNull('pm_seen_at');
    }
}

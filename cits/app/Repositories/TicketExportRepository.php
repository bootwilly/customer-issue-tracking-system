<?php

namespace App\Repositories;

use App\Models\Attachment;
use Illuminate\Support\Collection;

/** Read-only attachment lookup for the ticket exports. */
class TicketExportRepository
{
    /**
     * Bound attachments owned by the given tickets or ticket comments.
     *
     * @param  int[]  $ticketIds
     * @param  int[]  $commentIds
     * @return Collection<int, Attachment>
     */
    public function attachmentsOf(array $ticketIds, array $commentIds): Collection
    {
        if ($ticketIds === [] && $commentIds === []) {
            return collect();
        }

        return Attachment::query()->where('state', 'bound')
            ->where(function ($w) use ($ticketIds, $commentIds) {
                $w->where(fn ($x) => $x->where('owner_type', 'ticket')->whereIn('owner_id', $ticketIds ?: [0]))
                    ->orWhere(fn ($x) => $x->where('owner_type', 'ticket_comment')->whereIn('owner_id', $commentIds ?: [0]));
            })
            ->orderBy('id')->get(['id', 'owner_type', 'owner_id', 'original_name']);
    }
}

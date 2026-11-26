<?php

namespace App\Repositories;

use App\Models\ClientTicket;
use App\Models\TicketRevision;

class RevisionRepository
{
    public function ticketInProject(int $ticketId, int $projectId): ?ClientTicket
    {
        return ClientTicket::with('mesPage')->where(['id' => $ticketId, 'project_id' => $projectId])->first();
    }

    public function revision(int $ticketId, int $revNo): ?TicketRevision
    {
        return TicketRevision::with('author')->where(['ticket_id' => $ticketId, 'rev_no' => $revNo])->first();
    }
}

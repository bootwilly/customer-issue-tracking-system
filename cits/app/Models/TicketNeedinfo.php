<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A PM request for more information on a client ticket (flag, not a status). */
class TicketNeedinfo extends Model
{
    protected $table = 'ticket_needinfo';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'ticket_id' => 'integer',
            'requested_by' => 'integer',
            'resolved_by' => 'integer',
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
            'reminded_at' => 'datetime',
            'pm_seen_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ClientTicket::class, 'ticket_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}

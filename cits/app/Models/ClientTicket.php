<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientTicket extends Model
{
    protected $table = 'client_tickets';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'edit_lock_expires_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'accepted_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'needs_pm_action' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function acceptor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function mesPage(): BelongsTo
    {
        return $this->belongsTo(MesPage::class);
    }

    public function lockUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edit_lock_user_id');
    }

    public function issues(): BelongsToMany
    {
        return $this->belongsToMany(Issue::class, 'ticket_issue_links', 'ticket_id', 'issue_id')
            ->withPivot(['based_on_rev', 'linked_by', 'linked_at']);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(TicketRevision::class, 'ticket_id')->orderBy('rev_no');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class, 'ticket_id')->orderBy('id');
    }

    public function reopens(): HasMany
    {
        return $this->hasMany(TicketReopen::class, 'ticket_id')->orderBy('round');
    }
}

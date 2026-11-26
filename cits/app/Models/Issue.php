<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Issue extends Model
{
    protected $table = 'issues';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'planned_start' => 'date',
            'planned_due' => 'date',
            'current_due' => 'date',
            'actual_done_at' => 'datetime',
            'test_scheduled_at' => 'date',
            'release_planned_at' => 'date',
            'released_at' => 'datetime',
            'content_locked_at' => 'datetime',
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

    public function dev(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_dev_id');
    }

    public function tester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_test_id');
    }

    public function mesPage(): BelongsTo
    {
        return $this->belongsTo(MesPage::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(ClientTicket::class, 'ticket_issue_links', 'issue_id', 'ticket_id')
            ->withPivot(['based_on_rev', 'linked_by', 'linked_at']);
    }

    public function addenda(): HasMany
    {
        return $this->hasMany(IssueAddendum::class)->orderBy('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(IssueComment::class)->orderBy('id');
    }

    public function dueRequests(): HasMany
    {
        return $this->hasMany(DueChangeRequest::class)->orderByDesc('id');
    }
}

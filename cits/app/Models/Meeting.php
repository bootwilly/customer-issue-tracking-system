<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Meeting extends Model
{
    protected $table = 'meetings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'created_by' => 'integer',
            'prev_meeting_id' => 'integer',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'customer_visible' => 'boolean',
            'notes_rev' => 'integer',
            'published_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function prev(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'prev_meeting_id');
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(MeetingAttendee::class)->orderBy('id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MeetingItem::class)->orderBy('sort')->orderBy('id');
    }

    /** Created by a client account: always visible to the project's clients, content never held back. */
    public function isClientOrigin(): bool
    {
        return $this->creator?->isClient() ?? false;
    }
}

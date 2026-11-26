<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingItem extends Model
{
    protected $table = 'meeting_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'meeting_id' => 'integer',
            'sort' => 'integer',
            'issue_id' => 'integer',
            'owner_id' => 'integer',
            'carried_from_id' => 'integer',
            'due_date' => 'date',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function carriedFrom(): BelongsTo
    {
        return $this->belongsTo(MeetingItem::class, 'carried_from_id');
    }
}

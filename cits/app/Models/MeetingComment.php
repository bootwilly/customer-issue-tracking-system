<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingComment extends Model
{
    protected $table = 'meeting_comments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'meeting_id' => 'integer',
            'parent_id' => 'integer',
            'user_id' => 'integer',
            'is_internal' => 'boolean',
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}

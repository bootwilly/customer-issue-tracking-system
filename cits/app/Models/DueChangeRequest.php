<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DueChangeRequest extends Model
{
    protected $table = 'due_change_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'old_due' => 'date',
            'new_due' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}

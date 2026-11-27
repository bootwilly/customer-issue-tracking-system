<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueImportRow extends Model
{
    protected $guarded = ['id'];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }
}

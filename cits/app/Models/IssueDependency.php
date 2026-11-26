<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** issue_id depends on depends_on_id (kind = lookup dependency_kind). */
class IssueDependency extends Model
{
    protected $table = 'issue_dependencies';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'issue_id' => 'integer',
            'depends_on_id' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'issue_id');
    }

    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'depends_on_id');
    }
}

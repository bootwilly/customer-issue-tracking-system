<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectFlow extends Model
{
    protected $table = 'project_flows';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'version' => 'integer',
            'sort' => 'integer',
            'is_active' => 'boolean',
            'draft_rev' => 'integer',
            'draft_updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function draftEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'draft_updated_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function hasDraft(): bool
    {
        return $this->draft_source !== null;
    }

    public function isPublished(): bool
    {
        return $this->version > 0;
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ProjectFlowRevision::class, 'flow_id')->orderByDesc('version');
    }
}

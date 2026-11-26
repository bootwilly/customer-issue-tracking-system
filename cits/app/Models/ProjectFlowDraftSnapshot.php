<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectFlowDraftSnapshot extends Model
{
    protected $table = 'project_flow_draft_snapshots';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['flow_id' => 'integer', 'created_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

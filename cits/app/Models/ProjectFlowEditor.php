<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectFlowEditor extends Model
{
    protected $table = 'project_flow_editors';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['flow_id' => 'integer', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

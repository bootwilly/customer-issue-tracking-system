<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowStatus extends Model
{
    protected $table = 'workflow_statuses';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'is_terminal' => 'boolean',
            'is_excluded' => 'boolean',
            'is_released' => 'boolean',
        ];
    }
}

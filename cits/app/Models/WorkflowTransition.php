<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowTransition extends Model
{
    protected $table = 'workflow_transitions';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'requires_fields' => 'array',
        ];
    }
}

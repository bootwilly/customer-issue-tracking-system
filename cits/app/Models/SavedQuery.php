<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A user's named list filter: whitelisted query-string params of one list page (never SQL). */
class SavedQuery extends Model
{
    protected $table = 'saved_queries';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'project_id' => 'integer',
            'sort' => 'integer',
            'params' => 'array',
        ];
    }
}

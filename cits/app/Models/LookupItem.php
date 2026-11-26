<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LookupItem extends Model
{
    protected $table = 'lookup_items';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}

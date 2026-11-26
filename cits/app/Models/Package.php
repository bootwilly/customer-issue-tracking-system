<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    protected $table = 'packages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'built_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class)->orderBy('id');
    }
}

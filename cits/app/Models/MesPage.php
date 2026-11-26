<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MesPage extends Model
{
    protected $table = 'mes_pages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MesPage::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(MesPage::class, 'parent_id')->orderBy('sort');
    }
}

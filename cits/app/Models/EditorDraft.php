<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EditorDraft extends Model
{
    protected $table = 'editor_drafts';

    protected $guarded = [];

    public $incrementing = false;

    public const CREATED_AT = null;

    protected function casts(): array
    {
        return [
            'updated_at' => 'datetime',
        ];
    }
}

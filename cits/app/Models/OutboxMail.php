<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutboxMail extends Model
{
    protected $table = 'notification_outbox';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }
}

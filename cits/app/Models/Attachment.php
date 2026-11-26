<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    public const MEETING_DOCUMENTS = 'meeting:documents';

    protected $table = 'attachments';

    protected $guarded = ['id'];
}

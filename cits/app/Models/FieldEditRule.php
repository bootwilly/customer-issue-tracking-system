<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FieldEditRule extends Model
{
    protected $table = 'field_edit_rules';

    protected $guarded = ['id'];

    public $timestamps = false;
}

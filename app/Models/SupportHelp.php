<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportHelp extends Model
{
    protected $fillable = [
        'question',
        'answer',
        'created_by',
        'updated_by',
        'is_active',
        'is_deleted',
    ];
}

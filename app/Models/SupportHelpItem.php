<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportHelpItem extends Model
{
    protected $fillable = [
        'question',
        'answer',
        'sort_order',
        'created_by',
        'updated_by',
        'is_active',
        'is_deleted',
    ];
}

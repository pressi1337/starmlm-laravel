<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TermsAndCondition extends Model
{
    protected $fillable = [
        'content',
        'created_by',
        'updated_by',
        'is_active',
        'is_deleted',
    ];
}

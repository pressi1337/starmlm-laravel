<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdditionalScratchReferral extends Model
{
    use HasFactory;

    protected $fillable = [
        'userid',
        'referral_code',
        'is_all_user',
        'is_active',
    ];
}

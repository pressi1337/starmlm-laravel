<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LevelIncomeRule extends Model
{
    public const TRIGGER_TYPE_PROMOTER_ACTIVATION = 1;

    protected $fillable = [
        'promoter_level',
        'referral_depth',
        'amount',
        'trigger_type',
        'wallet_type',
        'created_by',
        'updated_by',
        'is_active',
        'is_deleted',
    ];
}

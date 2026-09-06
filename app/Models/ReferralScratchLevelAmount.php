<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One candidate cashback amount in the pool for a (promoter level, referral
 * level) combination. A scratch card draws one of these at random.
 */
class ReferralScratchLevelAmount extends Model
{
    protected $fillable = [
        'referral_scratch_level_id',
        'amount',
        'msg',
        'order_no',
        'created_by',
        'updated_by',
        'is_active',
        'is_deleted',
    ];

    protected $casts = [
        'amount' => 'float',
        'order_no' => 'integer',
        'is_active' => 'integer',
        'is_deleted' => 'integer',
    ];

    public function level()
    {
        return $this->belongsTo(ReferralScratchLevel::class, 'referral_scratch_level_id');
    }

    /** Live rows only, in authoring order. */
    public function scopeLive($query)
    {
        return $query->where('is_active', 1)->where('is_deleted', 0);
    }
}

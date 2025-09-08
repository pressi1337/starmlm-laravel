<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralScratchRange extends Model
{
    public function level()
    {
        return $this->belongsTo(ReferralScratchLevel::class, 'referral_scratch_level_id');
    }
}

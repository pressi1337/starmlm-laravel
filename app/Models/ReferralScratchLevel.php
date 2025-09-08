<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralScratchLevel extends Model
{
    public function ranges()
    {
        return $this->hasMany(ReferralScratchRange::class, 'referral_scratch_level_id');
    }
}

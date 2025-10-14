<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EarningHistory extends Model
{
    //
    const EARNING_TYPE_SESSION_1_SET_1_VIDEO = 1;
    const EARNING_TYPE_SESSION_1_SET_2_VIDEO = 2;
    const EARNING_TYPE_SESSION_2_SET_1_VIDEO = 3;
    const EARNING_TYPE_SESSION_2_SET_2_VIDEO = 4;
    const EARNING_TYPE_SCRATCH_EARNING = 5;
    const EARNING_TYPE_SAVINGS_EARNING = 6;

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

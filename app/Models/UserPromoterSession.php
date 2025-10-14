<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPromoterSession extends Model
{
    //
const SESSION_TYPE_MORNING = 1;
const SESSION_TYPE_EVENING = 2;
const SET1_STATUS_ASSIGNED = 0;
const SET1_STATUS_VIDEO_WATCHED = 1;
const SET1_STATUS_QUIZ_COMPLETED = 2;
const SET1_STATUS_SUBMITTED = 3;
const SET2_STATUS_ASSIGNED = 0;
const SET2_STATUS_VIDEO_WATCHED = 1;
const SET2_STATUS_QUIZ_COMPLETED = 2;
const SET2_STATUS_SUBMITTED = 3;
const SET1_VIDEO_ORDER_1 = 1;
const SET1_VIDEO_ORDER_2 = 2;
const SET2_VIDEO_ORDER_3 = 3;
const SET2_VIDEO_ORDER_4 = 4;
 
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function user_promoter()
    {
        return $this->belongsTo(UserPromoter::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionVideoQuiz extends Model
{
    //
    public function questions()
    {
        return $this->hasMany(PromotionQuizQuestion::class,'promotion_video_quiz_id');
    }
    public function promotion_video()
    {
        return $this->belongsTo(PromotionVideo::class,'promotion_video_id');
    }
}

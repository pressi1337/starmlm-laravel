<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionQuizQuestion extends Model
{
    //
    public function question()
    {
        return $this->belongsTo(PromotionVideoQuiz::class,'promotion_video_quiz_id');
    }
    public function choices()
    {
        return $this->hasMany(PromotionQuizChoice::class,'promotion_quiz_question_id');
    }
}

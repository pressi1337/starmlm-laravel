<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionVideoQuiz extends Model
{
    //
    public function promotionVideoQuizQuestions()
    {
        return $this->hasMany(PromotionQuizQuestion::class,'promotion_video_quiz_id');
    }
}

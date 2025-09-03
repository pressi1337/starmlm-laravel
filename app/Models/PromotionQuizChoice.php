<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionQuizChoice extends Model
{
    //
    public function promotionQuizQuestion()
    {
        return $this->belongsTo(PromotionQuizQuestion::class,'promotion_quiz_question_id');
    }
}

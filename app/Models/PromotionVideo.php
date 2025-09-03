<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionVideo extends Model
{
    //
    public function promotionQuiz()
    {
        return $this->belongsTo(PromotionVideoQuiz::class,'promotion_video_id');
    }
}

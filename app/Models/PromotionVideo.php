<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionVideo extends Model
{
    //
    public function quiz()
    {
        return $this->hasOne(PromotionVideoQuiz::class,'promotion_video_id');
    }
}

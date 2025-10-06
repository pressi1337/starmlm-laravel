<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingVideo extends Model
{
    //
    public function quiz()
    {
        return $this->hasOne(TrainingVideoQuiz::class,'training_video_id');
    }
}

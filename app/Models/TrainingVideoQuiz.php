<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingVideoQuiz extends Model
{
    //
    public function training_video()
    {
        return $this->belongsTo(TrainingVideo::class,'training_video_id');
    }

    public function questions()
    {
        return $this->hasMany(TrainingQuizQuestion::class,'training_quiz_id');
    }
}

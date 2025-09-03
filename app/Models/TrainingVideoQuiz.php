<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingVideoQuiz extends Model
{
    //
    public function trainingVideo()
    {
        return $this->belongsTo(TrainingVideo::class,'training_video_id');
    }

    public function trainingVideoQuizQuestions()
    {
        return $this->hasMany(TrainingVideoQuizQuestion::class,'training_quiz_id');
    }
}

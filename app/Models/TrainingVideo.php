<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingVideo extends Model
{
    //
    public function trainingVideoQuizzes()
    {
        return $this->hasMany(TrainingVideoQuiz::class,'training_video_id');
    }
}

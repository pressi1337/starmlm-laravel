<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingQuizQuestion extends Model
{
    //
    public function quiz()
    {
        return $this->belongsTo(TrainingVideoQuiz::class,'training_quiz_id');
    }
    public function choices()
    {
        return $this->hasMany(TrainingQuizChoice::class,'training_quiz_question_id');
    }
}

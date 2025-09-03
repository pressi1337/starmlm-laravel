<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingQuizChoice extends Model
{
    //
    public function trainingQuizQuestion()
    {
        return $this->belongsTo(TrainingQuizQuestion::class,'training_quiz_question_id');
    }
    
}

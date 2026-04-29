<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingVideo extends Model
{
    // Total length of the user-facing training program. Day numbers must be in [1, MAX_TRAINING_DAYS].
    const MAX_TRAINING_DAYS = 3;

    public function quiz()
    {
        return $this->hasOne(TrainingVideoQuiz::class,'training_video_id');
    }
}

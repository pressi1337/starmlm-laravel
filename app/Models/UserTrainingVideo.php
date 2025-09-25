<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserTrainingVideo extends Model
{
    //
    const STATUS_ASSIGNED = 0;
    const STATUS_IN_PROGRESS = 1;
    const STATUS_COMPLETED = 2; 
    protected $fillable = [
        'user_id',
        'training_video_id',
        'day',
        'status',
        'assigned_at',
        'completed_at',
    ];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function trainingVideo()
    {
        return $this->belongsTo(TrainingVideo::class);
    }
}

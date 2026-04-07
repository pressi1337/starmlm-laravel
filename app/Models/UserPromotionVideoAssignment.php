<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPromotionVideoAssignment extends Model
{
    public const ASSIGNMENT_TYPE_NEW = 'new';
    public const ASSIGNMENT_TYPE_REPLAY = 'replay';

    protected $fillable = [
        'user_id',
        'promotion_video_id',
        'user_promoter_session_id',
        'tree_root_user_id',
        'attend_date',
        'session_type',
        'set_no',
        'video_order_slot',
        'assignment_type',
        'is_watched',
        'is_quiz_completed',
        'is_confirmed',
        'assigned_at',
        'watched_at',
        'quiz_completed_at',
        'confirmed_at',
        'is_deleted',
        'created_by',
        'updated_by',
    ];

    public function promotionVideo()
    {
        return $this->belongsTo(PromotionVideo::class, 'promotion_video_id');
    }
}

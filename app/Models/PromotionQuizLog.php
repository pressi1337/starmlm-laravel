<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit record for a single promotion-video quiz attempt.
 * See the create migration for column semantics.
 */
class PromotionQuizLog extends Model
{
    // Outcome of an attempt.
    const STATUS_ATTEMPTED = 1; // submitted, awaiting confirm or retry
    const STATUS_CONFIRMED = 2; // user confirmed the result (earning banked)
    const STATUS_RETRIED   = 3; // user chose to retry; superseded by a newer attempt

    const SESSION_TYPE_MORNING = 1;
    const SESSION_TYPE_EVENING = 2;

    protected $fillable = [
        'user_id',
        'promotion_video_id',
        'promotion_video_title',
        'user_promoter_id',
        'user_promoter_session_id',
        'promoter_level',
        'session_type',
        'set_no',
        'attempt_no',
        'total_questions',
        'correct_count',
        'failed_count',
        'percentage',
        'earned_amount',
        'main_wallet_amount',
        'saving_amount',
        'offered_retry',
        'status',
        'answers',
        'attempted_at',
        'video_watched_at',
        'confirmed_at',
    ];

    protected $casts = [
        'answers'      => 'array',
        'attempted_at' => 'datetime',
        'video_watched_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'percentage'   => 'float',
        'earned_amount' => 'float',
        'main_wallet_amount' => 'float',
        'saving_amount' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function statusLabel(): string
    {
        return [
            self::STATUS_ATTEMPTED => 'Attempted',
            self::STATUS_CONFIRMED => 'Confirmed',
            self::STATUS_RETRIED   => 'Retried',
        ][$this->status] ?? 'Unknown';
    }

    public function sessionLabel(): string
    {
        return [
            self::SESSION_TYPE_MORNING => 'Morning',
            self::SESSION_TYPE_EVENING => 'Evening',
        ][$this->session_type] ?? '-';
    }
}

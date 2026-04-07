<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyVideo extends Model
{
    const DELIVERY_MODE_SCHEDULED = 1;
    const DELIVERY_MODE_COMMON_FALLBACK = 2;
    const DELIVERY_MODE_NEW_JOINER_DEFAULT = 3;

    protected $fillable = [
        'title',
        'description',
        'video_path',
        'youtube_link',
        'showing_date',
        'type',
        'delivery_mode',
        'priority',
        'created_by',
        'updated_by',
        'is_active',
        'is_deleted',
    ];

    public static function deliveryModeLabel(?int $deliveryMode): string
    {
        return match ((int) $deliveryMode) {
            self::DELIVERY_MODE_COMMON_FALLBACK => 'Common Fallback',
            self::DELIVERY_MODE_NEW_JOINER_DEFAULT => 'New Joiner Default',
            default => 'Scheduled Daily',
        };
    }
}

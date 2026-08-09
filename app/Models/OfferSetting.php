<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Points-per-upgrade config, one row per option type (own / referral).
 */
class OfferSetting extends Model
{
    const OPTION_OWN      = 1;
    const OPTION_REFERRAL = 2;

    /**
     * Level REACHED → the column holding its point value. Keyed by the level
     * achieved, never by a from→to pair, because levels can be skipped (a
     * Promoter 1 can go straight to Promoter 3 or 4).
     */
    const LEVEL_COLUMNS = [
        0 => 'promotor_points',
        1 => 'promotor1_points',
        2 => 'promotor2_points',
        3 => 'promotor3_points',
        4 => 'promotor4_points',
    ];

    /** Human label for each level. */
    const LEVEL_LABELS = [
        0 => 'Promoter',
        1 => 'Promoter 1',
        2 => 'Promoter 2',
        3 => 'Promoter 3',
        4 => 'Promoter 4',
    ];

    protected $fillable = [
        'option_type',
        'promotor_points',
        'promotor1_points',
        'promotor2_points',
        'promotor3_points',
        'promotor4_points',
        'created_by',
        'updated_by',
        'is_active',
        'is_deleted',
    ];

    protected $casts = [
        'promotor_points'  => 'float',
        'promotor1_points' => 'float',
        'promotor2_points' => 'float',
        'promotor3_points' => 'float',
        'promotor4_points' => 'float',
    ];

    /** The single active config row for an option type, or null. */
    public static function forOption(int $optionType): ?self
    {
        return self::where('option_type', $optionType)
            ->where('is_active', 1)
            ->where('is_deleted', 0)
            ->orderBy('id', 'desc')
            ->first();
    }

    /** Points configured for REACHING $level (skipped levels don't matter). */
    public function pointsForLevel($level): float
    {
        $column = self::LEVEL_COLUMNS[(int) $level] ?? null;
        if (!$column) {
            return 0.0;
        }
        return (float) ($this->{$column} ?? 0);
    }

    public static function optionLabel($optionType): string
    {
        return [
            self::OPTION_OWN      => 'Own',
            self::OPTION_REFERRAL => 'Referral',
        ][(int) $optionType] ?? 'Unknown';
    }

    public static function levelLabel($level): string
    {
        return self::LEVEL_LABELS[(int) $level] ?? '-';
    }
}

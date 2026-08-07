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

    /** Upgrade TARGET level → the column holding its point value. */
    const LEVEL_COLUMNS = [
        0 => 'trainee_to_promotor',
        1 => 'promotor_to_promotor1',
        2 => 'promotor1_to_promotor2',
        3 => 'promotor2_to_promotor3',
        4 => 'promotor3_to_promotor4',
    ];

    /** Human label for each upgrade step. */
    const LEVEL_LABELS = [
        0 => 'Trainee to Promoter',
        1 => 'Promoter to Promoter 1',
        2 => 'Promoter 1 to Promoter 2',
        3 => 'Promoter 2 to Promoter 3',
        4 => 'Promoter 3 to Promoter 4',
    ];

    protected $fillable = [
        'option_type',
        'trainee_to_promotor',
        'promotor_to_promotor1',
        'promotor1_to_promotor2',
        'promotor2_to_promotor3',
        'promotor3_to_promotor4',
        'created_by',
        'updated_by',
        'is_active',
        'is_deleted',
    ];

    protected $casts = [
        'trainee_to_promotor'    => 'float',
        'promotor_to_promotor1'  => 'float',
        'promotor1_to_promotor2' => 'float',
        'promotor2_to_promotor3' => 'float',
        'promotor3_to_promotor4' => 'float',
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

    /** Points configured for an upgrade whose TARGET level is $level. */
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

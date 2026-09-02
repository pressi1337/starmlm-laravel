<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sales price and MRP per promoter level — the master the invoice bills from.
 *
 * A dispatch copies these onto the box request rather than referencing them,
 * so a later price change never rewrites an invoice that has already been
 * issued. See the create migration.
 */
class ProductPrice extends Model
{
    /** Levels that can have a price, mirroring PromoterBoxRequest::LEVEL_RULES. */
    public const LEVELS = [0, 1, 2, 3, 4];

    /** Default product names, used when seeding a level for the first time. */
    public const DEFAULT_NAMES = [
        0 => 'Energy Plus',
        1 => 'Health Plus',
        2 => 'Health Plus',
        3 => 'Health Plus',
        4 => 'Health Plus',
    ];

    protected $fillable = [
        'level',
        'product_name',
        'price',
        'mrp',
        'is_active',
        'is_deleted',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'mrp'   => 'decimal:2',
    ];

    /** The live price row for a level, or null when none is configured. */
    public static function forLevel($level): ?self
    {
        return self::where('level', (int) $level)
            ->where('is_deleted', 0)
            ->where('is_active', 1)
            ->first();
    }

    public static function levelLabel($level): string
    {
        return [
            0 => 'Promoter',
            1 => 'Promoter Level 1',
            2 => 'Promoter Level 2',
            3 => 'Promoter Level 3',
            4 => 'Promoter Level 4',
        ][(int) $level] ?? ('Level ' . (int) $level);
    }
}

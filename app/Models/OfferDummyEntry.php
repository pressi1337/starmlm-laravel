<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Display-only leaderboard row entered by an admin.
 *
 * Not a real user: never earns points, never appears in the admin "User Points"
 * analytics, and is never included in any payout or totals calculation. It is
 * merged into the Top Points list purely for display.
 */
class OfferDummyEntry extends Model
{
    protected $fillable = [
        'username',
        'name',
        'points',
        'created_by',
        'updated_by',
        'is_active',
        'is_deleted',
    ];

    protected $casts = [
        'points' => 'float',
    ];

    /** Active display rows, highest points first. */
    public static function activeEntries()
    {
        return self::where('is_active', 1)
            ->where('is_deleted', 0)
            ->orderByDesc('points')
            ->get();
    }
}

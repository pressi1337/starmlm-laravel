<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Offer master config (single-row). See the create migration for semantics.
 */
class Offer extends Model
{
    protected $fillable = [
        'title',
        'is_active',
        'start_at',
        'top_list_count',
        'created_by',
        'updated_by',
        'is_deleted',
    ];

    protected $casts = [
        'start_at' => 'datetime',
    ];

    /** The live config row (latest non-deleted), or null if never set up. */
    public static function current(): ?self
    {
        return self::where('is_deleted', 0)->orderBy('id', 'desc')->first();
    }

    /** Has the configured start moment passed? (No start date = not started.) */
    public function hasStarted(): bool
    {
        if (empty($this->start_at)) {
            return false;
        }
        return Carbon::now()->greaterThanOrEqualTo(Carbon::parse($this->start_at));
    }

    /** Offer is switched on AND its start moment has arrived. */
    public function isRunning(): bool
    {
        return (int) $this->is_active === 1 && $this->hasStarted();
    }

    /** Leaderboard size, guarded to a sane range. */
    public function topCount(): int
    {
        $n = (int) ($this->top_list_count ?: 10);
        return max(1, min(100, $n));
    }
}

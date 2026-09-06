<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class ReferralScratchLevel extends Model
{

    /**
     * The pool of cashback amounts configured for this combination. A scratch
     * card draws one of these at random, so the payout is not fixed.
     */
    public function amounts()
    {
        return $this->hasMany(ReferralScratchLevelAmount::class, 'referral_scratch_level_id')
            ->orderBy('order_no')
            ->orderBy('id');
    }

    /**
     * Draw one amount for a scratch card.
     *
     * Returns ['amount' => float, 'msg' => ?string], or null when this
     * combination has nothing payable configured (caller should skip).
     *
     * Every call draws independently — two cards created in the same request
     * can legitimately be worth different amounts, which is the whole point.
     *
     * The pool is the source of truth: the backfill migration moved every
     * pre-existing single amount into it, and every save writes to it. The
     * fallback to this row's own `amount`/`msg` below is a SAFETY NET only —
     * it exists so an environment where the backfill has not run yet keeps
     * paying instead of silently issuing nothing. It should never fire in a
     * migrated database.
     */
    public function pickAmount(): ?array
    {
        $pool = collect();

        try {
            // relationLoaded() keeps an eager-loaded pool from re-querying per
            // card; the filter re-applies the live scope in memory either way.
            $pool = ($this->relationLoaded('amounts') ? $this->amounts : $this->amounts()->get())
                ->filter(function ($row) {
                    return (int) $row->is_active === 1
                        && (int) $row->is_deleted === 0
                        && (float) $row->amount > 0;
                })
                ->values();
        } catch (\Throwable $e) {
            // Missing table on a not-yet-migrated environment, etc. Never let
            // this break a payout — fall through to the legacy amount.
            Log::warning('Scratch amount pool unavailable, using flat amount', [
                'referral_scratch_level_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            $pool = collect();
        }

        if ($pool->isNotEmpty()) {
            $chosen = $pool[random_int(0, $pool->count() - 1)];
            return [
                'amount' => (float) $chosen->amount,
                // A pool entry with no message of its own inherits the
                // combination's message rather than showing nothing.
                'msg' => $chosen->msg !== null && $chosen->msg !== '' ? $chosen->msg : $this->msg,
            ];
        }

        if ((float) $this->amount > 0) {
            return ['amount' => (float) $this->amount, 'msg' => $this->msg];
        }

        return null;
    }
}

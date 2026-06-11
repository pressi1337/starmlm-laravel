<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPromoter extends Model
{
    const GIFT_DELIVERY_TYPE_PICKUP = 1;
    const GIFT_DELIVERY_TYPE_DELIVERY = 2;
    const PIN_STATUS_PENDING = 0;
    const PIN_STATUS_APPROVED = 1;
    const PIN_STATUS_ACTIVATED = 2;
    const PIN_STATUS_REJECTED = 3; 
    const PROMOTER_STATUS_CLOSED = 4;
    protected $fillable = [
        'user_id',
        'level',
        'gift_delivery_type',
        'gift_delivery_address',
        'wh_number',
        'pin',
        'status',
        'pin_generated_at',
        'activated_at',
        'created_by',
        'updated_by',
        'is_active',
        'is_deleted',
    ];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Auto-raise Terms & Conditions for pin requests that have stayed pending
     * for more than $minutes without an admin manually raising the term.
     * Mirrors UserPromoterController::termRaised() — flips the requesting
     * user's promoter_status from PENDING to SHOW_TERM so the user isn't stuck
     * waiting on an absent admin. Idempotent and safe to call repeatedly (the
     * traffic-driven maintenance middleware and the lazy controller fallback
     * both invoke it).
     *
     * Pass $userId to limit the sweep to a single user (used by the lazy
     * fallback on the user's own pin screen); omit it to sweep everyone (used
     * by the maintenance middleware). Returns the number of terms raised.
     */
    public static function autoRaiseDueTerms(int $minutes = 10, ?int $userId = null): int
    {
        $cutoff = now()->subMinutes($minutes);

        $query = self::where('status', self::PIN_STATUS_PENDING)
            ->where('is_deleted', 0)
            ->where('created_at', '<=', $cutoff)
            ->whereHas('user', function ($q) {
                $q->where('promoter_status', User::PROMOTER_STATUS_PENDING);
            });

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $raised = 0;
        foreach ($query->get() as $promoter) {
            // Re-check inside the loop so a manual raise that lands between the
            // query and here is never overwritten.
            $user = User::find($promoter->user_id);
            if ($user && (int) $user->promoter_status === User::PROMOTER_STATUS_PENDING) {
                $user->promoter_status = User::PROMOTER_STATUS_SHOW_TERM;
                $user->save();
                $raised++;
            }
        }

        return $raised;
    }

    /**
     * Auto-reject pin requests for which no pin was generated within $days of
     * the request. "No pin generated" = the row is still PIN_STATUS_PENDING
     * (generatePin would have moved it to APPROVED). Mirrors
     * UserPromoterController::pinRejected() — sets the row to REJECTED and
     * resets the user's promoter_status (back to their existing activated level
     * if they have one, otherwise null). Idempotent; never touches a request
     * that has since been generated, activated, or rejected.
     *
     * Pass $userId to limit the sweep to a single user (lazy fallback); omit it
     * to sweep everyone (maintenance middleware). Returns the number rejected.
     */
    public static function autoRejectStalePins(int $days = 5, ?int $userId = null): int
    {
        $cutoff = now()->subDays($days);

        $query = self::where('status', self::PIN_STATUS_PENDING)
            ->where('is_deleted', 0)
            ->where('created_at', '<=', $cutoff);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $rejected = 0;
        foreach ($query->get() as $promoter) {
            // Re-check so a pin generated/rejected between the query and here is
            // never clobbered.
            $fresh = self::find($promoter->id);
            if (!$fresh || $fresh->is_deleted || (int) $fresh->status !== self::PIN_STATUS_PENDING) {
                continue;
            }

            $fresh->status = self::PIN_STATUS_REJECTED;
            $fresh->updated_by = null; // system action — no admin actor
            $fresh->save();

            $user = User::find($fresh->user_id);
            if ($user) {
                // Same as pinRejected(): keep them at their existing activated
                // level if they already have one, otherwise clear the status.
                $user->promoter_status = ($user->current_promoter_level === null)
                    ? null
                    : User::PROMOTER_STATUS_ACTIVATED;
                $user->save();
            }

            $rejected++;
        }

        return $rejected;
    }
}

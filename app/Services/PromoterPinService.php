<?php

namespace App\Services;

use App\Models\PromoterPayment;
use App\Models\User;
use App\Models\UserPromoter;
use Illuminate\Support\Facades\DB;

/**
 * Issues the activation pin for a promoter request.
 *
 * Two callers reach this: the admin's Generate Pin action, and a successful
 * plan payment (the gateway result generates the pin on its own, so the user
 * is not left waiting for an admin after paying). Keeping it in one place
 * means both paths leave exactly the same rows behind.
 */
class PromoterPinService
{
    /**
     * Issue the pin for $promoter and move its user to APPROVED.
     *
     * Idempotent: a request that already has a pin (APPROVED) or has been
     * activated is returned untouched, so a gateway return and its server
     * callback arriving together cannot produce two pins.
     *
     * @param int|null $actorId Admin who clicked Generate Pin; null when the
     *                          payment generated it, which is not a person.
     */
    public function generate(UserPromoter $promoter, ?int $actorId = null): UserPromoter
    {
        return DB::transaction(function () use ($promoter, $actorId) {
            $row = UserPromoter::whereKey($promoter->id)->lockForUpdate()->first();

            if (!$row || (int) $row->status !== UserPromoter::PIN_STATUS_PENDING) {
                return $row ?: $promoter;
            }

            $row->pin = strtoupper('PROM' . rand(1000, 9999));
            $row->status = UserPromoter::PIN_STATUS_APPROVED;
            $row->pin_generated_at = now();
            $row->updated_by = $actorId;
            $row->save();

            $user = User::find($row->user_id);
            if ($user) {
                $user->current_promoter_level = $row->level;
                $user->promoter_status = User::PROMOTER_STATUS_APPROVED;
                $user->save();
            }

            return $row;
        });
    }

    /**
     * Has this request been paid for? True when any settled-success payment
     * exists against it — online through the gateway, or an offline payment
     * an admin recorded.
     */
    public function isPaid(int $userPromoterId): bool
    {
        return PromoterPayment::where('user_promoter_id', $userPromoterId)
            ->where('purpose', PromoterPayment::PURPOSE_PROMOTER)
            ->where('status', PromoterPayment::STATUS_SUCCESS)
            ->where('is_deleted', 0)
            ->exists();
    }

    /** The payment that paid for this request, newest first, or null. */
    public function paidPayment(int $userPromoterId): ?PromoterPayment
    {
        return PromoterPayment::where('user_promoter_id', $userPromoterId)
            ->where('purpose', PromoterPayment::PURPOSE_PROMOTER)
            ->where('status', PromoterPayment::STATUS_SUCCESS)
            ->where('is_deleted', 0)
            ->orderByDesc('id')
            ->first();
    }
}

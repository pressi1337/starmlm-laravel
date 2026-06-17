<?php

use App\Models\PromoterBoxRequest;
use App\Models\UserPromoter;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * One-time backfill for promoters who activated BEFORE the plan-product
     * feature existed. Each already-ACTIVATED promoter with no existing
     * allocation gets a single "Requested" batch for their level's full
     * entitlement (cap): L0=1, L1=1, L2=10, L3=20, L4=30 — reusing the delivery
     * details captured at activation.
     *
     * Keyed on user_promoter_id with a NOT-EXISTS guard, so it never duplicates
     * a batch that activation already created and is safe to re-run.
     */
    public function up(): void
    {
        $rules = PromoterBoxRequest::LEVEL_RULES;
        $now = now();

        UserPromoter::where('status', UserPromoter::PIN_STATUS_ACTIVATED)
            ->where('is_deleted', 0)
            ->orderBy('id')
            ->chunkById(200, function ($promoters) use ($rules, $now) {
                foreach ($promoters as $p) {
                    $level = (int) $p->level;
                    if (!isset($rules[$level])) {
                        continue;
                    }
                    $qty = (int) ($rules[$level]['cap'] ?? 0);
                    if ($qty <= 0) {
                        continue;
                    }

                    $alreadyHas = PromoterBoxRequest::where('user_promoter_id', $p->id)
                        ->where('is_deleted', 0)
                        ->exists();
                    if ($alreadyHas) {
                        continue;
                    }

                    PromoterBoxRequest::create([
                        'user_id'          => $p->user_id,
                        'user_promoter_id' => $p->id,
                        'level'            => $level,
                        'quantity'         => $qty,
                        'delivery_type'    => $p->gift_delivery_type,
                        'delivery_address' => $p->gift_delivery_address,
                        'pickup_date'      => $p->direct_pick_date,
                        'contact_number'   => $p->wh_number,
                        'status'           => PromoterBoxRequest::STATUS_REQUESTED,
                        'requested_at'     => $p->activated_at ?? $now,
                        'created_by'       => $p->user_id,
                        'updated_by'       => $p->user_id,
                        'is_active'        => 1,
                        'is_deleted'       => 0,
                    ]);
                }
            });
    }

    /**
     * Data backfill — no automatic rollback (backfilled rows can't be told
     * apart from genuine ones).
     */
    public function down(): void
    {
        // intentionally a no-op
    }
};

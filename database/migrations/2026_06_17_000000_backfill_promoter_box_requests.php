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

                    // Date the batch to when the promoter actually activated, so
                    // the lists (which show created_at) reflect the original
                    // activation rather than the backfill run time.
                    $ts = $p->activated_at ?? $p->created_at ?? $now;

                    $box = new PromoterBoxRequest();
                    $box->user_id          = $p->user_id;
                    $box->user_promoter_id = $p->id;
                    $box->level            = $level;
                    $box->quantity         = $qty;
                    $box->delivery_type    = $p->gift_delivery_type;
                    $box->delivery_address = $p->gift_delivery_address;
                    $box->pickup_date      = $p->direct_pick_date;
                    $box->contact_number   = $p->wh_number;
                    $box->status           = PromoterBoxRequest::STATUS_REQUESTED;
                    $box->requested_at     = $ts;
                    $box->created_by       = $p->user_id;
                    $box->updated_by       = $p->user_id;
                    $box->is_active        = 1;
                    $box->is_deleted       = 0;
                    $box->created_at       = $ts;
                    $box->updated_at       = $ts;
                    $box->timestamps       = false; // keep our explicit timestamps
                    $box->save();
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

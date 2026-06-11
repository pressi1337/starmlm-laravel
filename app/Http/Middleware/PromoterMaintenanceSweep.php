<?php

namespace App\Http\Middleware;

use App\Models\UserPromoter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PromoterMaintenanceSweep
{
    /**
     * Throttle window in seconds — the sweep runs at most once per this many
     * seconds, regardless of how many requests arrive.
     */
    private const EVERY_SECONDS = 60;

    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    /**
     * Cron-less, traffic-driven maintenance. After the response is sent, at
     * most once per minute (throttled via the cache), apply the time-based
     * promoter transitions:
     *   - raise Terms & Conditions for requests pending > 10 minutes,
     *   - reject requests with no pin generated within 5 days.
     *
     * Because it rides on ordinary request traffic, it needs no scheduler cron
     * and no queue worker — as long as the app is being used it behaves like an
     * every-minute job. The throttle keeps it to one sweep per minute no matter
     * the request volume, and the underlying sweep methods are idempotent so a
     * rare double-run is harmless. It runs in terminate() so it never adds
     * latency to the user's response.
     */
    public function terminate(Request $request, $response): void
    {
        try {
            // Cheap read first so the vast majority of requests do no write.
            if (Cache::get('promoter_maint_sweep_at') !== null) {
                return;
            }
            // Atomic claim: only the first request in this window proceeds.
            if (!Cache::add('promoter_maint_sweep_at', 1, self::EVERY_SECONDS)) {
                return;
            }

            UserPromoter::autoRaiseDueTerms(10);
            UserPromoter::autoRejectStalePins(5);
        } catch (\Throwable $e) {
            Log::error('Promoter maintenance sweep failed', ['error' => $e->getMessage()]);
        }
    }
}

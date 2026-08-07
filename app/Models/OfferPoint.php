<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Offer points ledger. Every award is one row; all totals are aggregated from
 * here so the history view is always consistent with the summary cards.
 */
class OfferPoint extends Model
{
    protected $fillable = [
        'user_id',
        'source_user_id',
        'user_promoter_id',
        'option_type',
        'level',
        'points',
        'description',
        'earned_at',
        'is_deleted',
    ];

    protected $casts = [
        'points'    => 'float',
        'earned_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sourceUser()
    {
        return $this->belongsTo(User::class, 'source_user_id');
    }

    /**
     * Award offer points for a completed promoter upgrade.
     *
     * Called from UserPromoterController::activatePin once the user's
     * current_promoter_level has been set. Awards:
     *   • "Own" points to the upgrading user, and
     *   • "Referral" points to their DIRECT referrer (users.referred_by).
     *
     * No-ops unless the offer exists, is active, and its start moment has
     * passed. Never throws — a failure here must not break pin activation.
     *
     * @param User $user  the user who just upgraded
     * @param int  $level the TARGET promoter level (0..4)
     */
    public static function awardForUpgrade(User $user, int $level, $userPromoterId = null): void
    {
        try {
            $offer = Offer::current();
            if (!$offer || !$offer->isRunning()) {
                return; // offer off, or not started yet — nothing is earned
            }

            $levelLabel = OfferSetting::levelLabel($level);

            // ── Own upgrade points ──────────────────────────────────────────
            $ownSetting = OfferSetting::forOption(OfferSetting::OPTION_OWN);
            if ($ownSetting) {
                $points = $ownSetting->pointsForLevel($level);
                if ($points > 0) {
                    self::create([
                        'user_id'          => $user->id,
                        'source_user_id'   => $user->id,
                        'user_promoter_id' => $userPromoterId,
                        'option_type'      => OfferSetting::OPTION_OWN,
                        'level'            => $level,
                        'points'           => $points,
                        'description'      => 'Own upgrade: ' . $levelLabel,
                        'earned_at'        => now(),
                    ]);
                }
            }

            // ── Referral upgrade points (direct referrer only) ──────────────
            if (!empty($user->referred_by)) {
                $referrer = User::where('id', $user->referred_by)
                    ->where('is_deleted', 0)
                    ->where('is_active', 1)
                    ->first();
                if ($referrer) {
                    $refSetting = OfferSetting::forOption(OfferSetting::OPTION_REFERRAL);
                    if ($refSetting) {
                        $points = $refSetting->pointsForLevel($level);
                        if ($points > 0) {
                            self::create([
                                'user_id'          => $referrer->id,
                                'source_user_id'   => $user->id,
                                'user_promoter_id' => $userPromoterId,
                                'option_type'      => OfferSetting::OPTION_REFERRAL,
                                'level'            => $level,
                                'points'           => $points,
                                'description'      => 'Referral upgrade (' . ($user->username ?? 'user') . '): ' . $levelLabel,
                                'earned_at'        => now(),
                            ]);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('OfferPoint awardForUpgrade failed', [
                'user_id' => $user->id ?? null,
                'level'   => $level,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Point totals for one user: own / referral / combined.
     */
    public static function totalsForUser($userId): array
    {
        $row = self::where('user_id', $userId)
            ->where('is_deleted', 0)
            ->selectRaw('COALESCE(SUM(points), 0) as total_points')
            ->selectRaw('COALESCE(SUM(CASE WHEN option_type = ? THEN points ELSE 0 END), 0) as own_points', [OfferSetting::OPTION_OWN])
            ->selectRaw('COALESCE(SUM(CASE WHEN option_type = ? THEN points ELSE 0 END), 0) as referral_points', [OfferSetting::OPTION_REFERRAL])
            ->first();

        return [
            'total_points'    => round((float) ($row->total_points ?? 0), 2),
            'own_points'      => round((float) ($row->own_points ?? 0), 2),
            'referral_points' => round((float) ($row->referral_points ?? 0), 2),
        ];
    }

    /**
     * Per-user aggregate query (own / referral / total) joined to users.
     * Shared by the admin "user points" tab and the public leaderboard.
     */
    public static function aggregateQuery()
    {
        return DB::table('offer_points as op')
            ->join('users as u', 'u.id', '=', 'op.user_id')
            ->where('op.is_deleted', 0)
            ->where('u.is_deleted', 0)
            ->groupBy('op.user_id', 'u.username', 'u.first_name', 'u.last_name', 'u.customer_id', 'u.mobile')
            ->select(
                'op.user_id',
                'u.username',
                'u.first_name',
                'u.last_name',
                'u.customer_id',
                'u.mobile',
                DB::raw('COALESCE(SUM(op.points), 0) as total_points'),
                DB::raw('COALESCE(SUM(CASE WHEN op.option_type = ' . OfferSetting::OPTION_OWN . ' THEN op.points ELSE 0 END), 0) as own_points'),
                DB::raw('COALESCE(SUM(CASE WHEN op.option_type = ' . OfferSetting::OPTION_REFERRAL . ' THEN op.points ELSE 0 END), 0) as referral_points')
            );
    }
}

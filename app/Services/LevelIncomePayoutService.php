<?php

namespace App\Services;

use App\Models\EarningHistory;
use App\Models\LevelIncomeRule;
use App\Models\User;
use App\Models\WithdrawRequest;
use Illuminate\Support\Facades\DB;

class LevelIncomePayoutService
{
    public function __construct(
        protected ReferralTreeService $referralTreeService
    ) {
    }

    public function distributeForPromoterActivation(User $sourceUser, int $referenceId): void
    {
        $ancestors = $this->referralTreeService->getAncestors($sourceUser->id);

        foreach ($ancestors as $ancestorData) {
            $depth = (int) $ancestorData['depth'];
            /** @var User $beneficiary */
            $beneficiary = $ancestorData['user'];

            if ($beneficiary->promoter_status !== User::PROMOTER_STATUS_ACTIVATED) {
                continue;
            }

            if ($beneficiary->current_promoter_level === null) {
                continue;
            }

            $rule = LevelIncomeRule::query()
                ->where('promoter_level', $beneficiary->current_promoter_level)
                ->where('referral_depth', $depth)
                ->where('trigger_type', LevelIncomeRule::TRIGGER_TYPE_PROMOTER_ACTIVATION)
                ->where('is_active', 1)
                ->where('is_deleted', 0)
                ->orderByDesc('id')
                ->first();

            if (!$rule || (float) $rule->amount <= 0) {
                continue;
            }

            $alreadyDistributed = EarningHistory::query()
                ->where('user_id', $beneficiary->id)
                ->where('earning_type', EarningHistory::EARNING_TYPE_LEVEL_INCOME)
                ->where('trigger_type', LevelIncomeRule::TRIGGER_TYPE_PROMOTER_ACTIVATION)
                ->where('reference_id', $referenceId)
                ->where('referral_depth', $depth)
                ->where('is_deleted', 0)
                ->exists();

            if ($alreadyDistributed) {
                continue;
            }

            DB::transaction(function () use ($beneficiary, $sourceUser, $rule, $depth, $referenceId) {
                $earning = new EarningHistory();
                $earning->user_id = $beneficiary->id;
                $earning->source_user_id = $sourceUser->id;
                $earning->amount = $rule->amount;
                $earning->earning_date = today();
                $earning->earning_type = EarningHistory::EARNING_TYPE_LEVEL_INCOME;
                $earning->earning_status = 1;
                $earning->description = sprintf(
                    'Level income from %s promoter activation at depth %d',
                    $sourceUser->username ?? ('User #' . $sourceUser->id),
                    $depth
                );
                $earning->referral_depth = $depth;
                $earning->beneficiary_promoter_level = $beneficiary->current_promoter_level;
                $earning->trigger_type = LevelIncomeRule::TRIGGER_TYPE_PROMOTER_ACTIVATION;
                $earning->income_rule_id = $rule->id;
                $earning->reference_id = $referenceId;
                $earning->created_by = $sourceUser->id;
                $earning->updated_by = $sourceUser->id;
                $earning->save();

                if ((int) $rule->wallet_type === WithdrawRequest::WALLET_TYPE_SCRATCH) {
                    $beneficiary->scratch_total_earning += $rule->amount;
                } elseif ((int) $rule->wallet_type === WithdrawRequest::WALLET_TYPE_GROW) {
                    $beneficiary->saving_total_earning += $rule->amount;
                } else {
                    $beneficiary->quiz_total_earning += $rule->amount;
                }

                $beneficiary->save();
            });
        }
    }
}

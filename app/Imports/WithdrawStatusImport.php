<?php

namespace App\Imports;

use App\Models\User;
use App\Models\WithdrawRequest;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

class WithdrawStatusImport implements ToCollection
{
    public function __construct(protected int $adminId)
    {
    }

    public function collection(Collection $rows)
    {
        foreach ($rows->skip(1) as $row) {
            $requestId = (int) ($row[0] ?? 0);
            $statusText = strtolower(trim((string) ($row[1] ?? '')));
            $details = trim((string) ($row[2] ?? ''));

            if (!$requestId || $statusText === '') {
                continue;
            }

            $withdraw = WithdrawRequest::find($requestId);
            if (!$withdraw) {
                continue;
            }

            $status = match ($statusText) {
                'processing' => WithdrawRequest::STATUS_PROCESSING,
                'completed' => WithdrawRequest::STATUS_COMPLETED,
                'rejected' => WithdrawRequest::STATUS_REJECTED,
                default => WithdrawRequest::STATUS_PENDING,
            };

            if ($withdraw->status === WithdrawRequest::STATUS_REJECTED && $status !== WithdrawRequest::STATUS_REJECTED) {
                continue;
            }

            $alreadyRejected = $withdraw->status === WithdrawRequest::STATUS_REJECTED;
            $withdraw->status = $status;
            $withdraw->status_updated_at = now();
            $withdraw->status_updated_by = $this->adminId;
            $withdraw->updated_by = $this->adminId;

            if ($status === WithdrawRequest::STATUS_PROCESSING) {
                $withdraw->processing_details = $details;
            } elseif ($status === WithdrawRequest::STATUS_COMPLETED) {
                $withdraw->completed_details = $details;
            } elseif ($status === WithdrawRequest::STATUS_REJECTED) {
                $withdraw->reason = $details;
                $withdraw->rejected_details = $details;
            }

            $withdraw->save();

            if ($status === WithdrawRequest::STATUS_REJECTED && !$alreadyRejected) {
                $user = User::find($withdraw->user_id);
                if ($user) {
                    if ($withdraw->wallet_type == WithdrawRequest::WALLET_TYPE_MAIN) {
                        $user->quiz_total_withdraw -= $withdraw->amount;
                    } elseif ($withdraw->wallet_type == WithdrawRequest::WALLET_TYPE_SCRATCH) {
                        $user->scratch_total_withdraw -= $withdraw->amount;
                    } elseif ($withdraw->wallet_type == WithdrawRequest::WALLET_TYPE_GROW) {
                        $user->saving_total_withdraw -= $withdraw->amount;
                    }
                    $user->save();
                }
            }
        }
    }
}

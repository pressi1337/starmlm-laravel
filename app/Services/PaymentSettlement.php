<?php

namespace App\Services;

use App\Models\ProductPrice;
use App\Models\PromoterPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies Omniware results to PromoterPayment rows — shared by the gateway
 * return/callback handlers and the admin status re-check.
 */
class PaymentSettlement
{
    public function __construct(private OmniwareGateway $gateway)
    {
    }

    /**
     * Apply a gateway result to a payment attempt. Idempotent and race-safe:
     * the browser return and the server callback can arrive together.
     *
     * @param bool|null $hashVerified true when $data's hash was checked, null
     *                                when it came from the Status API.
     */
    public function settle(PromoterPayment $payment, array $data, string $via, ?bool $hashVerified = null): PromoterPayment
    {
        return DB::transaction(function () use ($payment, $data, $via, $hashVerified) {
            $row = PromoterPayment::whereKey($payment->id)->lockForUpdate()->first();

            if ((int) $row->status === PromoterPayment::STATUS_SUCCESS) {
                return $row; // never downgrade a settled success
            }

            $status = PromoterPayment::statusFromGatewayCode($data['response_code'] ?? null);

            // Amount the merchant asked for — amount_orig when the account
            // passes the convenience fee to the customer, else amount.
            $charged = (float) ($data['amount_orig'] ?? $data['amount'] ?? 0);
            if ($status === PromoterPayment::STATUS_SUCCESS && abs($charged - (float) $row->amount) > 0.009) {
                Log::critical('Omniware amount mismatch', [
                    'order_id' => $row->order_id, 'expected' => $row->amount, 'got' => $charged,
                ]);
                $status = PromoterPayment::STATUS_FAILED;
                $data['error_desc'] = 'Amount mismatch: expected ' . $row->amount . ', got ' . $charged;
            }

            unset($data['hash']);
            $row->fill([
                'status'           => $status,
                'transaction_id'   => $data['transaction_id'] ?? $row->transaction_id,
                'response_code'    => isset($data['response_code']) && $data['response_code'] !== '' ? (int) $data['response_code'] : $row->response_code,
                'response_message' => isset($data['response_message']) ? mb_substr((string) $data['response_message'], 0, 255) : $row->response_message,
                'error_desc'       => isset($data['error_desc']) ? mb_substr((string) $data['error_desc'], 0, 500) : $row->error_desc,
                'payment_mode'     => $data['payment_mode'] ?? $data['payment_method'] ?? $row->payment_mode,
                'payment_channel'  => $data['payment_channel'] ?? $row->payment_channel,
                'payment_datetime' => $data['payment_datetime'] ?? $row->payment_datetime,
                'gateway_response' => $data,
            ]);
            if ($hashVerified !== null) {
                $row->hash_verified = $hashVerified ? 1 : 0;
            }
            if ($status !== PromoterPayment::STATUS_INITIATED) {
                $row->settled_via = $via;
            }
            if ($status === PromoterPayment::STATUS_SUCCESS) {
                $row->paid_at = now();
            }
            $row->save();

            return $row;
        });
    }

    /**
     * Ask the gateway for the truth about one attempt. Never throws.
     *
     * @return array{0: PromoterPayment, 1: array, 2: ?string} payment, raw status rows, lookup error
     */
    public function reconcile(PromoterPayment $payment): array
    {
        try {
            $rows = $this->gateway->fetchStatus($payment->order_id);
        } catch (Throwable $e) {
            // Most commonly: this server's IP isn't whitelisted with Omniware yet.
            Log::info('Omniware status lookup unavailable', ['order_id' => $payment->order_id, 'error' => $e->getMessage()]);
            return [$payment, [], $e->getMessage()];
        }

        if (!$rows) {
            return [$payment, [], null];
        }

        // Prefer a successful transaction if the user attempted more than once
        // on the same payment page; otherwise take the latest.
        $pick = collect($rows)->first(fn ($r) => (string) ($r['response_code'] ?? '') === '0') ?? end($rows);

        return [$this->settle($payment, $pick, 'status_api'), $rows, null];
    }

    /**
     * Raw form body as the gateway sent it. Laravel's TrimStrings /
     * ConvertEmptyStringsToNull middleware rewrite $request->all(), and the
     * hash must be computed over what the gateway actually signed.
     */
    public function gatewayPayload(Request $request): array
    {
        $raw = (string) $request->getContent();
        if ($raw !== '' && str_contains((string) $request->header('Content-Type'), 'application/x-www-form-urlencoded')) {
            parse_str($raw, $parsed);
            if (is_array($parsed) && $parsed) {
                return $parsed;
            }
        }
        return array_map(fn ($v) => $v ?? '', $request->all());
    }

    public function returnUrl(Request $request): string
    {
        $base = (string) config('services.omniware.callback_base_url');
        return rtrim($base !== '' ? $base : $request->getSchemeAndHttpHost(), '/') . '/api/v1/payments/omniware/return';
    }

    public function callbackUrl(Request $request): string
    {
        return str_replace('/omniware/return', '/omniware/callback', $this->returnUrl($request));
    }

    public function present(PromoterPayment $p): array
    {
        return [
            'order_id'         => $p->order_id,
            'purpose'          => $p->purpose,
            'amount'           => (float) $p->amount,
            'currency'         => $p->currency,
            'mode'             => $p->mode,
            'level'            => $p->level !== null ? (int) $p->level : null,
            'level_label'      => $p->level !== null ? ProductPrice::levelLabel($p->level) : 'Test payment',
            'status'           => (int) $p->status,
            'status_label'     => [0 => 'pending', 1 => 'success', 2 => 'failed', 3 => 'cancelled'][(int) $p->status] ?? 'pending',
            'transaction_id'   => $p->transaction_id,
            'response_code'    => $p->response_code,
            'response_message' => $p->response_message,
            'error_desc'       => $p->error_desc,
            'payment_mode'     => $p->payment_mode,
            'payment_channel'  => $p->payment_channel,
            'payment_datetime' => $p->payment_datetime,
            'settled_via'      => $p->settled_via,
            'hash_verified'    => $p->hash_verified === null ? null : (bool) $p->hash_verified,
            'paid_at'          => $p->paid_at,
            'created_at'       => $p->created_at,
        ];
    }
}

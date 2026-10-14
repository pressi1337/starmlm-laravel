<?php

namespace App\Services;

use App\Models\PromoterPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies Worldline (Paynimo) results to PromoterPayment rows — shared by the
 * gateway return/S2S handlers and the admin status re-check.
 *
 * Response fields used (decrypted, pipe-delimited — see WorldlineGateway):
 *   txn_status 0300 success / 0399 failure / 0002 pending
 *   txn_msg, txn_err_msg, clnt_txn_ref (our order id), tpsl_txn_id (theirs),
 *   txn_amt, bank_name, tpsl_bank_cd, tpsl_txn_time
 */
class PaymentSettlement
{
    public function __construct(private WorldlineGateway $gateway)
    {
    }

    /**
     * Apply a gateway result to a payment attempt. Idempotent and race-safe:
     * the browser return and the server-to-server call can arrive together.
     *
     * @param bool|null $hashVerified true when the payload decrypted AND its
     *                                hash matched; null when not applicable.
     */
    public function settle(PromoterPayment $payment, array $f, string $via, ?bool $hashVerified = null): PromoterPayment
    {
        return DB::transaction(function () use ($payment, $f, $via, $hashVerified) {
            $row = PromoterPayment::whereKey($payment->id)->lockForUpdate()->first();

            if ((int) $row->status === PromoterPayment::STATUS_SUCCESS) {
                return $row; // never downgrade a settled success
            }

            $status = PromoterPayment::statusFromGatewayStatus($f['txn_status'] ?? null);

            // The amount the gateway actually processed must match the order.
            $charged = (float) ($f['txn_amt'] ?? 0);
            if ($status === PromoterPayment::STATUS_SUCCESS && abs($charged - (float) $row->amount) > 0.009) {
                Log::critical('Worldline amount mismatch', [
                    'order_id' => $row->order_id, 'expected' => $row->amount, 'got' => $charged,
                ]);
                $status = PromoterPayment::STATUS_FAILED;
                $f['txn_err_msg'] = 'Amount mismatch: expected ' . $row->amount . ', got ' . $charged;
            }

            $row->fill([
                'status'           => $status,
                'transaction_id'   => $f['tpsl_txn_id'] ?? $row->transaction_id,
                'response_code'    => isset($f['txn_status']) && $f['txn_status'] !== '' ? (int) $f['txn_status'] : $row->response_code,
                'response_message' => isset($f['txn_msg']) ? mb_substr((string) $f['txn_msg'], 0, 255) : $row->response_message,
                'error_desc'       => !empty($f['txn_err_msg']) ? mb_substr((string) $f['txn_err_msg'], 0, 500) : $row->error_desc,
                'payment_mode'     => $f['tpsl_bank_cd'] ?? $row->payment_mode,
                'payment_channel'  => $f['bank_name'] ?? $row->payment_channel,
                'payment_datetime' => $f['tpsl_txn_time'] ?? $row->payment_datetime,
                'gateway_response' => $f,
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
     * Ask the gateway for the truth about one attempt (status enquiry).
     * Never throws.
     *
     * @return array{0: PromoterPayment, 1: array, 2: ?string} payment, decrypted fields, lookup error
     */
    public function reconcile(PromoterPayment $payment): array
    {
        try {
            $answer = $this->gateway->fetchStatus(
                $payment->order_id,
                number_format((float) $payment->amount, 2, '.', ''),
                $this->gateway->txnDate(optional($payment->created_at)->toDateString()),
            );
        } catch (Throwable $e) {
            Log::info('Worldline status enquiry failed', ['order_id' => $payment->order_id, 'error' => $e->getMessage()]);
            return [$payment, [], $e->getMessage()];
        }

        $fields = $answer['fields'];
        $txnStatus = (string) ($fields['txn_status'] ?? '');

        // No answer, or the gateway has never seen this order (the payer never
        // reached the payment page). Leave the row alone.
        if ($txnStatus === '' || $txnStatus === WorldlineGateway::STATUS_NOT_FOUND) {
            return [$payment, $fields, $txnStatus === WorldlineGateway::STATUS_NOT_FOUND
                ? ($fields['txn_err_msg'] ?: 'Transaction not found at the gateway')
                : null];
        }

        // A hash on an enquiry answer must match. The enquiry runs over our
        // own authenticated TLS call, so an answer with no hash is accepted
        // but recorded as unverified rather than verified.
        if ($answer['hash_present'] && !$answer['verified']) {
            Log::warning('Worldline enquiry hash mismatch', ['order_id' => $payment->order_id]);
            return [$payment, $fields, 'Gateway answer failed its hash check'];
        }

        $verified = $answer['hash_present'] ? true : null;

        return [$this->settle($payment, $fields, 'status_api', $verified), $fields, null];
    }

    /**
     * The gateway posts its result as a single encrypted `msg` field. Read it
     * from the raw body — Laravel's TrimStrings / ConvertEmptyStringsToNull
     * middleware rewrite $request->all(), and the payload must stay byte-exact.
     */
    public function gatewayPayload(Request $request): string
    {
        $raw = (string) $request->getContent();
        if ($raw !== '' && str_contains((string) $request->header('Content-Type'), 'application/x-www-form-urlencoded')) {
            parse_str($raw, $parsed);
            if (is_array($parsed) && isset($parsed['msg'])) {
                return (string) $parsed['msg'];
            }
        }
        return (string) ($request->input('msg') ?? $request->query('msg') ?? '');
    }

    public function returnUrl(Request $request): string
    {
        $base = (string) config('services.worldline.callback_base_url');
        return rtrim($base !== '' ? $base : $request->getSchemeAndHttpHost(), '/') . '/api/v1/payments/worldline/return';
    }

    public function s2sUrl(Request $request): string
    {
        return str_replace('/worldline/return', '/worldline/s2s', $this->returnUrl($request));
    }

    public function present(PromoterPayment $p): array
    {
        return [
            'order_id'         => $p->order_id,
            'purpose'          => $p->purpose,
            'amount'           => (float) $p->amount,
            'currency'         => $p->currency,
            'mode'             => $p->mode,
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

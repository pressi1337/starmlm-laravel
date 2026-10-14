<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\PromoterPayment;
use App\Services\PaymentSettlement;
use App\Services\WorldlineGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Endpoints the Worldline (Paynimo) gateway itself calls. Public routes: the
 * payload is AES-encrypted with our key and carries its own hash, so only a
 * payload that decrypts AND verifies is ever recorded.
 */
class WorldlineWebhookController extends Controller
{
    public function __construct(
        private WorldlineGateway $gateway,
        private PaymentSettlement $settlement,
    ) {
    }

    /**
     * POST payments/worldline/return — the gateway posts the encrypted result
     * here through the payer's browser, then we send the browser back to where
     * the payment was started from.
     */
    public function gatewayReturn(Request $request)
    {
        [$payment, $orderId] = $this->apply($request, 'return');

        // Admin test payments go back to the console page they started from
        // (validated against WORLDLINE_ADMIN_HOSTS when stored).
        if ($payment && $payment->redirect_to) {
            return redirect()->away(rtrim($payment->redirect_to, '/') . '/portal/admin/payment-gateway?order_id=' . urlencode($orderId));
        }

        return response('Payment response received. You can close this window.', 200);
    }

    /**
     * POST payments/worldline/s2s — Worldline's server-to-server copy of the
     * same result. Give this URL to Worldline as the S2S / callback URL.
     */
    public function gatewayS2s(Request $request)
    {
        [$payment, $orderId] = $this->apply($request, 's2s');

        if (!$payment) {
            return response('UNKNOWN ORDER', 404);
        }
        if (!$payment->isFinal() && (int) $payment->hash_verified === 0) {
            return response('INVALID PAYLOAD', 400);
        }

        return response('OK ' . $orderId, 200);
    }

    /**
     * Decrypt, verify and record one gateway payload.
     *
     * @return array{0: ?PromoterPayment, 1: string}
     */
    private function apply(Request $request, string $via): array
    {
        $msg = $this->settlement->gatewayPayload($request);
        $parsed = $this->gateway->parseResponse($msg);
        $fields = $parsed['fields'];
        $orderId = (string) ($fields['clnt_txn_ref'] ?? '');

        if ($orderId === '') {
            Log::warning('Worldline ' . $via . ': payload could not be read', ['length' => strlen($msg)]);
            return [null, ''];
        }

        $payment = PromoterPayment::where('order_id', $orderId)->first();
        if (!$payment) {
            Log::warning('Worldline ' . $via . ' for unknown order', ['order_id' => $orderId]);
            return [null, $orderId];
        }

        if ($parsed['verified']) {
            $payment = $this->settlement->settle($payment, $fields, $via, true);
        } else {
            // Decrypted but the hash did not match, or did not decrypt at all.
            // Don't trust it — ask the gateway directly instead.
            Log::warning('Worldline ' . $via . ' hash mismatch', ['order_id' => $orderId]);
            $payment->update(['hash_verified' => 0]);
            [$payment] = $this->settlement->reconcile($payment);
        }

        return [$payment, $orderId];
    }
}

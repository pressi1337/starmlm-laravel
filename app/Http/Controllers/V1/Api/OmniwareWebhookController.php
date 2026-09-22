<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\PromoterPayment;
use App\Services\OmniwareGateway;
use App\Services\PaymentSettlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Endpoints the Omniware (Federal Bank) gateway itself calls. Public routes:
 * the response hash (signed with our SALT) is the credential, and nothing is
 * recorded unless it verifies.
 */
class OmniwareWebhookController extends Controller
{
    public function __construct(
        private OmniwareGateway $gateway,
        private PaymentSettlement $settlement,
    ) {
    }

    /**
     * POST payments/omniware/return — the gateway posts the result here via
     * the payer's browser, then we send the browser back to where the payment
     * was started from.
     */
    public function gatewayReturn(Request $request)
    {
        $data = $this->settlement->gatewayPayload($request);
        $orderId = (string) ($data['order_id'] ?? '');
        $payment = $orderId !== '' ? PromoterPayment::where('order_id', $orderId)->first() : null;

        if ($payment) {
            if ($this->gateway->verify($data)) {
                $this->settlement->settle($payment, $data, 'return', true);
            } else {
                // Could be tampering, could be a field we hash differently.
                // Either way don't trust it — ask the gateway directly.
                Log::warning('Omniware return hash mismatch', ['order_id' => $orderId]);
                $payment->update(['hash_verified' => 0]);
                $this->settlement->reconcile($payment);
            }
        } else {
            Log::warning('Omniware return for unknown order', ['order_id' => $orderId]);
        }

        // Admin test payments go back to the console page they started from
        // (validated against OMNIWARE_ADMIN_HOSTS when stored).
        if ($payment && $payment->redirect_to) {
            return redirect()->away(rtrim($payment->redirect_to, '/') . '/portal/admin/payment-gateway?order_id=' . urlencode($orderId));
        }

        return response('Payment response received. You can close this window.', 200);
    }

    /**
     * POST payments/omniware/callback — server-to-server webhook. Configure it
     * as the "Payment Callback URL" in the Omniware dashboard.
     */
    public function gatewayCallback(Request $request)
    {
        $data = $this->settlement->gatewayPayload($request);
        $orderId = (string) ($data['order_id'] ?? '');

        if (!$this->gateway->verify($data)) {
            Log::warning('Omniware callback hash mismatch', ['order_id' => $orderId]);
            return response('INVALID HASH', 400);
        }

        $payment = PromoterPayment::where('order_id', $orderId)->first();
        if (!$payment) {
            Log::warning('Omniware callback for unknown order', ['order_id' => $orderId]);
            return response('UNKNOWN ORDER', 404);
        }

        $this->settlement->settle($payment, $data, 'callback', true);
        return response('OK', 200);
    }
}

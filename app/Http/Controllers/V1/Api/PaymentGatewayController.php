<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\PromoterPayment;
use App\Services\PaymentSettlement;
use App\Services\WorldlineGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Super-admin "Payment Gateway" page: shows the live Worldline (Paynimo)
 * configuration and lets an admin run a real payment for any amount, end to
 * end, to see exactly how the gateway behaves before promoters use it.
 *
 * Test payments are PromoterPayment rows with purpose = 'test'. After paying,
 * the gateway return (WorldlineWebhookController) sends the browser back to
 * /portal/admin/payment-gateway on the console it started from.
 */
class PaymentGatewayController extends Controller
{
    /** Worldline's test merchant only accepts amounts in this range. */
    private const TEST_MIN = 1;
    private const TEST_MAX = 10;

    public function __construct(
        private WorldlineGateway $gateway,
        private PaymentSettlement $settlement,
    ) {
    }

    /** GET payment-gateway/config */
    public function config(Request $request)
    {
        $cfg = config('services.worldline');
        $key = (string) ($cfg['enc_key'] ?? '');
        $iv = (string) ($cfg['enc_iv'] ?? '');
        $test = $this->gateway->mode() === 'TEST';

        return $this->ok([
            'provider'       => 'Worldline ePayments India (Paynimo)',
            'configured'     => $this->gateway->isConfigured(),
            'mode'           => $this->gateway->mode(),
            'service_url'    => $cfg['service_url'] ?? null,
            'merchant_code'  => $this->gateway->merchantCode() ?: null,
            'scheme_code'    => $this->gateway->schemeCode() ?: null,
            // Never return the full key or IV — only enough to recognise them.
            'enc_key_masked' => $this->mask($key),
            'enc_iv_masked'  => $this->mask($iv),
            'return_url'     => $this->settlement->returnUrl($request),
            's2s_url'        => $this->settlement->s2sUrl($request),
            'dashboard_url'  => 'https://www.paynimo.com/corpproducts/',
            'integration'    => 'Single URL integration (SOAP getTransactionToken + AES-128-CBC)',
            'bank_code'      => $cfg['bank_code'] ?: null,
            'min_amount'     => $test ? self::TEST_MIN : 1,
            'max_amount'     => $test ? self::TEST_MAX : null,
            'test_bank_login' => $test ? 'test / test' : null,
            'stats'          => [
                'total'     => PromoterPayment::where('is_deleted', 0)->count(),
                'success'   => PromoterPayment::where('is_deleted', 0)->where('status', PromoterPayment::STATUS_SUCCESS)->count(),
                'failed'    => PromoterPayment::where('is_deleted', 0)->whereIn('status', [PromoterPayment::STATUS_FAILED, PromoterPayment::STATUS_CANCELLED])->count(),
                'pending'   => PromoterPayment::where('is_deleted', 0)->where('status', PromoterPayment::STATUS_INITIATED)->count(),
                'collected' => (float) PromoterPayment::where('is_deleted', 0)->where('status', PromoterPayment::STATUS_SUCCESS)->sum('amount'),
            ],
        ]);
    }

    /** POST payment-gateway/test-initiate — start a real payment for any amount. */
    public function testInitiate(Request $request)
    {
        if (!$this->gateway->isConfigured()) {
            return $this->fail('Payment gateway is not configured. Set WORLDLINE_MERCHANT_CODE, WORLDLINE_ENC_KEY and WORLDLINE_ENC_IV in .env.');
        }

        $test = $this->gateway->mode() === 'TEST';
        $validator = Validator::make($request->all(), [
            // In TEST the gateway itself rejects anything outside ₹1–₹10.
            'amount'        => 'required|numeric|min:' . ($test ? self::TEST_MIN : 1) . ($test ? '|max:' . self::TEST_MAX : '|max:500000'),
            'name'          => 'required|string|max:100',
            'email'         => 'required|email|max:150',
            'phone'         => 'required|digits:10',
            'description'   => 'nullable|string|max:200',
            // Payment-option / bank code. Required by merchant setups that
            // don't show Worldline's own payment-options page (TPPGE006).
            'bank_code'     => 'nullable|alpha_num|max:10',
            'return_origin' => 'required|url|max:255',
        ], [
            'amount.max' => $test ? 'In TEST mode the gateway only accepts ₹1 to ₹10.' : 'Amount is too large.',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // The browser is sent back here after paying — only to our own consoles.
        $origin = $this->allowedOrigin((string) $request->return_origin);
        if (!$origin) {
            return $this->fail('This console address is not allowed as a return address.');
        }

        $adminId = Auth::id();
        $payment = PromoterPayment::create([
            'purpose'     => PromoterPayment::PURPOSE_TEST,
            'user_id'     => $adminId,
            'order_id'    => PromoterPayment::newTestOrderId(),
            'amount'      => round((float) $request->amount, 2),
            'currency'    => 'INR',
            'mode'        => $this->gateway->mode(),
            'status'      => PromoterPayment::STATUS_INITIATED,
            'redirect_to' => $origin,
            'created_by'  => $adminId,
            'updated_by'  => $adminId,
        ]);

        $params = [
            'order_id'    => $payment->order_id,
            'amount'      => number_format((float) $payment->amount, 2, '.', ''),
            'currency'    => 'INR',
            'name'        => trim($request->name),
            'email'       => trim($request->email),
            'phone'       => $request->phone,
            'customer_id' => 'admin-' . $adminId,
            'bank_code'   => $request->bank_code ?: (config('services.worldline.bank_code') ?: null),
            'return_url'  => $this->settlement->returnUrl($request),
            's2s_url'     => $this->settlement->s2sUrl($request),
        ];
        $params = array_filter($params, fn ($v) => $v !== null && $v !== '');
        $payment->update(['request_payload' => $params]);

        try {
            $url = $this->gateway->createPaymentUrl($params);
        } catch (Throwable $e) {
            $payment->update([
                'status'           => PromoterPayment::STATUS_FAILED,
                'response_message' => 'INIT-FAILED',
                'error_desc'       => mb_substr($e->getMessage(), 0, 500),
            ]);
            Log::warning('Worldline test payment init failed', ['order_id' => $payment->order_id, 'error' => $e->getMessage()]);
            return $this->fail('Gateway rejected the request: ' . $e->getMessage());
        }

        $payment->update(['payment_url' => $url]);

        return $this->ok([
            'order_id'    => $payment->order_id,
            'payment_url' => $url,
            'request'     => $params,
        ], 'Payment page created');
    }

    /** GET payment-gateway/transactions — newest first, all purposes. */
    public function transactions(Request $request)
    {
        $pageSize = min(max((int) $request->query('page_size', 20), 1), 100);
        $page = max((int) $request->query('page_number', 1), 1);
        $purpose = $request->query('purpose');

        $query = PromoterPayment::with('user:id,username,first_name,last_name,mobile')
            ->where('is_deleted', 0)
            ->when(in_array($purpose, [PromoterPayment::PURPOSE_TEST, PromoterPayment::PURPOSE_PROMOTER], true),
                fn ($q) => $q->where('purpose', $purpose))
            ->orderByDesc('id');

        $total = (clone $query)->count();
        $rows = $query->forPage($page, $pageSize)->get()->map(fn ($p) => $this->detail($p));

        return response()->json([
            'success'  => true,
            'message'  => 'Payment transactions',
            'data'     => $rows,
            'pageInfo' => [
                'page_size'     => $pageSize,
                'page_number'   => $page,
                'total_pages'   => (int) ceil($total / $pageSize),
                'total_records' => $total,
            ],
        ]);
    }

    /** GET payment-gateway/transactions/{orderId} — re-check with the status enquiry API. */
    public function checkStatus(string $orderId)
    {
        $payment = PromoterPayment::where('order_id', $orderId)->where('is_deleted', 0)->first();
        if (!$payment) {
            return $this->fail('Transaction not found.');
        }

        [$payment, $fields, $error] = $this->settlement->reconcile($payment);

        return $this->ok([
            'payment'       => $this->detail($payment->fresh('user')),
            'gateway_rows'  => $fields ? [$fields] : [],
            'gateway_error' => $error,
            'gateway_says'  => $error ? 'lookup_failed' : ($fields ? 'found' : 'no_transaction_yet'),
        ]);
    }

    // ---------------------------------------------------------------------

    private function detail(PromoterPayment $p): array
    {
        $user = $p->user;
        return $this->settlement->present($p) + [
            'id'               => $p->id,
            'user'             => $user ? [
                'id'     => $user->id,
                'name'   => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->username,
                'mobile' => $user->mobile,
            ] : null,
            'request_payload'  => $p->request_payload,
            'gateway_response' => $p->gateway_response,
        ];
    }

    /** Scheme+host(+port) of $url when its host is one of our consoles, else null. */
    private function allowedOrigin(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($host === '' || !in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $allowed = array_filter(array_map('trim', explode(',', (string) config('services.worldline.admin_hosts'))));
        foreach ($allowed as $suffix) {
            $suffix = strtolower(ltrim($suffix, '.'));
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
            }
        }
        return null;
    }

    private function mask(string $secret): ?string
    {
        return $secret !== '' ? substr($secret, 0, 4) . str_repeat('•', 8) . substr($secret, -4) : null;
    }

    private function ok(array $data, string $message = 'OK')
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], 200);
    }

    private function fail(string $message, int $code = 400)
    {
        return response()->json(['success' => false, 'message' => $message], $code);
    }
}

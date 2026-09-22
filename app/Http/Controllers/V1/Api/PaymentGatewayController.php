<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\PromoterPayment;
use App\Services\OmniwareGateway;
use App\Services\PaymentSettlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Super-admin "Payment Gateway" page: shows the live Omniware configuration
 * and lets an admin run a real payment for any amount, end to end, to see
 * exactly how the gateway behaves before promoters use it.
 *
 * Test payments are PromoterPayment rows with purpose = 'test'. After paying,
 * the gateway return (OmniwareWebhookController) sends the browser back to
 * /portal/admin/payment-gateway on the console it started from.
 */
class PaymentGatewayController extends Controller
{
    public function __construct(
        private OmniwareGateway $gateway,
        private PaymentSettlement $settlement,
    ) {
    }

    /** GET payment-gateway/config */
    public function config(Request $request)
    {
        $cfg = config('services.omniware');
        $key = (string) ($cfg['api_key'] ?? '');
        $salt = (string) ($cfg['salt'] ?? '');

        return $this->ok([
            'provider'       => 'Omniware (Federal Bank)',
            'configured'     => $this->gateway->isConfigured(),
            'mode'           => $this->gateway->mode(),
            'api_url'        => $cfg['api_url'] ?? null,
            // Never return the full key or salt — only enough to recognise them.
            'api_key_masked' => $key !== '' ? substr($key, 0, 8) . str_repeat('•', 8) . substr($key, -4) : null,
            'salt_masked'    => $salt !== '' ? str_repeat('•', 12) . substr($salt, -4) : null,
            'return_url'     => $this->settlement->returnUrl($request),
            'callback_url'   => $this->settlement->callbackUrl($request),
            'dashboard_url'  => 'https://pgmrm.omniware.in',
            'integration'    => 'Two-step hosted checkout (v2/getpaymentrequesturl)',
            'min_amount'     => 10,
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
            return $this->fail('Payment gateway is not configured. Set OMNIWARE_API_KEY and OMNIWARE_SALT in .env.');
        }

        $validator = Validator::make($request->all(), [
            'amount'        => 'required|numeric|min:1|max:500000',
            'name'          => 'required|string|max:100',
            'email'         => 'required|email|max:150',
            'phone'         => 'required|digits:10',
            'city'          => 'required|string|max:100',
            'state'         => 'nullable|string|max:100',
            'zip_code'      => 'required|digits:6',
            'description'   => 'nullable|string|max:200',
            'return_origin' => 'required|url|max:255',
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
            'mode'        => $payment->mode,
            'amount'      => number_format((float) $payment->amount, 2, '.', ''),
            'currency'    => 'INR',
            'description' => $request->description ?: 'Starup gateway test payment',
            'name'        => trim($request->name),
            'email'       => trim($request->email),
            'phone'       => $request->phone,
            'city'        => trim($request->city),
            'state'       => $request->state ? trim($request->state) : null,
            'zip_code'    => $request->zip_code,
            'country'     => 'IND',
            'udf1'        => 'admin-test',
            'udf2'        => (string) $adminId,
            'return_url'  => $this->settlement->returnUrl($request),
        ];
        $payment->update(['request_payload' => array_filter($params, fn ($v) => $v !== null && $v !== '')]);

        try {
            $page = $this->gateway->createPaymentUrl($params);
        } catch (Throwable $e) {
            $payment->update([
                'status'           => PromoterPayment::STATUS_FAILED,
                'response_message' => 'INIT-FAILED',
                'error_desc'       => mb_substr($e->getMessage(), 0, 500),
            ]);
            Log::warning('Omniware test payment init failed', ['order_id' => $payment->order_id, 'error' => $e->getMessage()]);
            return $this->fail('Gateway rejected the request: ' . $e->getMessage());
        }

        $payment->update([
            'gateway_uuid'   => $page['uuid'],
            'payment_url'    => $page['url'],
            'url_expires_at' => $page['expiry_datetime'],
        ]);

        return $this->ok([
            'order_id'        => $payment->order_id,
            'payment_url'     => $page['url'],
            'uuid'            => $page['uuid'],
            'expiry_datetime' => $page['expiry_datetime'],
            'request'         => $payment->request_payload,
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

    /** GET payment-gateway/transactions/{orderId} — re-check with the gateway Status API. */
    public function checkStatus(string $orderId)
    {
        $payment = PromoterPayment::where('order_id', $orderId)->where('is_deleted', 0)->first();
        if (!$payment) {
            return $this->fail('Transaction not found.');
        }

        [$payment, $rows, $error] = $this->settlement->reconcile($payment);

        return $this->ok([
            'payment'        => $this->detail($payment->fresh('user')),
            'gateway_rows'   => $rows,
            'gateway_error'  => $error,
            'gateway_says'   => $error ? 'lookup_failed' : ($rows ? 'found' : 'no_transaction_yet'),
        ]);
    }

    // ---------------------------------------------------------------------

    private function detail(PromoterPayment $p): array
    {
        $user = $p->user;
        return $this->settlement->present($p) + [
            'id'               => $p->id,
            'user'             => $user ? [
                'id'       => $user->id,
                'name'     => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->username,
                'mobile'   => $user->mobile,
            ] : null,
            'gateway_uuid'     => $p->gateway_uuid,
            'url_expires_at'   => $p->url_expires_at,
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

        $allowed = array_filter(array_map('trim', explode(',', (string) config('services.omniware.admin_hosts'))));
        foreach ($allowed as $suffix) {
            $suffix = strtolower(ltrim($suffix, '.'));
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
            }
        }
        return null;
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

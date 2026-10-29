<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductPrice;
use App\Models\PromoterPayment;
use App\Models\User;
use App\Models\UserPromoter;
use App\Services\OmniwareGateway;
use App\Services\PaymentSettlement;
use App\Services\PromoterPinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Plan payments for a promoter upgrade.
 *
 * Where this sits in the promoter flow:
 *   request upgrade -> terms raised -> terms accepted -> PAY HERE -> pin
 *   issued automatically -> user activates the pin.
 *
 * The amount is the Product Prices sales price for the level being upgraded
 * to, read on the server every time. The client never sends an amount, so a
 * tampered request cannot change what is charged.
 *
 * A successful payment issues the pin on its own (PaymentSettlement), so the
 * user is not left waiting for an admin after paying. An admin can instead
 * record an offline payment for a promoter who paid in cash, which unblocks
 * the Generate Pin action on the console.
 */
class PromoterPaymentController extends Controller
{
    /** Offline payment methods an admin may record. */
    private const OFFLINE_MODES = ['CASH', 'UPI', 'BANK', 'CHEQUE'];

    public function __construct(
        private OmniwareGateway $gateway,
        private PaymentSettlement $settlement,
        private PromoterPinService $pins,
    ) {
    }

    /**
     * GET promoter-payment/summary — everything the app's payment screen
     * needs: the open request, what it costs, whether it is paid, and the
     * payer details to prefill.
     */
    public function summary()
    {
        $user = User::find(Auth::id());
        $promoter = $this->latestRequest((int) $user->id);

        if (!$promoter) {
            return $this->ok([
                'request'          => null,
                'product'          => null,
                'terms_accepted'   => false,
                'payment_required' => false,
                'paid'             => false,
                'payment'          => null,
                'last_attempt'     => null,
                'payer'            => $this->payerDefaults($user),
                'gateway'          => $this->gatewayInfo(),
            ]);
        }

        $price = ProductPrice::forLevel($promoter->level);
        $paid = $this->pins->paidPayment((int) $promoter->id);
        $last = PromoterPayment::where('user_promoter_id', $promoter->id)
            ->where('is_deleted', 0)
            ->orderByDesc('id')
            ->first();

        return $this->ok([
            'request' => [
                'id'          => (int) $promoter->id,
                'level'       => (int) $promoter->level,
                'level_label' => ProductPrice::levelLabel($promoter->level),
                'status'      => (int) $promoter->status,
                'created_at'  => $promoter->created_at,
            ],
            'product' => $price ? [
                'name'  => $price->product_name,
                'price' => (float) $price->price,
                'mrp'   => $price->mrp !== null ? (float) $price->mrp : null,
            ] : null,
            // Terms come before payment; the app keeps the Pay button closed
            // until the user has accepted them.
            'terms_accepted'   => (int) $user->promoter_status === User::PROMOTER_STATUS_ACCEPTED_TERM,
            // Only an unpaid request still waiting for a pin needs paying. A
            // paid one has already been issued its pin, so it is no longer
            // PENDING and the app shows nothing to pay.
            'payment_required' => !$paid && (int) $promoter->status === UserPromoter::PIN_STATUS_PENDING,
            'paid'             => (bool) $paid,
            'payment'          => $paid ? $this->settlement->present($paid) : null,
            'last_attempt'     => $last ? $this->settlement->present($last) : null,
            'payer'            => $this->payerDefaults($user),
            'gateway'          => $this->gatewayInfo(),
        ]);
    }

    /**
     * POST promoter-payment/initiate — create the hosted payment page for the
     * user's open request and hand back the URL for the app to open.
     */
    public function initiate(Request $request)
    {
        if (!$this->gateway->isConfigured()) {
            return $this->fail('Online payment is not available right now. Please contact support.');
        }

        $validator = Validator::make($request->all(), [
            'return_origin' => 'required|url|max:255',
            // Prefilled from the profile; the app lets the user correct them
            // because the gateway rejects a request with any of them missing.
            'name'          => 'nullable|string|max:100',
            'email'         => 'nullable|email|max:150',
            'phone'         => 'nullable|digits:10',
            'city'          => 'nullable|string|max:100',
            'state'         => 'nullable|string|max:100',
            'zip_code'      => 'nullable|digits:6',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $origin = $this->allowedOrigin((string) $request->return_origin);
        if (!$origin) {
            return $this->fail('This app address is not allowed as a return address.');
        }

        $user = User::find(Auth::id());
        $promoter = $this->latestRequest((int) $user->id);
        if (!$promoter) {
            return $this->fail('You have no upgrade request waiting for payment.');
        }

        // Paid first: a paid request has already been issued its pin, so the
        // status check below would otherwise answer with something vaguer.
        if ($this->pins->isPaid((int) $promoter->id)) {
            return $this->fail('This upgrade has already been paid for.');
        }

        if ((int) $promoter->status !== UserPromoter::PIN_STATUS_PENDING) {
            return $this->fail('This request is no longer waiting for payment.');
        }

        if ((int) $user->promoter_status !== User::PROMOTER_STATUS_ACCEPTED_TERM) {
            return $this->fail('Please accept the Terms & Conditions before paying.');
        }

        $price = ProductPrice::forLevel($promoter->level);
        if (!$price || (float) $price->price <= 0) {
            return $this->fail('The price for this plan is not set up yet. Please contact support.');
        }

        // Payer details: profile first, the app's corrections on top. Built
        // and validated here so a missing profile field fails with a message
        // the user can act on, rather than as a gateway error.
        $payer = array_merge($this->payerDefaults($user), array_filter([
            'name'     => $request->input('name'),
            'email'    => $request->input('email'),
            'phone'    => $request->input('phone'),
            'city'     => $request->input('city'),
            'state'    => $request->input('state'),
            'zip_code' => $request->input('zip_code'),
        ], fn ($v) => $v !== null && $v !== ''));

        $payerCheck = Validator::make($payer, [
            'name'     => 'required|string|max:100',
            'email'    => 'required|email|max:150',
            'phone'    => 'required|digits:10',
            'city'     => 'required|string|max:100',
            'zip_code' => 'required|digits:6',
        ], [], [
            'name'     => 'name',
            'email'    => 'email address',
            'phone'    => 'mobile number',
            'city'     => 'city',
            'zip_code' => 'pincode',
        ]);
        if ($payerCheck->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Please complete these details before paying: '
                    . implode(' ', $payerCheck->errors()->all()),
                'errors'  => $payerCheck->errors(),
            ], 422);
        }

        $payment = PromoterPayment::create([
            'purpose'          => PromoterPayment::PURPOSE_PROMOTER,
            'user_id'          => $user->id,
            'user_promoter_id' => $promoter->id,
            'level'            => (int) $promoter->level,
            'order_id'         => PromoterPayment::newPromoterOrderId(),
            'amount'           => round((float) $price->price, 2),
            'currency'         => 'INR',
            'mode'             => $this->gateway->mode(),
            'status'           => PromoterPayment::STATUS_INITIATED,
            'redirect_to'      => $origin,
            'created_by'       => $user->id,
            'updated_by'       => $user->id,
        ]);

        $params = [
            'order_id'    => $payment->order_id,
            'mode'        => $payment->mode,
            'amount'      => number_format((float) $payment->amount, 2, '.', ''),
            'currency'    => 'INR',
            'description' => $price->product_name . ' - ' . ProductPrice::levelLabel($promoter->level),
            'name'        => $payer['name'],
            'email'       => $payer['email'],
            'phone'       => $payer['phone'],
            'city'        => $payer['city'],
            'state'       => $payer['state'] ?: null,
            'zip_code'    => $payer['zip_code'],
            'country'     => 'IND',
            'udf1'        => 'promoter-plan',
            'udf2'        => (string) $promoter->id,
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
            Log::warning('Promoter plan payment init failed', [
                'order_id' => $payment->order_id,
                'error'    => $e->getMessage(),
            ]);
            return $this->fail('Could not start the payment: ' . $e->getMessage());
        }

        $payment->update([
            'gateway_uuid'   => $page['uuid'],
            'payment_url'    => $page['url'],
            'url_expires_at' => $page['expiry_datetime'],
        ]);

        return $this->ok([
            'order_id'        => $payment->order_id,
            'payment_url'     => $page['url'],
            'amount'          => (float) $payment->amount,
            'expiry_datetime' => $page['expiry_datetime'],
        ], 'Payment page created');
    }

    /**
     * GET promoter-payment/status/{orderId} — what happened to one attempt.
     * Called when the app comes back from the gateway; re-checks with the
     * gateway while the row is still open, so a result that never reached us
     * is picked up here.
     */
    public function status(string $orderId)
    {
        $payment = PromoterPayment::where('order_id', $orderId)
            ->where('user_id', Auth::id())
            ->where('is_deleted', 0)
            ->first();

        if (!$payment) {
            return $this->fail('Payment not found.', 404);
        }

        $lookupError = null;
        if (!$payment->isFinal()) {
            [$payment, , $lookupError] = $this->settlement->reconcile($payment);
        }

        $promoter = $payment->user_promoter_id ? UserPromoter::find($payment->user_promoter_id) : null;

        return $this->ok([
            'payment'       => $this->settlement->present($payment),
            'gateway_error' => $lookupError,
            'request'       => $promoter ? [
                'id'     => (int) $promoter->id,
                'status' => (int) $promoter->status,
                // The pin itself is never returned here — the user reads it on
                // their pin screen, which is also where they activate it.
                'pin_ready' => (int) $promoter->status === UserPromoter::PIN_STATUS_APPROVED,
            ] : null,
        ]);
    }

    /**
     * POST promoter-payment/offline — admin records a payment collected
     * outside the gateway (cash, UPI to the company account, bank transfer),
     * which unblocks Generate Pin for that request.
     *
     * Unlike an online payment this does NOT issue the pin by itself: the
     * admin recording the money and the admin issuing the pin stay two
     * deliberate actions.
     */
    public function recordOffline(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_promoter_id' => 'required|integer|exists:user_promoters,id',
            'amount'           => 'required|numeric|min:1|max:500000',
            'payment_mode'     => 'required|string|in:' . implode(',', self::OFFLINE_MODES),
            'reference'        => 'nullable|string|max:64',
            'note'             => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $promoter = UserPromoter::where('id', $request->user_promoter_id)
            ->where('is_deleted', 0)
            ->first();
        if (!$promoter) {
            return $this->fail('Pin request not found.');
        }

        if ((int) $promoter->status !== UserPromoter::PIN_STATUS_PENDING) {
            return $this->fail('This request is no longer waiting for payment.');
        }

        if ($this->pins->isPaid((int) $promoter->id)) {
            return $this->fail('This request is already paid.');
        }

        $adminId = Auth::id();
        $payment = PromoterPayment::create([
            'purpose'          => PromoterPayment::PURPOSE_PROMOTER,
            'user_id'          => $promoter->user_id,
            'user_promoter_id' => $promoter->id,
            'level'            => (int) $promoter->level,
            'order_id'         => PromoterPayment::newPromoterOrderId(),
            'amount'           => round((float) $request->amount, 2),
            'currency'         => 'INR',
            'mode'             => $this->gateway->mode(),
            'status'           => PromoterPayment::STATUS_SUCCESS,
            'transaction_id'   => $request->reference ?: null,
            'payment_mode'     => strtoupper($request->payment_mode),
            'payment_channel'  => 'OFFLINE',
            'settled_via'      => 'offline',
            'offline_note'     => $request->note ?: null,
            'paid_at'          => now(),
            'created_by'       => $adminId,
            'updated_by'       => $adminId,
        ]);

        Log::info('Offline plan payment recorded', [
            'user_promoter_id' => $promoter->id,
            'amount'           => $payment->amount,
            'by'               => $adminId,
        ]);

        return $this->ok([
            'payment' => $this->settlement->present($payment),
        ], 'Offline payment recorded. You can now generate the pin.');
    }

    // ---------------------------------------------------------------------

    /**
     * The user's current upgrade request, whatever state it is in.
     *
     * Deliberately not filtered to PENDING: a paid request has already been
     * issued its pin and left PENDING, and the caller still needs it in hand
     * to say "already paid" rather than "no request".
     */
    private function latestRequest(int $userId): ?UserPromoter
    {
        return UserPromoter::where('user_id', $userId)
            ->where('is_deleted', 0)
            ->orderByDesc('id')
            ->first();
    }

    private function payerDefaults(User $user): array
    {
        return [
            'name'     => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: (string) $user->username,
            'email'    => (string) $user->email,
            'phone'    => (string) $user->mobile,
            'city'     => (string) ($user->city ?: $user->district),
            'state'    => (string) $user->state,
            'zip_code' => (string) $user->pin_code,
        ];
    }

    private function gatewayInfo(): array
    {
        return [
            'configured' => $this->gateway->isConfigured(),
            'mode'       => $this->gateway->mode(),
        ];
    }

    /**
     * Scheme+host(+port) of $url when its host is one of ours, else null.
     * The gateway sends the browser back to this origin, so it may never be
     * an address an attacker chose.
     */
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

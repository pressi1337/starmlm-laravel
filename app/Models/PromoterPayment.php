<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One payment attempt through Omniware (Federal Bank).
 *
 * Two purposes share the table: 'promoter' is a promoter paying for the plan
 * product of the level they are upgrading to, and 'test' is the admin
 * console's gateway test payment. One row per ATTEMPT — the gateway rejects a
 * reused order_id, so a retry is a new row. See the create migration.
 */
class PromoterPayment extends Model
{
    const PURPOSE_PROMOTER = 'promoter';
    const PURPOSE_TEST = 'test';

    const STATUS_INITIATED = 0;
    const STATUS_SUCCESS = 1;
    const STATUS_FAILED = 2;
    const STATUS_CANCELLED = 3;

    // Gateway response codes (Appendix 4 of the integration guide).
    const GATEWAY_CODE_SUCCESS = 0;
    const GATEWAY_CODE_CANCELLED = 1043;
    // Codes that mean "not final yet" — keep the row INITIATED and re-check.
    const GATEWAY_PENDING_CODES = [1006, 1030];

    protected $fillable = [
        'purpose',
        'user_id',
        'user_promoter_id',
        'level',
        'order_id',
        'amount',
        'currency',
        'mode',
        'status',
        'gateway_uuid',
        'payment_url',
        'url_expires_at',
        'redirect_to',
        'request_payload',
        'hash_verified',
        'transaction_id',
        'response_code',
        'response_message',
        'error_desc',
        'payment_mode',
        'payment_channel',
        'payment_datetime',
        'settled_via',
        'offline_note',
        'paid_at',
        'gateway_response',
        'is_active',
        'is_deleted',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'gateway_response' => 'array',
        'request_payload'  => 'array',
        'url_expires_at'   => 'datetime',
        'paid_at'          => 'datetime',
    ];

    // The raw gateway payload can carry masked card data — keep it off the wire.
    protected $hidden = ['gateway_response', 'payment_url'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isFinal(): bool
    {
        return (int) $this->status !== self::STATUS_INITIATED;
    }

    /** Map a gateway response_code to our status. */
    public static function statusFromGatewayCode($code): int
    {
        if ($code === null || $code === '') {
            return self::STATUS_INITIATED;
        }
        $code = (int) $code;
        if ($code === self::GATEWAY_CODE_SUCCESS) {
            return self::STATUS_SUCCESS;
        }
        if ($code === self::GATEWAY_CODE_CANCELLED) {
            return self::STATUS_CANCELLED;
        }
        if (in_array($code, self::GATEWAY_PENDING_CODES, true)) {
            return self::STATUS_INITIATED;
        }
        return self::STATUS_FAILED;
    }

    /** Admin test payment reference — "SUWTEST" + timestamp, <= 30 chars. */
    public static function newTestOrderId(): string
    {
        return 'SUWTEST' . now()->format('ymdHis') . random_int(1000, 9999);
    }

    /** Promoter plan payment reference — "SUWP" + timestamp, <= 30 chars. */
    public static function newPromoterOrderId(): string
    {
        return 'SUWP' . now()->format('ymdHis') . random_int(1000, 9999);
    }

    public function promoter()
    {
        return $this->belongsTo(UserPromoter::class, 'user_promoter_id');
    }

    /**
     * Where the gateway return should send the browser once this payment is
     * done. A promoter pays inside the app, an admin pays on the console, and
     * each has to land back on the screen they started from.
     */
    public function returnPath(): string
    {
        return $this->purpose === self::PURPOSE_PROMOTER
            ? '/portal/user/plan-payment'
            : '/portal/admin/payment-gateway';
    }
}

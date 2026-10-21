<?php

namespace App\Models;

use App\Services\WorldlineGateway;
use Illuminate\Database\Eloquent\Model;

/**
 * One online payment attempt through Worldline ePayments India (Paynimo).
 * Today only the admin console's test payments (purpose = 'test') create
 * these; promoter plan payments will reuse the table later. See the migration.
 */
class PromoterPayment extends Model
{
    const PURPOSE_PROMOTER = 'promoter';
    const PURPOSE_TEST = 'test';

    const STATUS_INITIATED = 0;
    const STATUS_SUCCESS = 1;
    const STATUS_FAILED = 2;
    const STATUS_CANCELLED = 3;

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

    /**
     * Map the gateway's txn_status to our status. Anything that is not a
     * documented success/failure/pending code is treated as a failure, so an
     * unknown code never passes as paid.
     */
    public static function statusFromGatewayStatus($txnStatus): int
    {
        $txnStatus = trim((string) $txnStatus);
        if ($txnStatus === '') {
            return self::STATUS_INITIATED;
        }

        return match ($txnStatus) {
            WorldlineGateway::STATUS_SUCCESS => self::STATUS_SUCCESS,
            // Pending, or an order the gateway has no record of yet — neither
            // is a final answer, so the row stays open for a re-check.
            WorldlineGateway::STATUS_PENDING,
            WorldlineGateway::STATUS_NOT_FOUND => self::STATUS_INITIATED,
            default => self::STATUS_FAILED,
        };
    }

    /** Admin test payment reference — "SUWTEST" + timestamp, <= 30 chars. */
    public static function newTestOrderId(): string
    {
        return 'SUWTEST' . now()->format('ymdHis') . random_int(1000, 9999);
    }
}

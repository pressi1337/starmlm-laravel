<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPromoter extends Model
{
    const GIFT_DELIVERY_TYPE_PICKUP = 1;
    const GIFT_DELIVERY_TYPE_DELIVERY = 2;
    const PRODUCT_DELIVERY_STATUS_PENDING = 0;
    const PRODUCT_DELIVERY_STATUS_PROCESSING = 1;
    const PRODUCT_DELIVERY_STATUS_DELIVERED = 2;
    const PRODUCT_DELIVERY_STATUS_NOT_DELIVERED = 3;
    const CUSTOMER_DELIVERY_STATUS_PENDING = 0;
    const CUSTOMER_DELIVERY_STATUS_RECEIVED = 1;
    const CUSTOMER_DELIVERY_STATUS_NOT_RECEIVED = 2;
    const PIN_STATUS_PENDING = 0;
    const PIN_STATUS_APPROVED = 1;
    const PIN_STATUS_ACTIVATED = 2;
    const PIN_STATUS_REJECTED = 3;
    const PIN_STATUS_AUTO_DELETED = 4;
    const PROMOTER_STATUS_CLOSED = 4;
    protected $fillable = [
        'user_id',
        'level',
        'gift_delivery_type',
        'gift_delivery_address',
        'wh_number',
        'pin',
        'status',
        'pin_generated_at',
        'term_raised_at',
        'terms_accepted_at',
        'auto_deleted_at',
        'deleted_reason',
        'product_delivery_status',
        'product_delivery_notes',
        'bill_path',
        'product_delivery_updated_at',
        'customer_delivery_status',
        'customer_delivery_confirmed_at',
        'activated_at',
        'created_by',
        'updated_by',
        'is_active',
        'is_deleted',
    ];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
    //
}

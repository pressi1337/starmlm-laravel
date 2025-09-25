<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPromoter extends Model
{
    const GIFT_DELIVERY_TYPE_PICKUP = 1;
    const GIFT_DELIVERY_TYPE_DELIVERY = 2;
    const PIN_STATUS_PENDING = 0;
    const PIN_STATUS_APPROVED = 1;
    const PIN_STATUS_ACTIVATED = 2;
    const PIN_STATUS_REJECTED = 3; 
    protected $fillable = [
        'user_id',
        'level',
        'gift_delivery_type',
        'gift_delivery_address',
        'wh_number',
        'pin',
        'status',
        'pin_generated_at',
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

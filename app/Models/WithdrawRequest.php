<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WithdrawRequest extends Model
{
    //
    const STATUS_PENDING = 0;
    const STATUS_PROCESSING = 1;
    const STATUS_COMPLETED = 2;
    const STATUS_REJECTED = 3;
    const WALLET_TYPE_MAIN = 1;
    const WALLET_TYPE_SCRATCH = 2;
    const WALLET_TYPE_GROW = 3;

    protected $fillable = [
        'user_id',
        'amount',
        'request_at',
        'status',
        'wallet_type',
        'reason',
        'processing_details',
        'completed_details',
        'rejected_details',
        'status_updated_at',
        'status_updated_by',
        'created_by',
        'updated_by',
        'is_deleted',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function bankDetail()
    {
        return $this->hasOne(UserBankDetail::class, 'user_id', 'user_id');
    }
}

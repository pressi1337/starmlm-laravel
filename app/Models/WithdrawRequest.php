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

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function bankDetail()
    {
        return $this->hasOne(UserBankDetail::class, 'user_id', 'user_id');
    }
}

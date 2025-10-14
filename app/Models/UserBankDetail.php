<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserBankDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'acc_no',
        'acc_name',
        'ifsc_code',
        'bank_name',
        'branch_name',
        'address',
        'is_editable'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

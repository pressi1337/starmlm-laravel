<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdminBankDetail extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'account_holder_name',
        'account_number',
        'bank_name',
        'branch_name',
        'ifsc_code',
        'whatsapp_number'
    ];
}

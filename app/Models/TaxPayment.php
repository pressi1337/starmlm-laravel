<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One payment of tax to the government. See the migration for why `amount`
 * is authoritative and cgst/sgst are an optional breakdown.
 */
class TaxPayment extends Model
{
    protected $fillable = [
        'payment_date',
        'amount',
        'cgst',
        'sgst',
        'reference_no',
        'payment_mode',
        'fy',
        'remarks',
        'created_by',
        'updated_by',
        'is_active',
        'is_deleted',
    ];

    protected $casts = [
        'amount' => 'float',
        'cgst' => 'float',
        'sgst' => 'float',
        'is_active' => 'integer',
        'is_deleted' => 'integer',
    ];

    /** Live rows only — the house soft-delete convention. */
    public function scopeLive($query)
    {
        return $query->where('is_deleted', 0);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Seller details and invoice settings for the plan-product bill.
 *
 * Single-row pattern (see the create migration): the admin maintains one
 * record and every invoice renders from it.
 */
class BillTemplate extends Model
{
    protected $fillable = [
        'company_name',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'pincode',
        'gstin',
        'pan',
        'email',
        'phone',
        'invoice_prefix',
        'country_of_supply',
        'is_active',
        'is_deleted',
        'created_by',
        'updated_by',
    ];

    /** The live template, or null when the admin hasn't set one up yet. */
    public static function current(): ?self
    {
        return self::where('is_deleted', 0)->orderBy('id', 'desc')->first();
    }

    /** "12 Main Road, Near Park, Chennai, Tamil Nadu - 600001" */
    public function fullAddress(): string
    {
        $lines = array_filter([
            $this->address_line1,
            $this->address_line2,
            $this->city,
            $this->state,
        ], fn ($p) => trim((string) $p) !== '');

        $address = implode(', ', $lines);
        if (trim((string) $this->pincode) !== '') {
            $address = $address !== '' ? $address . ' - ' . $this->pincode : (string) $this->pincode;
        }

        return $address;
    }
}

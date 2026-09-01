<?php

namespace App\Services;

use App\Models\BillTemplate;
use App\Models\PromoterBoxRequest;
use App\Models\User;

/**
 * Assembles everything the invoice template renders for one delivered
 * plan-product batch.
 *
 * All money is computed here, on the server, so the printed document can
 * never disagree with the database because of a rounding difference in JS.
 *
 * Tax model (fixed, as specified): 18% GST on the taxable amount, split
 * evenly into 9% CGST + 9% SGST. MRP is shown for reference and is
 * deliberately NOT part of the calculation.
 */
class InvoiceBuilder
{
    public const GST_PERCENT = 18.0;
    public const CGST_PERCENT = 9.0;
    public const SGST_PERCENT = 9.0;

    public function build(PromoterBoxRequest $box): array
    {
        $template = BillTemplate::current();

        $qty = max(0, (int) $box->quantity);
        $rate = round((float) $box->rate_per_qty, 2);

        // qty * rate is the taxable amount; tax is added on top.
        $taxable = round($qty * $rate, 2);
        $cgst = round($taxable * self::CGST_PERCENT / 100, 2);
        $sgst = round($taxable * self::SGST_PERCENT / 100, 2);
        $total = round($taxable + $cgst + $sgst, 2);

        return [
            'invoice_no'   => $this->formatInvoiceNo($box->invoice_no, $template),
            'invoice_date' => $box->delivered_at
                ? date('d-m-Y', strtotime((string) $box->delivered_at))
                : date('d-m-Y'),
            'billed_by'    => $this->company($template),
            'billed_to'    => $this->customer($box->user),
            'place_of_supply' => $template?->place_of_supply ?: ($template?->state ?: null),
            'country_of_supply' => $template?->country_of_supply ?: 'India',
            'items'        => [[
                'description' => $this->productName($box->level),
                'qty'         => $qty,
                'mrp'         => round((float) $box->mrp, 2),
                'sales_price' => $rate,
                'gst_percent' => self::GST_PERCENT,
                'taxable'     => $taxable,
                'cgst'        => $cgst,
                'sgst'        => $sgst,
                'amount'      => $total,
            ]],
            'totals'       => [
                'sub_total'    => $taxable,
                'taxable'      => $taxable,
                'cgst_percent' => self::CGST_PERCENT,
                'sgst_percent' => self::SGST_PERCENT,
                'cgst'         => $cgst,
                'sgst'         => $sgst,
                'total'        => $total,
                'in_words'     => self::amountInWords($total),
            ],
        ];
    }

    /** Zero-padded, with the admin's prefix if they set one. */
    private function formatInvoiceNo($number, ?BillTemplate $template = null): string
    {
        if (!$number) {
            return '-';
        }

        $padded = str_pad((string) $number, 4, '0', STR_PAD_LEFT);
        $prefix = trim((string) ($template?->invoice_prefix ?? ''));

        return $prefix !== '' ? $prefix . $padded : $padded;
    }

    /** Base Promoter (level 0) ships Energy Plus; every other level Health Plus. */
    private function productName($level): string
    {
        return (int) $level === 0 ? 'Energy Plus' : 'Health Plus';
    }

    /**
     * Seller block, from the bill template the admin maintains.
     * Empty strings rather than nulls so the template can render blanks
     * without a pile of null checks when it hasn't been filled in yet.
     */
    private function company(?BillTemplate $template): array
    {
        if (!$template) {
            return [
                'name' => '', 'address' => '', 'city' => '', 'state' => '',
                'pincode' => '', 'gstin' => '', 'pan' => '',
                'email' => '', 'phone' => '', 'configured' => false,
            ];
        }

        return [
            'name'       => (string) $template->company_name,
            'address'    => $template->fullAddress(),
            'city'       => (string) $template->city,
            'state'      => (string) $template->state,
            'pincode'    => (string) $template->pincode,
            'gstin'      => (string) $template->gstin,
            'pan'        => (string) $template->pan,
            'email'      => (string) $template->email,
            'phone'      => (string) $template->phone,
            'configured' => true,
        ];
    }

    /**
     * Buyer block. Users have no GSTIN or PAN on file and none is collected,
     * so those lines simply do not appear on this invoice.
     */
    private function customer(?User $user): array
    {
        if (!$user) {
            return ['name' => '-', 'address' => '', 'customer_id' => '', 'mobile' => ''];
        }

        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        return [
            'name'        => $name !== '' ? $name : ($user->username ?? '-'),
            'username'    => $user->username ?? '',
            'customer_id' => $user->customer_id ?? '',
            'mobile'      => $user->mobile ?? '',
            'email'       => $user->email ?? '',
            'city'        => $user->city ?? '',
            'district'    => $user->district ?? '',
            'state'       => $user->state ?? '',
            'pincode'     => $user->pin_code ?? '',
            'address'     => $this->joinAddress([
                $user->city, $user->district, $user->state, $user->pin_code,
            ]),
        ];
    }

    private function joinAddress(array $parts): string
    {
        return implode(', ', array_filter(array_map(
            fn ($p) => trim((string) $p),
            $parts
        ), fn ($p) => $p !== ''));
    }

    // ───────────────────────── amount in words ─────────────────────────

    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight',
        'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen',
        'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private const TENS = [
        '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy',
        'Eighty', 'Ninety',
    ];

    /**
     * Rupees (and paise) in words, Indian style — thousand / lakh / crore.
     *
     * Hand-rolled on purpose: PHP's NumberFormatter needs ext-intl, which is
     * not installed on this server, so relying on it would fatal at runtime.
     */
    public static function amountInWords(float $amount): string
    {
        $amount = round($amount, 2);
        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);

        $words = $rupees > 0 ? self::numberToWords($rupees) . ' Rupees' : 'Zero Rupees';

        if ($paise > 0) {
            $words .= ' and ' . self::numberToWords($paise) . ' Paise';
        }

        return $words . ' Only';
    }

    private static function numberToWords(int $number): string
    {
        if ($number === 0) {
            return 'Zero';
        }

        $out = [];

        // Indian grouping: crore, lakh, thousand, then the last three digits.
        foreach ([10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand'] as $value => $label) {
            if ($number >= $value) {
                $count = intdiv($number, $value);
                $out[] = self::numberToWords($count) . ' ' . $label;
                $number %= $value;
            }
        }

        if ($number >= 100) {
            $out[] = self::ONES[intdiv($number, 100)] . ' Hundred';
            $number %= 100;
        }

        if ($number > 0) {
            if ($number < 20) {
                $out[] = self::ONES[$number];
            } else {
                $word = self::TENS[intdiv($number, 10)];
                if ($number % 10 > 0) {
                    $word .= ' ' . self::ONES[$number % 10];
                }
                $out[] = $word;
            }
        }

        return implode(' ', $out);
    }
}

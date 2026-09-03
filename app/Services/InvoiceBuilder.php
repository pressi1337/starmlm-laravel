<?php

namespace App\Services;

use App\Models\BillTemplate;
use App\Models\ProductPrice;
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

    /**
     * Indian financial year label for a date — "26-27" for anything from
     * 1 April 2026 to 31 March 2027.
     *
     * April is the boundary: a January invoice still belongs to the year that
     * started the previous April, which is exactly the mistake a plain
     * calendar year would make.
     */
    public static function financialYear($date = null): string
    {
        $ts = $date ? strtotime((string) $date) : time();
        if ($ts === false) {
            $ts = time();
        }

        $year = (int) date('Y', $ts);
        $month = (int) date('n', $ts);
        $start = $month >= 4 ? $year : $year - 1;

        return substr((string) $start, 2) . '-' . substr((string) ($start + 1), 2);
    }

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

        // Round off to the nearest rupee.
        //
        // The rate is itself a back-calculation of a round selling price
        // (750 / 1.18 = 635.5932..., stored as 635.59), so multiplying back up
        // lands a few paise short — and the gap grows with quantity. CGST and
        // SGST stay at a true 9% each, and the difference is shown on its own
        // "Round Off" line, which is how a GST invoice is expected to handle
        // this. The customer sees the round figure the price list quotes.
        $grandTotal = round($total, 0);
        $roundOff = round($grandTotal - $total, 2);

        return [
            'invoice_no'   => $this->formatInvoiceNo($box, $template),
            'invoice_fy'   => $box->invoice_fy ?: self::financialYear($box->delivered_at),
            'invoice_date' => $box->delivered_at
                ? date('d-m-Y', strtotime((string) $box->delivered_at))
                : date('d-m-Y'),
            'billed_by'    => $this->company($template),
            'billed_to'    => $this->customer($box->user),
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
                'amount'      => $grandTotal,
            ]],
            'totals'       => [
                'sub_total'    => $taxable,
                'taxable'      => $taxable,
                'cgst_percent' => self::CGST_PERCENT,
                'sgst_percent' => self::SGST_PERCENT,
                'cgst'         => $cgst,
                'sgst'         => $sgst,
                // Sum of the parts, before rounding to the nearest rupee.
                'total'        => $total,
                'round_off'    => $roundOff,
                'grand_total'  => $grandTotal,
                // Words follow the amount actually payable.
                'in_words'     => self::amountInWords($grandTotal),
            ],
        ];
    }

    /**
     * "startup/26-27/001" — prefix, financial year, then the sequence within
     * that year. Any of the parts may be absent: with no prefix configured it
     * reads "26-27/001".
     */
    private function formatInvoiceNo(PromoterBoxRequest $box, ?BillTemplate $template = null): string
    {
        if (!$box->invoice_no) {
            return '-';
        }

        // Fall back to deriving the year for rows numbered before invoice_fy
        // existed, so an older invoice still prints a sensible number.
        $fy = $box->invoice_fy ?: self::financialYear($box->delivered_at);
        $sequence = str_pad((string) $box->invoice_no, 3, '0', STR_PAD_LEFT);

        // Trim any separator the admin typed so we never emit "startup//26-27".
        $prefix = trim((string) ($template?->invoice_prefix ?? ''));
        $prefix = rtrim($prefix, '/-');

        $parts = array_filter([$prefix, $fy, $sequence], fn ($p) => $p !== '');

        return implode('/', $parts);
    }

    /**
     * Product name from the price master, falling back to the original rule
     * (level 0 ships Energy Plus, everything else Health Plus) when no master
     * row exists. The name is cosmetic, unlike the price, so a live lookup is
     * fine here.
     */
    private function productName($level): string
    {
        $master = ProductPrice::forLevel($level);
        if ($master && trim((string) $master->product_name) !== '') {
            return $master->product_name;
        }

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

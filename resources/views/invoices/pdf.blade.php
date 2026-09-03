<?php
/**
 * Plan-product invoice, rendered to PDF by Dompdf.
 *
 * Deliberately table-based rather than flexbox/grid — Dompdf's CSS support
 * is closer to a mid-2000s browser than a modern one, and tables are the one
 * layout primitive it renders reliably. Every number comes straight from
 * $inv (InvoiceBuilder::build()); nothing is computed in this view.
 */
use App\Services\InvoiceBuilder;

$item = $inv['items'][0] ?? [];
$totals = $inv['totals'] ?? [];
$by = $inv['billed_by'] ?? [];
$to = $inv['billed_to'] ?? [];
$money = fn ($v) => InvoiceBuilder::formatMoney((float) ($v ?? 0));
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 28px 34px; }
    body {
        /* DejaVu Sans, not Helvetica: Helvetica is a non-embedded PDF core
           font with no rupee glyph (U+20B9), so every amount rendered as
           "?750.00". DejaVu ships with dompdf, covers the rupee sign, and
           gets subset-embedded. */
        font-family: "DejaVu Sans", sans-serif;
        color: #1f2937;
        font-size: 11px;
        margin: 0;
    }
    table { border-collapse: collapse; width: 100%; }
    .header-table td { vertical-align: top; }
    .h1 { font-size: 26px; font-weight: bold; color: #111827; margin: 0 0 8px 0; }
    .meta-table td { padding: 1px 0; font-size: 10px; }
    .meta-label { color: #6b7280; padding-right: 18px; }
    .meta-value { font-weight: bold; color: #111827; }
    .logo-cell { text-align: right; }
    .logo-cell img { max-width: 130px; max-height: 70px; }

    .party-table { margin-top: 18px; }
    .party-table td { width: 50%; vertical-align: top; padding: 12px; background: #f9fafb; }
    .party-table td.spacer { width: 12px; background: transparent; padding: 0; }
    .party-title { font-size: 11px; font-weight: bold; color: #374151; margin: 0 0 6px 0; }
    .party-name { font-size: 12px; font-weight: bold; color: #111827; margin: 0 0 3px 0; }
    .party-line { font-size: 9.5px; color: #4b5563; line-height: 1.5; margin: 0 0 2px 0; }
    .party-line b { color: #374151; }

    .supply-line { margin-top: 10px; font-size: 9.5px; color: #6b7280; }
    .supply-line b { color: #374151; }

    .items-table { margin-top: 14px; }
    .items-table th {
        background: #374151; color: #ffffff; font-size: 9.5px;
        padding: 7px 6px; text-align: left; font-weight: bold;
    }
    .items-table th.num, .items-table td.num { text-align: right; }
    .items-table th.center, .items-table td.center { text-align: center; }
    .items-table td {
        padding: 8px 6px; font-size: 10px; border-bottom: 1px solid #e5e7eb;
        color: #1f2937;
    }
    .items-table td.amount { font-weight: bold; }

    .totals-wrap { margin-top: 16px; }
    .totals-table { width: 260px; float: right; }
    .totals-table td { padding: 3px 0; font-size: 10.5px; }
    .totals-table td.label { color: #4b5563; }
    .totals-table td.value { text-align: right; font-weight: bold; color: #111827; }
    .totals-table tr.grand td { padding-top: 8px; border-top: 1.5px solid #9ca3af; }
    .totals-table tr.grand td.label { font-size: 13px; font-weight: bold; color: #111827; }
    .totals-table tr.grand td.value { font-size: 16px; }

    .words-wrap { clear: both; margin-top: 14px; padding-top: 8px; border-top: 1px solid #e5e7eb; }
    .words-label { font-size: 9px; color: #6b7280; margin: 0 0 2px 0; }
    .words-value { font-size: 11px; font-weight: bold; color: #111827; margin: 0; }

    .footer { clear: both; margin-top: 26px; padding-top: 10px; border-top: 1px solid #f3f4f6; font-size: 9px; color: #6b7280; }
    .footer b { color: #374151; }
</style>
</head>
<body>

    <table class="header-table">
        <tr>
            <td>
                <p class="h1">Invoice</p>
                <table class="meta-table">
                    <tr>
                        <td class="meta-label">Invoice#</td>
                        <td class="meta-value">{{ $inv['invoice_no'] }}</td>
                    </tr>
                    <tr>
                        <td class="meta-label">Invoice Date</td>
                        <td class="meta-value">{{ $inv['invoice_date'] }}</td>
                    </tr>
                </table>
            </td>
            <td class="logo-cell">
                @if($logo)
                    <img src="{{ $logo }}" alt="{{ $by['name'] ?? 'Company' }}">
                @endif
            </td>
        </tr>
    </table>

    <table class="party-table">
        <tr>
            <td>
                <p class="party-title">Billed by</p>
                <p class="party-name">{{ $by['name'] ?? '' }}</p>
                @if(!empty($by['address']))
                    <p class="party-line">{{ $by['address'] }}</p>
                @endif
                @if(!empty($by['gstin']))
                    <p class="party-line"><b>GSTIN</b> {{ $by['gstin'] }}</p>
                @endif
                @if(!empty($by['pan']))
                    <p class="party-line"><b>PAN</b> {{ $by['pan'] }}</p>
                @endif
            </td>
            <td class="spacer"></td>
            <td>
                <p class="party-title">Billed to</p>
                <p class="party-name">{{ $to['name'] ?? '' }}</p>
                @if(!empty($to['address']))
                    <p class="party-line">{{ $to['address'] }}</p>
                @endif
                @if(!empty($to['customer_id']))
                    <p class="party-line"><b>Customer ID</b> {{ $to['customer_id'] }}</p>
                @endif
                @if(!empty($to['mobile']))
                    <p class="party-line"><b>Mobile</b> {{ $to['mobile'] }}</p>
                @endif
            </td>
        </tr>
    </table>

    <p class="supply-line">Country of Supply <b>{{ $inv['country_of_supply'] ?? '' }}</b></p>

    <table class="items-table">
        <thead>
            <tr>
                <th>Item description</th>
                <th class="center">Qty</th>
                <th class="num">MRP</th>
                <th class="num">Sales Price</th>
                <th class="center">GST</th>
                <th class="num">Taxable</th>
                <th class="num">SGST</th>
                <th class="num">CGST</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1. {{ $item['description'] ?? '' }}</td>
                <td class="center">{{ $item['qty'] ?? 0 }}</td>
                <td class="num">&#8377;{{ $money($item['mrp'] ?? 0) }}</td>
                <td class="num">&#8377;{{ $money($item['sales_price'] ?? 0) }}</td>
                <td class="center">{{ $item['gst_percent'] ?? 0 }}%</td>
                <td class="num">&#8377;{{ $money($item['taxable'] ?? 0) }}</td>
                <td class="num">&#8377;{{ $money($item['sgst'] ?? 0) }}</td>
                <td class="num">&#8377;{{ $money($item['cgst'] ?? 0) }}</td>
                <td class="num amount">&#8377;{{ $money($item['amount'] ?? 0) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="totals-wrap">
        <table class="totals-table">
            <tr>
                <td class="label">Sub Total</td>
                <td class="value">&#8377;{{ $money($totals['sub_total'] ?? 0) }}</td>
            </tr>
            <tr>
                <td class="label">Taxable Amount</td>
                <td class="value">&#8377;{{ $money($totals['taxable'] ?? 0) }}</td>
            </tr>
            <tr>
                <td class="label">CGST ({{ $totals['cgst_percent'] ?? 9 }}%)</td>
                <td class="value">&#8377;{{ $money($totals['cgst'] ?? 0) }}</td>
            </tr>
            <tr>
                <td class="label">SGST ({{ $totals['sgst_percent'] ?? 9 }}%)</td>
                <td class="value">&#8377;{{ $money($totals['sgst'] ?? 0) }}</td>
            </tr>
            @if(round((float) ($totals['round_off'] ?? 0), 2) !== 0.0)
                <tr>
                    <td class="label">Round Off</td>
                    <td class="value">
                        {{ (float) $totals['round_off'] > 0 ? '+' : '-' }}&#8377;{{ $money(abs((float) $totals['round_off'])) }}
                    </td>
                </tr>
            @endif
            <tr class="grand">
                <td class="label">Total</td>
                <td class="value">&#8377;{{ $money($totals['grand_total'] ?? $totals['total'] ?? 0) }}</td>
            </tr>
        </table>
    </div>

    <div class="words-wrap">
        <p class="words-label">Invoice Total (in words)</p>
        <p class="words-value">{{ $totals['in_words'] ?? '' }}</p>
    </div>

    @if(!empty($by['email']) || !empty($by['phone']))
        <p class="footer">
            For any enquiries
            @if(!empty($by['email']))
                , email us at <b>{{ $by['email'] }}</b>
            @endif
            @if(!empty($by['phone']))
                or call <b>{{ $by['phone'] }}</b>
            @endif
            .
        </p>
    @endif

</body>
</html>

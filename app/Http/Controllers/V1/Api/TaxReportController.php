<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\BillTemplate;
use App\Models\ProductPrice;
use App\Models\PromoterBoxRequest;
use App\Services\InvoiceBuilder;
use App\Traits\HandlesJson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Tax tracking: every invoice that has been issued, with its GST breakdown,
 * plus running totals across the whole filtered set.
 *
 * Read-only. It never assigns an invoice number or touches a box request — a
 * report must not have side effects.
 *
 * A number is issued when the delivery is confirmed (BoxRequestController's
 * markDelivered and adminMarkDelivered both call assignInvoiceNumber), so in
 * normal operation every row here already has one. The exception is a batch
 * delivered BEFORE numbering existed: those are numbered lazily, the first
 * time their invoice is opened. `awaiting_number` in the summary counts them,
 * so the tax figures are never silently short of a row that has no number
 * yet.
 *
 * Every rupee comes from InvoiceBuilder::money(), the same function that
 * prints the customer's bill, so these totals cannot drift from what was
 * actually billed.
 */
class TaxReportController extends Controller
{
    use HandlesJson;

    protected array $sortable = ['delivered_at', 'invoice_no', 'quantity', 'level', 'id'];
    protected array $filterable = ['level', 'invoice_fy'];

    /**
     * An invoice exists for a batch that is DELIVERED and had its price
     * recorded at dispatch — exactly the eligibility
     * BoxRequestController::resolveInvoiceBox enforces. Batches dispatched
     * before pricing was captured have no rate and can never be invoiced, so
     * they are not tax records and are excluded.
     */
    private function baseQuery(Request $request)
    {
        $query = PromoterBoxRequest::with('user')
            ->where('is_deleted', 0)
            ->where('status', PromoterBoxRequest::STATUS_DELIVERED)
            ->whereNotNull('rate_per_qty');

        $search_param = $this->safeJsonDecode($request->query('search_param', '{}'));
        foreach (($search_param ?? []) as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            if ($key === 'fromdate' || $key === 'todate') {
                continue;
            }
            if (in_array($key, $this->filterable, true)) {
                is_array($value)
                    ? $query->whereIn($key, $value)
                    : $query->where($key, $value);
            }
        }

        // Date range runs on delivered_at — the invoice date.
        $fromDate = $search_param['fromdate'] ?? null;
        $toDate = $search_param['todate'] ?? null;
        if ($fromDate && $toDate) {
            $query->whereBetween('delivered_at', [$fromDate . ' 00:00:00', $toDate . ' 23:59:59']);
        } elseif ($fromDate) {
            $query->whereDate('delivered_at', '>=', $fromDate);
        } elseif ($toDate) {
            $query->whereDate('delivered_at', '<=', $toDate);
        }

        $search_term = trim((string) $request->query('search', ''));
        if ($search_term !== '') {
            $query->where(function ($q) use ($search_term) {
                $q->where('invoice_no', 'LIKE', '%' . $search_term . '%')
                    ->orWhereHas('user', function ($uq) use ($search_term) {
                        $uq->where('username', 'LIKE', '%' . $search_term . '%')
                            ->orWhere('mobile', 'LIKE', '%' . $search_term . '%');
                    });
            });
        }

        return $query;
    }

    public function index(Request $request)
    {
        try {
            $template = BillTemplate::current();

            // Product name per level, resolved once instead of per row.
            $names = [];
            foreach (ProductPrice::where('is_deleted', 0)->get() as $price) {
                $names[(int) $price->level] = trim((string) $price->product_name);
            }
            $nameFor = function (int $level) use ($names): string {
                if (!empty($names[$level])) {
                    return $names[$level];
                }
                return $level === 0 ? 'Energy Plus' : 'Health Plus';
            };

            $sort_column = $request->query('sort_column', 'delivered_at');
            if (!in_array($sort_column, $this->sortable, true)) {
                $sort_column = 'delivered_at';
            }
            $sort_direction = strtoupper($request->query('sort_direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

            // ── Totals over the WHOLE filtered set, not just this page ──
            // Walked in chunks so a large range cannot exhaust memory, and
            // summed from the same money() the invoice uses.
            $summary = [
                'invoices' => 0, 'awaiting_number' => 0, 'qty' => 0,
                'gross' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0,
                'tax' => 0.0, 'round_off' => 0.0, 'grand_total' => 0.0,
            ];
            $products = [];

            $this->baseQuery($request)->select(['id', 'level', 'quantity', 'rate_per_qty', 'invoice_no'])
                ->chunkById(500, function ($rows) use (&$summary, &$products, $nameFor) {
                    foreach ($rows as $row) {
                        $m = InvoiceBuilder::money($row->quantity, $row->rate_per_qty);
                        $summary['invoices']++;
                        if ($row->invoice_no === null) {
                            $summary['awaiting_number']++;
                        }
                        $summary['qty'] += $m['qty'];
                        $summary['gross'] += $m['taxable'];
                        $summary['cgst'] += $m['cgst'];
                        $summary['sgst'] += $m['sgst'];
                        $summary['tax'] += $m['tax'];
                        $summary['round_off'] += $m['round_off'];
                        $summary['grand_total'] += $m['grand_total'];

                        // Per-product delivered tally for the top cards.
                        $name = $nameFor((int) $row->level);
                        if (!isset($products[$name])) {
                            $products[$name] = ['product' => $name, 'batches' => 0, 'qty' => 0, 'grand_total' => 0.0];
                        }
                        $products[$name]['batches']++;
                        $products[$name]['qty'] += $m['qty'];
                        $products[$name]['grand_total'] += $m['grand_total'];
                    }
                });

            foreach (['gross', 'cgst', 'sgst', 'tax', 'round_off', 'grand_total'] as $k) {
                $summary[$k] = round($summary[$k], 2);
            }
            foreach ($products as &$p) {
                $p['grand_total'] = round($p['grand_total'], 2);
            }
            unset($p);
            // Energy Plus first, then the rest alphabetically — stable order
            // so the cards do not jump around between loads.
            uasort($products, function ($a, $b) {
                if ($a['product'] === 'Energy Plus') return -1;
                if ($b['product'] === 'Energy Plus') return 1;
                return strcmp($a['product'], $b['product']);
            });

            // ── The page of rows ──
            $page_size = max(0, (int) $request->query('page_size', 10));
            $page_number = max(1, (int) $request->query('page_number', 1));

            $rowsQuery = $this->baseQuery($request)
                ->orderBy($sort_column, $sort_direction)
                // Stable tiebreak: delivered_at ties are common when a batch
                // is marked delivered in bulk, and without this a paginated
                // row can repeat or vanish between pages.
                ->orderBy('id', $sort_direction);

            $total_records = $summary['invoices'];
            if ($page_size > 0) {
                $rowsQuery->skip(($page_number - 1) * $page_size)->take($page_size);
            }

            $data = $rowsQuery->get()->map(function ($box) use ($nameFor, $template) {
                $m = InvoiceBuilder::money($box->quantity, $box->rate_per_qty);

                return [
                    'id' => $box->id,
                    // Formatted by InvoiceBuilder so it is character-for-character
                    // the number printed on the customer's bill.
                    'invoice_no' => $box->invoice_no !== null
                        ? InvoiceBuilder::formatNumber(
                            $box->invoice_fy ?: InvoiceBuilder::financialYear($box->delivered_at),
                            $box->invoice_no,
                            $template
                        )
                        : null,
                    'invoice_fy' => $box->invoice_fy ?: InvoiceBuilder::financialYear($box->delivered_at),
                    'invoice_date' => $box->delivered_at
                        ? date('d-m-Y', strtotime((string) $box->delivered_at))
                        : null,
                    'username' => $box->user->username ?? null,
                    'mobile' => $box->user->mobile ?? null,
                    'level' => (int) $box->level,
                    'product' => $nameFor((int) $box->level),
                    'qty' => $m['qty'],
                    'rate_per_qty' => $m['rate'],
                    'gross_total' => $m['taxable'],
                    'cgst' => $m['cgst'],
                    'sgst' => $m['sgst'],
                    'tax_total' => $m['tax'],
                    'round_off' => $m['round_off'],
                    'grand_total' => $m['grand_total'],
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data' => $data,
                'summary' => $summary,
                'products' => array_values($products),
                'rates' => [
                    'gst_percent' => InvoiceBuilder::GST_PERCENT,
                    'cgst_percent' => InvoiceBuilder::CGST_PERCENT,
                    'sgst_percent' => InvoiceBuilder::SGST_PERCENT,
                ],
                'pageInfo' => [
                    'page_size' => $page_size,
                    'page_number' => $page_number,
                    'total_pages' => $page_size > 0 ? (int) ceil($total_records / $page_size) : 1,
                    'total_records' => $total_records,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Tax report failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }
}

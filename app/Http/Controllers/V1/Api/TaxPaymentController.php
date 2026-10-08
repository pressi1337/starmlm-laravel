<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\PromoterBoxRequest;
use App\Models\TaxPayment;
use App\Services\InvoiceBuilder;
use App\Traits\HandlesJson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Tax remitted to the government — the paid side of the Tax report.
 *
 * The collected side is derived from invoices and cannot be edited. This side
 * is entered by hand, so it is full CRUD. The balance the screen shows is
 * simply collected minus paid.
 *
 * Super-admin only, like the rest of the Tax report.
 */
class TaxPaymentController extends Controller
{
    use HandlesJson;

    protected array $sortable = ['payment_date', 'amount', 'id', 'created_at'];

    private function rules(): array
    {
        return [
            'payment_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'cgst' => 'nullable|numeric|min:0',
            'sgst' => 'nullable|numeric|min:0',
            'reference_no' => 'nullable|string|max:100',
            'payment_mode' => 'nullable|string|max:50',
            'remarks' => 'nullable|string|max:1000',
        ];
    }

    private function messages(): array
    {
        return [
            'payment_date.required' => 'Payment date is required',
            'amount.required' => 'Amount is required',
            'amount.min' => 'Amount must be more than zero',
        ];
    }

    /**
     * A CGST/SGST split is optional, but if it is given it has to add up to
     * the amount — otherwise the breakdown would quietly contradict the total
     * it sits next to.
     */
    private function splitError(Request $request): ?string
    {
        $cgst = round((float) $request->input('cgst', 0), 2);
        $sgst = round((float) $request->input('sgst', 0), 2);
        if ($cgst <= 0 && $sgst <= 0) {
            return null; // no split given, nothing to reconcile
        }

        $amount = round((float) $request->input('amount', 0), 2);
        if (abs(($cgst + $sgst) - $amount) > 0.009) {
            return 'CGST + SGST must equal the amount ('
                . number_format($cgst, 2) . ' + ' . number_format($sgst, 2)
                . ' = ' . number_format($cgst + $sgst, 2)
                . ', amount is ' . number_format($amount, 2) . ')';
        }

        return null;
    }

    public function index(Request $request)
    {
        try {
            $search_param = $this->safeJsonDecode($request->query('search_param', '{}'));
            $query = TaxPayment::where('is_deleted', 0);

            if (!empty($search_param['fy'])) {
                $query->where('fy', $search_param['fy']);
            }
            $fromDate = $search_param['fromdate'] ?? null;
            $toDate = $search_param['todate'] ?? null;
            if ($fromDate && $toDate) {
                $query->whereBetween('payment_date', [$fromDate, $toDate]);
            } elseif ($fromDate) {
                $query->whereDate('payment_date', '>=', $fromDate);
            } elseif ($toDate) {
                $query->whereDate('payment_date', '<=', $toDate);
            }

            $search_term = trim((string) $request->query('search', ''));
            if ($search_term !== '') {
                $query->where(function ($q) use ($search_term) {
                    $q->where('reference_no', 'LIKE', '%' . $search_term . '%')
                        ->orWhere('payment_mode', 'LIKE', '%' . $search_term . '%')
                        ->orWhere('remarks', 'LIKE', '%' . $search_term . '%');
                });
            }

            // Totals over the whole filtered set, before paging.
            $totals = (clone $query)
                ->selectRaw('COUNT(*) AS payments, COALESCE(SUM(amount),0) AS paid,
                             COALESCE(SUM(cgst),0) AS cgst, COALESCE(SUM(sgst),0) AS sgst')
                ->first();

            $sort_column = $request->query('sort_column', 'payment_date');
            if (!in_array($sort_column, $this->sortable, true)) {
                $sort_column = 'payment_date';
            }
            $sort_direction = strtoupper($request->query('sort_direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

            $page_size = max(0, (int) $request->query('page_size', 10));
            $page_number = max(1, (int) $request->query('page_number', 1));

            $total_records = (int) $totals->payments;

            $rows = $query->orderBy($sort_column, $sort_direction)
                // Stable tiebreak — several payments share a date routinely.
                ->orderBy('id', $sort_direction);
            if ($page_size > 0) {
                $rows->skip(($page_number - 1) * $page_size)->take($page_size);
            }

            $data = $rows->get()->map(function ($p) {
                return [
                    'id' => $p->id,
                    'payment_date' => $p->payment_date,
                    'payment_date_formatted' => $p->payment_date
                        ? date('d-m-Y', strtotime((string) $p->payment_date))
                        : null,
                    'amount' => round((float) $p->amount, 2),
                    'cgst' => round((float) $p->cgst, 2),
                    'sgst' => round((float) $p->sgst, 2),
                    'reference_no' => $p->reference_no,
                    'payment_mode' => $p->payment_mode,
                    'fy' => $p->fy,
                    'remarks' => $p->remarks,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data' => $data,
                'summary' => $this->summary($search_param),
                'paid_totals' => [
                    'payments' => (int) $totals->payments,
                    'paid' => round((float) $totals->paid, 2),
                    'cgst' => round((float) $totals->cgst, 2),
                    'sgst' => round((float) $totals->sgst, 2),
                ],
                'pageInfo' => [
                    'page_size' => $page_size,
                    'page_number' => $page_number,
                    'total_pages' => $page_size > 0 ? (int) ceil($total_records / max(1, $page_size)) : 1,
                    'total_records' => $total_records,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Tax payment index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Collected vs paid vs balance, over the same date window the caller is
     * looking at.
     *
     * Collected is summed from invoices with InvoiceBuilder::money(), the same
     * function that printed each bill, so this can never disagree with the
     * invoice tab.
     */
    private function summary(array $search_param): array
    {
        $fromDate = $search_param['fromdate'] ?? null;
        $toDate = $search_param['todate'] ?? null;

        $invoices = PromoterBoxRequest::where('is_deleted', 0)
            ->where('status', PromoterBoxRequest::STATUS_DELIVERED)
            ->whereNotNull('rate_per_qty');
        $payments = TaxPayment::where('is_deleted', 0);

        if ($fromDate && $toDate) {
            $invoices->whereBetween('delivered_at', [$fromDate . ' 00:00:00', $toDate . ' 23:59:59']);
            $payments->whereBetween('payment_date', [$fromDate, $toDate]);
        } elseif ($fromDate) {
            $invoices->whereDate('delivered_at', '>=', $fromDate);
            $payments->whereDate('payment_date', '>=', $fromDate);
        } elseif ($toDate) {
            $invoices->whereDate('delivered_at', '<=', $toDate);
            $payments->whereDate('payment_date', '<=', $toDate);
        }

        $collected = 0.0;
        $collectedCgst = 0.0;
        $collectedSgst = 0.0;
        $invoices->select(['id', 'quantity', 'rate_per_qty'])
            ->chunkById(500, function ($rows) use (&$collected, &$collectedCgst, &$collectedSgst) {
                foreach ($rows as $row) {
                    $m = InvoiceBuilder::money($row->quantity, $row->rate_per_qty);
                    $collected += $m['tax'];
                    $collectedCgst += $m['cgst'];
                    $collectedSgst += $m['sgst'];
                }
            });

        $paid = round((float) $payments->sum('amount'), 2);
        $collected = round($collected, 2);

        return [
            'tax_collected' => $collected,
            'tax_collected_cgst' => round($collectedCgst, 2),
            'tax_collected_sgst' => round($collectedSgst, 2),
            'tax_paid' => $paid,
            // Positive => still owed. Negative => paid more than collected.
            'balance' => round($collected - $paid, 2),
        ];
    }

    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), $this->rules(), $this->messages());
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
            if ($error = $this->splitError($request)) {
                return response()->json(['errors' => ['cgst' => [$error]]], 422);
            }

            $payment = new TaxPayment();
            $this->fill($payment, $request);
            $payment->created_by = Auth::id();
            $payment->save();

            return response()->json(['success' => true, 'message' => 'Tax payment recorded', 'status' => 200], 200);
        } catch (\Throwable $e) {
            Log::error('Tax payment store failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    public function show($id)
    {
        $payment = TaxPayment::where('id', $id)->where('is_deleted', 0)->first();
        if (!$payment) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $payment], 200);
    }

    public function update(Request $request, $id)
    {
        try {
            $payment = TaxPayment::where('id', $id)->where('is_deleted', 0)->first();
            if (!$payment) {
                return response()->json(['success' => false, 'message' => 'Not found'], 404);
            }

            $validator = Validator::make($request->all(), $this->rules(), $this->messages());
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
            if ($error = $this->splitError($request)) {
                return response()->json(['errors' => ['cgst' => [$error]]], 422);
            }

            $this->fill($payment, $request);
            $payment->updated_by = Auth::id();
            $payment->save();

            return response()->json(['success' => true, 'message' => 'Tax payment updated', 'status' => 200], 200);
        } catch (\Throwable $e) {
            Log::error('Tax payment update failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $payment = TaxPayment::where('id', $id)->where('is_deleted', 0)->first();
            if (!$payment) {
                return response()->json(['success' => false, 'message' => 'Not found'], 404);
            }

            // Soft delete, per the house convention — a removed payment stays
            // on the row so the history is auditable.
            $payment->is_deleted = 1;
            $payment->updated_by = Auth::id();
            $payment->save();

            return response()->json(['success' => true, 'message' => 'Tax payment deleted', 'status' => 200], 200);
        } catch (\Throwable $e) {
            Log::error('Tax payment destroy failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    private function fill(TaxPayment $payment, Request $request): void
    {
        $payment->payment_date = date('Y-m-d', strtotime((string) $request->input('payment_date')));
        $payment->amount = round((float) $request->input('amount'), 2);
        $payment->cgst = round((float) $request->input('cgst', 0), 2);
        $payment->sgst = round((float) $request->input('sgst', 0), 2);
        $payment->reference_no = $request->input('reference_no') ?: null;
        $payment->payment_mode = $request->input('payment_mode') ?: null;
        $payment->remarks = $request->input('remarks') ?: null;
        // Derived, never taken from the client, so it always matches the date.
        $payment->fy = InvoiceBuilder::financialYear($payment->payment_date);
        $payment->is_active = 1;
        $payment->is_deleted = 0;
    }
}

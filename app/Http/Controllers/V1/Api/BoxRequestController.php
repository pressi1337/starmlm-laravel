<?php

namespace App\Http\Controllers\V1\Api;

use App\Exports\BoxRequestExport;
use App\Http\Controllers\Controller;
use App\Traits\HandlesJson;
use App\Models\AppSetting;
use App\Models\ProductPrice;
use App\Models\PromoterBoxRequest;
use App\Models\User;
use App\Models\UserPromoter;
use App\Services\InvoiceBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class BoxRequestController extends Controller
{
    use HandlesJson;

    protected array $sortable = [
        'created_at', 'status', 'level', 'quantity', 'updated_at',
        // Lifecycle dates — each column in the list is sortable.
        'requested_at', 'sent_at', 'delivered_at', 'not_received_at',
    ];
    protected array $filterable = [
        'status', 'level', 'user_id', 'fromdate', 'todate',
        // Independent date ranges, one pair per lifecycle stage.
        'requested_from', 'requested_to',
        'sent_from', 'sent_to',
        'delivered_from', 'delivered_to',
        'not_received_from', 'not_received_to',
    ];

    /** search_param key => the column its from/to pair filters on. */
    private const DATE_RANGE_FILTERS = [
        'requested' => 'requested_at',
        'sent'      => 'sent_at',
        'delivered' => 'delivered_at',
        'not_received' => 'not_received_at',
    ];

    /* ===================== USER SIDE ===================== */

    /**
     * The authenticated user's box list + how many they can still request at
     * their current level (drives the "Request Boxes" option in the PWA).
     */
    public function userBoxRequests()
    {
        try {
            $userId = Auth::id();
            $user = User::find($userId);
            $level = ($user && $user->current_promoter_level !== null)
                ? (int) $user->current_promoter_level
                : null;

            $invoicesOn = $this->invoiceEnabled();

            $list = PromoterBoxRequest::where('user_id', $userId)
                ->where('is_deleted', 0)
                ->orderBy('id', 'desc')
                ->get()
                ->map(function ($b) use ($invoicesOn) {
                    $b->status_label = $b->statusLabel();
                    $b->created_at_formatted = $b->created_at ? $b->created_at->format('d-m-Y h:i A') : '-';

                    // Dispatch details, so the user can see how it was sent
                    // and chase a courier if it hasn't arrived.
                    $b->dispatch_label = $b->dispatchLabel();
                    $b->dispatch_summary = $b->dispatchSummary();
                    $b->courier_name = $b->courier_name;
                    $b->courier_number = $b->courier_number;
                    $b->collected_date_formatted = $b->collected_date
                        ? date('d-m-Y', strtotime((string) $b->collected_date))
                        : null;
                    $b->sent_at_formatted = $b->sent_at
                        ? date('d-m-Y h:i A', strtotime((string) $b->sent_at))
                        : null;
                    $b->not_received_at_formatted = $b->not_received_at
                        ? date('d-m-Y h:i A', strtotime((string) $b->not_received_at))
                        : null;
                    // Drives the "Download Invoice" button. The admin can
                    // switch invoices off entirely from App Settings.
                    $b->has_invoice = $invoicesOn
                        && (int) $b->status === PromoterBoxRequest::STATUS_DELIVERED
                        && $b->rate_per_qty !== null;
                    return $b;
                });

            $rules = $level !== null ? PromoterBoxRequest::rulesForLevel($level) : null;
            $options = $level !== null ? PromoterBoxRequest::selectableOptions($userId, $level) : [];

            $meta = [
                'level'       => $level,
                'cap'         => $rules['cap'] ?? 0,
                'received'    => $level !== null ? PromoterBoxRequest::receivedQuantity($userId, $level) : 0,
                'remaining'   => $level !== null ? PromoterBoxRequest::remainingForLevel($userId, $level) : 0,
                'options'     => $options,
                // Only manual levels (3/4) with remaining capacity can request more.
                'can_request' => $rules !== null && empty($rules['auto']) && !empty($options),
                // Prefill the request form's contact number, like the pin screen.
                'mobile'      => $user?->mobile ?? '',
            ];

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data'    => $list,
                'meta'    => $meta,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('BoxRequest userBoxRequests failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Standalone "request more boxes" action for manual levels (3/4), used
     * after activation. Validates the chosen quantity against the remaining
     * cap and captures its own delivery details.
     */
    public function requestBoxes(Request $request)
    {
        try {
            $userId = Auth::id();
            $user = User::find($userId);
            if (!$user || $user->current_promoter_level === null) {
                return response()->json(['success' => false, 'message' => 'You are not an active promoter'], 400);
            }
            $level = (int) $user->current_promoter_level;

            $rules = PromoterBoxRequest::rulesForLevel($level);
            if (!$rules || !empty($rules['auto'])) {
                return response()->json(['success' => false, 'message' => 'No additional boxes can be requested at your level'], 400);
            }

            $options = PromoterBoxRequest::selectableOptions($userId, $level);
            if (empty($options)) {
                return response()->json(['success' => false, 'message' => 'You have reached your box limit for this level'], 400);
            }

            $validator = Validator::make($request->all(), [
                'quantity'         => ['required', 'integer', 'in:' . implode(',', $options)],
                'delivery_type'    => 'required|integer|in:1,2',
                'delivery_address' => 'required_if:delivery_type,2|nullable|string|max:500',
                'pickup_date'      => 'required_if:delivery_type,1|nullable|date',
                'contact_number'   => 'required|string|max:50',
            ], [
                'quantity.in' => 'Please choose a valid box quantity (' . implode('/', $options) . ').',
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            // Link to the current activated promoter cycle when available.
            $promoter = UserPromoter::where('user_id', $userId)
                ->where('status', UserPromoter::PIN_STATUS_ACTIVATED)
                ->orderBy('level', 'DESC')
                ->first();

            $box = new PromoterBoxRequest();
            $box->user_id = $userId;
            $box->user_promoter_id = $promoter->id ?? null;
            $box->level = $level;
            $box->quantity = (int) $request->quantity;
            $box->delivery_type = (int) $request->delivery_type;
            $box->delivery_address = $request->delivery_address;
            $box->pickup_date = $request->pickup_date ?: null;
            $box->contact_number = $request->contact_number;
            $box->status = PromoterBoxRequest::STATUS_REQUESTED;
            $box->requested_at = now();
            $box->created_by = $userId;
            $box->updated_by = $userId;
            $box->save();

            return response()->json(['success' => true, 'message' => 'Boxes requested successfully', 'data' => $box], 200);
        } catch (\Throwable $e) {
            Log::error('BoxRequest requestBoxes failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /**
     * User confirms receipt — only valid once the admin has marked it Sent.
     */
    public function markDelivered(Request $request)
    {
        try {
            $userId = Auth::id();
            $box = PromoterBoxRequest::where('id', $request->id)
                ->where('user_id', $userId)
                ->where('is_deleted', 0)
                ->first();
            if (!$box) {
                return response()->json(['success' => false, 'message' => 'Box request not found'], 400);
            }
            if ((int) $box->status !== PromoterBoxRequest::STATUS_SENT) {
                return response()->json(['success' => false, 'message' => 'This box has not been sent yet'], 400);
            }

            $box->status = PromoterBoxRequest::STATUS_DELIVERED;
            $box->delivered_at = now();
            $box->updated_by = $userId;
            $box->save();

            // Delivery is what makes the invoice available, so the number is
            // issued here.
            $this->assignInvoiceNumber($box);

            return response()->json(['success' => true, 'message' => 'Marked as delivered', 'data' => $box], 200);
        } catch (\Throwable $e) {
            Log::error('BoxRequest markDelivered failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /* ===================== ADMIN SIDE ===================== */

    /**
     * Admin listing of box requests (paginated/filterable like the other admin
     * index endpoints). Route is gated by the pin_requests permission.
     */
    /**
     * The user reports that a dispatched batch never arrived.
     *
     * Only valid from Sent: a batch still Requested hasn't gone anywhere, one
     * already Delivered was confirmed by the user themselves, and reporting the
     * same batch twice in a row is pointless. The admin picks it up from the
     * Not Received tab and either re-sends it or marks it delivered.
     */
    public function markNotReceived(Request $request)
    {
        try {
            $userId = Auth::id();
            $box = PromoterBoxRequest::where('id', $request->id)
                ->where('user_id', $userId)
                ->where('is_deleted', 0)
                ->first();
            if (!$box) {
                return response()->json(['success' => false, 'message' => 'Box request not found'], 400);
            }
            if ((int) $box->status !== PromoterBoxRequest::STATUS_SENT) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only a dispatched product can be reported as not received',
                ], 400);
            }

            $box->status = PromoterBoxRequest::STATUS_NOT_RECEIVED;
            $box->not_received_at = now();
            $box->updated_by = $userId;
            $box->save();

            return response()->json([
                'success' => true,
                'message' => 'Reported as not received. The team will look into it.',
                'data'    => $box,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('BoxRequest markNotReceived failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Batches the signed-in user still hasn't confirmed, past their reminder
     * window (5 days direct, 10 days courier).
     *
     * Drives the nag popup in the PWA. Deliberately tiny: the app asks for
     * this on load, so it must stay cheap. Returns an empty list — never an
     * error — when there is nothing to chase.
     */
    public function statusReminders()
    {
        try {
            $userId = Auth::id();

            $due = PromoterBoxRequest::where('user_id', $userId)
                ->where('is_deleted', 0)
                ->where('status', PromoterBoxRequest::STATUS_SENT)
                ->whereNotNull('sent_at')
                ->orderBy('sent_at')
                ->get()
                ->filter(fn ($b) => $b->isStatusReminderDue())
                ->values()
                ->map(function ($b) {
                    return [
                        'id'                => $b->id,
                        'quantity'          => (int) $b->quantity,
                        'level'             => (int) $b->level,
                        'dispatch_label'    => $b->dispatchLabel(),
                        'days_since_sent'   => $b->daysSinceSent(),
                        'reminder_days'     => $b->reminderDays(),
                        'sent_at_formatted' => $b->sent_at
                            ? date('d-m-Y', strtotime((string) $b->sent_at))
                            : null,
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data'    => $due,
                'meta'    => ['count' => $due->count()],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('BoxRequest statusReminders failed', ['error' => $e->getMessage()]);
            // A broken reminder must never break the app shell it renders in.
            return response()->json(['success' => true, 'data' => [], 'meta' => ['count' => 0]], 200);
        }
    }

    /**
     * Allocate the next invoice number, once, WITHIN the financial year the
     * delivery falls in — so each April the sequence restarts at 1.
     *
     * Locked read of that year's current maximum, so two deliveries landing at
     * the same moment can't be handed the same number. The unique key is
     * (invoice_fy, invoice_no), so a collision would fail the save outright
     * rather than quietly duplicating a number.
     */
    private function assignInvoiceNumber(PromoterBoxRequest $box): void
    {
        if ($box->invoice_no !== null) {
            return; // already issued; numbers are never reused
        }

        try {
            DB::transaction(function () use ($box) {
                $fy = InvoiceBuilder::financialYear($box->delivered_at);
                $max = (int) PromoterBoxRequest::where('invoice_fy', $fy)
                    ->lockForUpdate()
                    ->max('invoice_no');
                $box->invoice_fy = $fy;
                $box->invoice_no = $max + 1;
                $box->save();
            });
        } catch (\Throwable $e) {
            // The delivery itself already succeeded; a missing number is
            // recoverable (the invoice route allocates on demand).
            Log::error('Invoice number allocation failed', [
                'box_id' => $box->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Everything the invoice template renders for one delivered batch.
     *
     * Only available once delivered — that is the point at which the sale is
     * complete. A user may only fetch their own; an admin may fetch any.
     */
    public function invoice(Request $request, $id)
    {
        try {
            $actor = Auth::user();
            $isAdmin = $actor && in_array((int) $actor->role, [User::ROLE_SUPER_ADMIN, User::ROLE_SUB_ADMIN], true);

            $query = PromoterBoxRequest::with('user')->where('id', $id)->where('is_deleted', 0);
            if (!$isAdmin) {
                $query->where('user_id', Auth::id());
            }
            $box = $query->first();

            if (!$box) {
                return response()->json(['success' => false, 'message' => 'Not found'], 404);
            }
            // Admins keep access when the toggle is off, so they can still
            // check a bill; it only closes the door for users.
            if (!$isAdmin && !$this->invoiceEnabled()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice download is currently unavailable.',
                ], 403);
            }
            if ((int) $box->status !== PromoterBoxRequest::STATUS_DELIVERED) {
                return response()->json([
                    'success' => false,
                    'message' => 'The invoice is available once the product is delivered',
                ], 400);
            }
            if ($box->rate_per_qty === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'This product was dispatched before pricing was recorded, so no invoice can be generated',
                ], 400);
            }

            // Batches delivered before numbering existed get one on first view,
            // so an older delivery still produces a valid invoice.
            $this->assignInvoiceNumber($box);

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data'    => app(InvoiceBuilder::class)->build($box),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('BoxRequest invoice failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    public function adminIndex(Request $request)
    {
        $sort_column = $request->query('sort_column', 'created_at');
        $sort_direction = $request->query('sort_direction', 'DESC');
        if (!in_array($sort_column, $this->sortable, true)) {
            $sort_column = 'created_at';
        }
        $sort_direction = strtoupper($sort_direction) === 'ASC' ? 'ASC' : 'DESC';

        $page_size = (int) $request->query('page_size', 10);
        $page_number = (int) $request->query('page_number', 1);
        $search_term = trim((string) $request->query('search', ''));
        $search_param = $this->safeJsonDecode($request->query('search_param', '{}'));

        $query = $this->buildAdminQuery($search_param, $search_term);

        $total_records = $query->count();
        $invoicesOn = $this->invoiceEnabled();

        $list = $query->orderBy($sort_column, $sort_direction)
            // Deterministic tiebreak. sent_at/delivered_at are NULL for most
            // rows, so without it equal values order arbitrarily and a paged
            // list can repeat or skip rows between pages.
            ->orderBy('id', $sort_direction)
            ->when($page_size > 0, function ($q) use ($page_size, $page_number) {
                return $q->skip(($page_number - 1) * $page_size)->take($page_size);
            })
            ->with('user')
            ->get()
            ->map(function ($b) use ($invoicesOn) {
                $b->status_label = $b->statusLabel();
                $b->created_at_formatted = $b->created_at ? $b->created_at->format('d-m-Y h:i A') : '-';

                // Lifecycle dates. sent/delivered are null until they
                // happen, and the UI shows a dash for those. requested_at
                // falls back to created_at for any row written before that
                // column existed.
                $b->requested_at_formatted = $this->formatDate($b->requested_at ?: $b->created_at);
                $b->sent_at_formatted = $this->formatDate($b->sent_at);
                $b->delivered_at_formatted = $this->formatDate($b->delivered_at);
                $b->not_received_at_formatted = $this->formatDate($b->not_received_at);

                // How it was dispatched (null until it is marked Sent).
                $b->dispatch_label = $b->dispatchLabel();
                $b->dispatch_summary = $b->dispatchSummary();
                $b->collected_date_formatted = $b->collected_date
                    ? date('d-m-Y', strtotime((string) $b->collected_date))
                    : null;
                $b->has_invoice = $invoicesOn
                    && (int) $b->status === PromoterBoxRequest::STATUS_DELIVERED
                    && $b->rate_per_qty !== null;

                // What this batch will bill at, so the dispatch form can show
                // it before the admin commits.
                $master = ProductPrice::forLevel($b->level);
                $b->master_price = $master ? (float) $master->price : null;
                $b->master_mrp = $master ? (float) $master->mrp : null;
                $b->master_product = $master->product_name ?? null;

                // Quantity is adjustable only while still Requested and at a
                // manual level (3/4). Reducing it frees cap room for the user to
                // re-request later; the offered options are bounded by the cap
                // (allowing for any other batches the user already has).
                $rules = PromoterBoxRequest::rulesForLevel($b->level);
                $manual = $rules && empty($rules['auto']);
                $b->can_edit_quantity = ((int) $b->status === PromoterBoxRequest::STATUS_REQUESTED) && $manual;
                if ($b->can_edit_quantity) {
                    $maxAllowed = PromoterBoxRequest::remainingForLevel((int) $b->user_id, (int) $b->level)
                        + (int) $b->quantity;
                    $b->editable_options = array_values(array_filter(
                        $rules['options'],
                        fn ($o) => $o <= $maxAllowed
                    ));
                } else {
                    $b->editable_options = [];
                }
                return $b;
            });

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $list,
            'pageInfo' => [
                'page_size' => $page_size,
                'page_number' => $page_number,
                'total_pages' => $page_size > 0 ? ceil($total_records / $page_size) : 1,
                'total_records' => $total_records,
            ],
        ], 200);
    }

    /**
     * Admin marks a batch as Sent (dispatched). Blocked once Delivered.
     */
    public function markSent(Request $request)
    {
        try {
            $box = PromoterBoxRequest::where('id', $request->id)->where('is_deleted', 0)->first();
            if (!$box) {
                return response()->json(['success' => false, 'message' => 'Box request not found'], 400);
            }
            if ((int) $box->status === PromoterBoxRequest::STATUS_DELIVERED) {
                return response()->json(['success' => false, 'message' => 'This box is already delivered'], 400);
            }

            // How it was dispatched. Each branch's fields are required for that
            // branch only, so picking Courier can't be saved without the
            // tracking details and vice versa.
            $validator = Validator::make($request->all(), [
                'dispatch_method' => 'required|integer|in:' . PromoterBoxRequest::DISPATCH_DIRECT
                    . ',' . PromoterBoxRequest::DISPATCH_COURIER,
                'collected_date'  => 'required_if:dispatch_method,' . PromoterBoxRequest::DISPATCH_DIRECT . '|nullable|date',
                'courier_name'    => 'required_if:dispatch_method,' . PromoterBoxRequest::DISPATCH_COURIER . '|nullable|string|max:120',
                'courier_number'  => 'required_if:dispatch_method,' . PromoterBoxRequest::DISPATCH_COURIER . '|nullable|string|max:120',
            ], [
                'dispatch_method.required' => 'Choose Direct or Courier',
                'dispatch_method.in'       => 'Choose Direct or Courier',
                'collected_date.required_if' => 'Collected date is required',
                'courier_name.required_if'   => 'Courier name is required',
                'courier_number.required_if' => 'Courier number is required',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            // Bill at the master price for this promoter level.
            $price = ProductPrice::forLevel($box->level);
            if (!$price) {
                return response()->json([
                    'success' => false,
                    'message' => 'No price is set for ' . ProductPrice::levelLabel($box->level)
                        . '. Add it under Product Price before dispatching this batch.',
                ], 400);
            }

            $method = (int) $request->input('dispatch_method');

            $box->status = PromoterBoxRequest::STATUS_SENT;
            $box->sent_at = now();
            $box->sent_by = Auth::id();
            // A fresh dispatch settles any previous "not received" report —
            // clear it so the row reads as cleanly Sent again.
            $box->not_received_at = null;
            $box->dispatch_method = $method;
            // Only keep the fields belonging to the chosen method, so a
            // re-send by the other method can't leave stale details behind.
            $box->collected_date = $method === PromoterBoxRequest::DISPATCH_DIRECT
                ? $request->input('collected_date')
                : null;
            $box->courier_name = $method === PromoterBoxRequest::DISPATCH_COURIER
                ? trim((string) $request->input('courier_name'))
                : null;
            $box->courier_number = $method === PromoterBoxRequest::DISPATCH_COURIER
                ? trim((string) $request->input('courier_number'))
                : null;
            // SNAPSHOT, not a reference: an invoice is a tax document, so a
            // later edit to the master must never change what an already
            // issued invoice says.
            $box->rate_per_qty = round((float) $price->price, 2);
            $box->mrp = round((float) $price->mrp, 2);
            $box->updated_by = Auth::id();
            $box->save();

            return response()->json(['success' => true, 'message' => 'Marked as sent', 'data' => $box], 200);
        } catch (\Throwable $e) {
            Log::error('BoxRequest markSent failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Admin marks a batch as Delivered — a fallback for when the user doesn't
     * confirm receipt themselves. Fills in the Sent stamp first if it was
     * skipped (delivered implies it was sent). Blocked once already delivered.
     */
    public function adminMarkDelivered(Request $request)
    {
        try {
            $box = PromoterBoxRequest::where('id', $request->id)->where('is_deleted', 0)->first();
            if (!$box) {
                return response()->json(['success' => false, 'message' => 'Box request not found'], 400);
            }
            if ((int) $box->status === PromoterBoxRequest::STATUS_DELIVERED) {
                return response()->json(['success' => false, 'message' => 'This box is already delivered'], 400);
            }

            // Delivered implies sent — stamp the sent step if it was skipped.
            if ($box->sent_at === null) {
                $box->sent_at = now();
                $box->sent_by = Auth::id();
            }
            $box->status = PromoterBoxRequest::STATUS_DELIVERED;
            $box->delivered_at = now();
            $box->updated_by = Auth::id();
            $box->save();

            $this->assignInvoiceNumber($box);

            return response()->json(['success' => true, 'message' => 'Marked as delivered', 'data' => $box], 200);
        } catch (\Throwable $e) {
            Log::error('BoxRequest adminMarkDelivered failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Admin adjusts a still-Requested batch's quantity to match availability —
     * only for manual levels (3/4), only to a valid option, and never above the
     * level cap (accounting for the user's other batches). Reducing it lowers
     * the user's used total, which automatically reopens that much of their cap
     * to request again.
     */
    public function adminUpdateQuantity(Request $request)
    {
        try {
            $box = PromoterBoxRequest::where('id', $request->id)->where('is_deleted', 0)->first();
            if (!$box) {
                return response()->json(['success' => false, 'message' => 'Box request not found'], 400);
            }
            if ((int) $box->status !== PromoterBoxRequest::STATUS_REQUESTED) {
                return response()->json(['success' => false, 'message' => 'Only requested batches can be changed'], 400);
            }

            $rules = PromoterBoxRequest::rulesForLevel($box->level);
            if (!$rules || !empty($rules['auto'])) {
                return response()->json(['success' => false, 'message' => "This level's quantity is fixed and can't be changed"], 400);
            }

            // cap minus the user's OTHER batches = remaining + this batch.
            $maxAllowed = PromoterBoxRequest::remainingForLevel((int) $box->user_id, (int) $box->level)
                + (int) $box->quantity;

            $newQty = (int) $request->quantity;
            if (!in_array($newQty, $rules['options'], true) || $newQty > $maxAllowed) {
                $valid = array_values(array_filter($rules['options'], fn ($o) => $o <= $maxAllowed));
                return response()->json([
                    'success' => false,
                    'message' => 'Please choose a valid quantity (' . implode('/', $valid) . ').',
                ], 400);
            }

            $box->quantity = $newQty;
            $box->updated_by = Auth::id();
            $box->save();

            return response()->json(['success' => true, 'message' => 'Quantity updated', 'data' => $box], 200);
        } catch (\Throwable $e) {
            Log::error('BoxRequest adminUpdateQuantity failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /**
     * The admin listing scope: soft-delete, plain filters, the three date
     * ranges and the user search.
     *
     * Shared by adminIndex() and exportExcel() on purpose — the export must
     * always match what the admin is looking at, and duplicating this logic is
     * how the two silently drift apart.
     */
    private function buildAdminQuery(array $search_param, string $search_term)
    {
        $query = PromoterBoxRequest::query()->where('is_deleted', 0);

        $rangeKeys = [];
        foreach (array_keys(self::DATE_RANGE_FILTERS) as $prefix) {
            $rangeKeys[] = $prefix . '_from';
            $rangeKeys[] = $prefix . '_to';
        }
        $skip = array_merge(['fromdate', 'todate'], $rangeKeys);

        foreach ($search_param as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            if (!in_array($key, $this->filterable, true)) {
                continue;
            }
            if (in_array($key, $skip, true)) {
                continue; // handled as ranges below
            }
            if (is_array($value)) {
                if (!empty($value)) {
                    $query->whereIn($key, $value);
                }
            } else {
                $query->where($key, $value);
            }
        }

        // Legacy created_at range, kept so existing saved filters still work.
        $fromDate = $search_param['fromdate'] ?? null;
        $toDate = $search_param['todate'] ?? null;
        if ($fromDate && $toDate) {
            $query->whereBetween('created_at', [$fromDate, $toDate]);
        } elseif ($fromDate) {
            $query->whereDate('created_at', '>=', $fromDate);
        } elseif ($toDate) {
            $query->whereDate('created_at', '<=', $toDate);
        }

        // One independent range per lifecycle date. Filtering on sent/delivered
        // naturally excludes rows that haven't reached that stage, since NULL
        // never satisfies a date comparison.
        foreach (self::DATE_RANGE_FILTERS as $prefix => $column) {
            $from = $search_param[$prefix . '_from'] ?? null;
            $to = $search_param[$prefix . '_to'] ?? null;
            if ($from) {
                $query->whereDate($column, '>=', $from);
            }
            if ($to) {
                $query->whereDate($column, '<=', $to);
            }
        }

        if ($search_term !== '') {
            $query->whereHas('user', function ($q) use ($search_term) {
                $q->where('username', 'LIKE', '%' . $search_term . '%')
                    ->orWhere('first_name', 'LIKE', '%' . $search_term . '%')
                    ->orWhere('last_name', 'LIKE', '%' . $search_term . '%')
                    ->orWhere('mobile', 'LIKE', '%' . $search_term . '%')
                    ->orWhere('customer_id', 'LIKE', '%' . $search_term . '%');
            });
        }

        return $query;
    }

    /**
     * Whether users may download plan-product invoices at all.
     *
     * Admin toggle under App Settings. Read here rather than only in the UI so
     * turning it off also refuses the endpoint — hiding a button is not the
     * same as closing the door.
     */
    private function invoiceEnabled(): bool
    {
        try {
            return AppSetting::getBool(AppSetting::menuKey('invoice_download'), true);
        } catch (\Throwable $e) {
            // A settings read that fails must not take the whole plan-product
            // list down with it. Fall back to the default (on).
            Log::warning('Invoice toggle lookup failed', ['error' => $e->getMessage()]);
            return true;
        }
    }

    /** Display helper: a formatted date, or "-" when it hasn't happened yet. */
    private function formatDate($value): string
    {
        if (empty($value)) {
            return '-';
        }

        return date('d-m-Y h:i A', strtotime((string) $value));
    }

    /**
     * Excel export of the Plan Product list. Applies the same filters, search
     * and sort as the on-screen table, unpaginated.
     */
    public function exportExcel(Request $request)
    {
        try {
            $search_term = trim((string) $request->query('search', ''));
            $search_param = $this->safeJsonDecode($request->query('search_param', '{}'));

            $sort_column = $request->query('sort_column', $request->query('sortBy', 'created_at'));
            if (!in_array($sort_column, $this->sortable, true)) {
                $sort_column = 'created_at';
            }
            $sort_direction = strtoupper((string) $request->query('sort_direction', $request->query('sortDir', 'DESC'))) === 'ASC'
                ? 'ASC'
                : 'DESC';

            $rows = $this->buildAdminQuery($search_param, $search_term)
                ->with('user')
                ->orderBy($sort_column, $sort_direction)
                ->orderBy('id', $sort_direction)
                ->get();

            return Excel::download(new BoxRequestExport($rows), 'plan_product_requests.xlsx');
        } catch (\Throwable $e) {
            Log::error('BoxRequest export failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }
}

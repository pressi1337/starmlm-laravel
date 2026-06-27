<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\PromotionQuizLog;
use App\Traits\HandlesJson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Admin "Promotion Log" — audit listing of promotion-video quiz attempts.
 *
 * Read-only. Follows the standard admin list contract (paginated, sortable,
 * filter by username + date range). The list omits the heavy per-question
 * answers; the detail endpoint (`show`) returns the full audit including every
 * answer so the admin can drill in via the row's view icon.
 */
class PromotionQuizLogController extends Controller
{
    use HandlesJson;

    protected array $sortable = ['id', 'attempted_at', 'percentage', 'earned_amount', 'correct_count'];

    public function index(Request $request)
    {
        try {
            // The admin web sends sort as sortBy/sortDir; accept the documented
            // sort_column/sort_direction too. Default: newest first.
            $sort_column = $request->query('sort_column', $request->query('sortBy', 'id'));
            if (!in_array($sort_column, $this->sortable, true)) {
                $sort_column = 'id';
            }
            $sort_direction = strtoupper((string) $request->query('sort_direction', $request->query('sortDir', 'DESC'))) === 'ASC' ? 'ASC' : 'DESC';
            $page_size = (int) $request->query('page_size', 10);
            $page_number = max(1, (int) $request->query('page_number', 1));
            $search_term = trim((string) $request->query('search', ''));
            $search_param = $this->safeJsonDecode($request->query('search_param', '[]'));

            $query = PromotionQuizLog::query()->with(['user:id,username,customer_id,mobile,current_promoter_level']);

            // Username search (the user-facing filter).
            if ($search_term !== '') {
                $query->whereHas('user', function ($q) use ($search_term) {
                    $q->where('username', 'LIKE', '%' . $search_term . '%')
                        ->orWhere('customer_id', 'LIKE', '%' . $search_term . '%')
                        ->orWhere('mobile', 'LIKE', '%' . $search_term . '%');
                });
            }

            // Date range over attempted_at — date-only so a same-day filter
            // covers the whole day (see WithdrawController for the rationale).
            $fromDate = $search_param['fromdate'] ?? null;
            $toDate = $search_param['todate'] ?? null;
            if ($fromDate && $toDate) {
                $query->whereDate('attempted_at', '>=', $fromDate)
                    ->whereDate('attempted_at', '<=', $toDate);
            } elseif ($fromDate) {
                $query->whereDate('attempted_at', '>=', $fromDate);
            } elseif ($toDate) {
                $query->whereDate('attempted_at', '<=', $toDate);
            }

            $total_records = $query->count();

            $items = $query->orderBy($sort_column, $sort_direction)
                ->when($page_size > 0, function ($q) use ($page_size, $page_number) {
                    return $q->skip(($page_number - 1) * $page_size)->take($page_size);
                })
                ->get()
                ->map(function ($row) {
                    return [
                        'id'                    => $row->id,
                        'username'              => $row->user->username ?? 'N/A',
                        'customer_id'           => $row->user->customer_id ?? null,
                        'mobile'                => $row->user->mobile ?? null,
                        'promotion_video_title' => $row->promotion_video_title,
                        'promoter_level'        => $row->promoter_level,
                        'session_type'          => $row->session_type,
                        'session_label'         => $row->sessionLabel(),
                        'set_no'                => $row->set_no,
                        'attempt_no'            => $row->attempt_no,
                        'total_questions'       => $row->total_questions,
                        'correct_count'         => $row->correct_count,
                        'failed_count'          => $row->failed_count,
                        'percentage'            => $row->percentage,
                        'earned_amount'         => $row->earned_amount,
                        'main_wallet_amount'    => $row->main_wallet_amount,
                        'saving_amount'         => $row->saving_amount,
                        'offered_retry'         => $row->offered_retry,
                        'status'                => $row->status,
                        'status_label'          => $row->statusLabel(),
                        'attempted_at'          => $row->attempted_at ? $row->attempted_at->format('d-m-Y h:i A') : '-',
                        'confirmed_at'          => $row->confirmed_at ? $row->confirmed_at->format('d-m-Y h:i A') : null,
                    ];
                });

            return response()->json([
                'success'  => true,
                'message'  => 'Success',
                'data'     => $items,
                'pageInfo' => [
                    'page_size'     => $page_size,
                    'page_number'   => $page_number,
                    'total_pages'   => $page_size > 0 ? (int) ceil($total_records / max(1, $page_size)) : 1,
                    'total_records' => $total_records,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('PromotionQuizLog index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /** Full detail of one attempt, including every per-question answer. */
    public function show($id)
    {
        $row = PromotionQuizLog::with(['user:id,username,customer_id,mobile,current_promoter_level'])
            ->find($id);
        if (!$row) {
            return response()->json(['success' => false, 'message' => 'Not found'], 400);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'id'                    => $row->id,
                'username'              => $row->user->username ?? 'N/A',
                'customer_id'           => $row->user->customer_id ?? null,
                'mobile'                => $row->user->mobile ?? null,
                'promotion_video_id'    => $row->promotion_video_id,
                'promotion_video_title' => $row->promotion_video_title,
                'promoter_level'        => $row->promoter_level,
                'session_type'          => $row->session_type,
                'session_label'         => $row->sessionLabel(),
                'set_no'                => $row->set_no,
                'attempt_no'            => $row->attempt_no,
                'total_questions'       => $row->total_questions,
                'correct_count'         => $row->correct_count,
                'failed_count'          => $row->failed_count,
                'percentage'            => $row->percentage,
                'earned_amount'         => $row->earned_amount,
                'main_wallet_amount'    => $row->main_wallet_amount,
                'saving_amount'         => $row->saving_amount,
                'offered_retry'         => $row->offered_retry,
                'status'                => $row->status,
                'status_label'          => $row->statusLabel(),
                'answers'               => $row->answers ?? [],
                'attempted_at'          => $row->attempted_at ? $row->attempted_at->format('d-m-Y h:i A') : '-',
                'confirmed_at'          => $row->confirmed_at ? $row->confirmed_at->format('d-m-Y h:i A') : null,
            ],
        ], 200);
    }
}

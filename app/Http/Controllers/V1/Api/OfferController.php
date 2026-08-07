<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\OfferPoint;
use App\Models\OfferSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Offer module.
 *
 * Admin (super-admin only):
 *   • config     — active/inactive, start datetime, leaderboard size
 *   • settings   — points per upgrade step, one row per option (own/referral)
 *   • points tab — per-user totals (own / referral / total) + drill-down history
 *
 * User (PWA):
 *   • status     — is the offer visible, and has it started (countdown source)
 *   • my points  — own/referral/total cards
 *   • my history — paginated ledger of how the points were earned
 *   • top list   — leaderboard (size from admin config)
 */
class OfferController extends Controller
{
    /** Sortable columns for the admin per-user points list. */
    protected array $sortable = ['total_points', 'own_points', 'referral_points', 'username'];

    // ───────────────────────────── Admin: config ─────────────────────────────

    /** Current offer config (creates nothing; returns defaults when unset). */
    public function getConfig()
    {
        try {
            $offer = Offer::current();

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data'    => [
                    'id'             => $offer->id ?? null,
                    'title'          => $offer->title ?? '',
                    'is_active'      => (int) ($offer->is_active ?? 0),
                    'start_at'       => $offer && $offer->start_at
                        ? Carbon::parse($offer->start_at)->format('Y-m-d\TH:i')
                        : null,
                    'start_at_label' => $offer && $offer->start_at
                        ? Carbon::parse($offer->start_at)->format('d-m-Y h:i A')
                        : null,
                    'top_list_count' => (int) ($offer->top_list_count ?? 10),
                    'has_started'    => $offer ? $offer->hasStarted() : false,
                    'is_running'     => $offer ? $offer->isRunning() : false,
                    'server_time'    => now()->toIso8601String(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Offer getConfig failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /** Upsert the single offer config row. */
    public function saveConfig(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'title'          => 'nullable|string|max:150',
                'is_active'      => 'nullable|boolean',
                'start_at'       => 'nullable|date',
                'top_list_count' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $authId = Auth::id();
            $offer = Offer::current() ?: new Offer();
            $offer->title = $request->title;
            $offer->is_active = $request->has('is_active') && (int) $request->input('is_active') ? 1 : 0;
            $offer->start_at = $request->filled('start_at')
                ? Carbon::parse($request->start_at)
                : null;
            $offer->top_list_count = $request->filled('top_list_count')
                ? (int) $request->top_list_count
                : 10;
            if (!$offer->exists) {
                $offer->created_by = $authId;
            }
            $offer->updated_by = $authId;
            $offer->is_deleted = 0;
            $offer->save();

            return response()->json(['message' => 'Offer settings saved successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            Log::error('Offer saveConfig failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    // ──────────────────────── Admin: points per upgrade ──────────────────────

    /** Both option rows (own / referral) with their per-step point values. */
    public function settingsIndex()
    {
        try {
            $rows = OfferSetting::where('is_deleted', 0)
                ->orderBy('option_type', 'asc')
                ->get()
                ->map(function ($row) {
                    return $this->presentSetting($row);
                });

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data'    => $rows,
                // Drives the admin dropdown + the labelled inputs.
                'meta'    => [
                    'options' => [
                        ['value' => OfferSetting::OPTION_OWN,      'label' => 'Own'],
                        ['value' => OfferSetting::OPTION_REFERRAL, 'label' => 'Referral'],
                    ],
                    'levels'  => collect(OfferSetting::LEVEL_COLUMNS)->map(function ($column, $level) {
                        return [
                            'level'  => $level,
                            'column' => $column,
                            'label'  => OfferSetting::levelLabel($level),
                        ];
                    })->values(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Offer settingsIndex failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Upsert the point values for one option type. Only ONE row per option is
     * kept — saving "Own" again updates the existing row rather than adding.
     */
    public function settingsSave(Request $request)
    {
        try {
            $rules = [
                'option_type' => 'required|integer|in:' . OfferSetting::OPTION_OWN . ',' . OfferSetting::OPTION_REFERRAL,
                'is_active'   => 'nullable|boolean',
            ];
            foreach (OfferSetting::LEVEL_COLUMNS as $column) {
                $rules[$column] = 'nullable|numeric|min:0';
            }

            $validator = Validator::make($request->all(), $rules, [
                'option_type.required' => 'Please choose an option',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $authId = Auth::id();
            $optionType = (int) $request->option_type;

            $setting = OfferSetting::where('option_type', $optionType)
                ->where('is_deleted', 0)
                ->orderBy('id', 'desc')
                ->first();
            if (!$setting) {
                $setting = new OfferSetting();
                $setting->option_type = $optionType;
                $setting->created_by = $authId;
            }
            foreach (OfferSetting::LEVEL_COLUMNS as $column) {
                $setting->{$column} = (float) $request->input($column, 0);
            }
            $setting->is_active = $request->has('is_active')
                ? ((int) $request->input('is_active') ? 1 : 0)
                : 1;
            $setting->updated_by = $authId;
            $setting->is_deleted = 0;
            $setting->save();

            return response()->json(['message' => 'Offer points saved successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            Log::error('Offer settingsSave failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    /** Soft-delete an option's point config. */
    public function settingsDestroy($id)
    {
        try {
            $setting = OfferSetting::where('id', $id)->where('is_deleted', 0)->first();
            if (!$setting) {
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }
            $setting->is_deleted = 1;
            $setting->updated_by = Auth::id();
            $setting->save();

            return response()->json(['message' => 'Deleted successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            Log::error('Offer settingsDestroy failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    // ───────────────────── Admin: user points + history ──────────────────────

    /** Tab 2 — per-user totals (own / referral / overall), searchable. */
    public function adminPoints(Request $request)
    {
        try {
            $sort_column = $request->query('sort_column', $request->query('sortBy', 'total_points'));
            if (!in_array($sort_column, $this->sortable, true)) {
                $sort_column = 'total_points';
            }
            $sort_direction = strtoupper((string) $request->query('sort_direction', $request->query('sortDir', 'DESC'))) === 'ASC' ? 'ASC' : 'DESC';
            $page_size = (int) $request->query('page_size', 10);
            $page_number = max(1, (int) $request->query('page_number', 1));
            $search_term = trim((string) $request->query('search', ''));

            $applySearch = function ($q) use ($search_term) {
                if ($search_term !== '') {
                    $like = '%' . $search_term . '%';
                    $q->where(function ($w) use ($like) {
                        $w->where('u.username', 'LIKE', $like)
                            ->orWhere('u.customer_id', 'LIKE', $like)
                            ->orWhere('u.mobile', 'LIKE', $like)
                            ->orWhere('u.first_name', 'LIKE', $like)
                            ->orWhere('u.last_name', 'LIKE', $like);
                    });
                }
                return $q;
            };

            // COUNT(DISTINCT user_id) — the grouped query can't be counted directly.
            $total_records = $applySearch(
                DB::table('offer_points as op')
                    ->join('users as u', 'u.id', '=', 'op.user_id')
                    ->where('op.is_deleted', 0)
                    ->where('u.is_deleted', 0)
            )->distinct()->count('op.user_id');

            $query = $applySearch(OfferPoint::aggregateQuery())
                ->orderBy($sort_column, $sort_direction);

            if ($page_size > 0) {
                $query->skip(($page_number - 1) * $page_size)->take($page_size);
            }

            // True leaderboard position, so the rank stays correct no matter how
            // the admin sorts or which page they're on. Ordered identically to
            // topList() (points DESC, then user_id) so both views agree.
            $rankMap = [];
            $position = 0;
            foreach (
                DB::table('offer_points')
                    ->select('user_id', DB::raw('COALESCE(SUM(points), 0) as tp'))
                    ->where('is_deleted', 0)
                    ->groupBy('user_id')
                    ->orderByDesc('tp')
                    ->orderBy('user_id')
                    ->pluck('user_id') as $uid
            ) {
                $rankMap[$uid] = ++$position;
            }

            $rows = collect($query->get())->map(function ($row) use ($rankMap) {
                return [
                    'rank'            => $rankMap[$row->user_id] ?? null,
                    'user_id'         => $row->user_id,
                    'username'        => $row->username ?? 'N/A',
                    'name'            => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')),
                    'customer_id'     => $row->customer_id,
                    'mobile'          => $row->mobile,
                    'own_points'      => round((float) $row->own_points, 2),
                    'referral_points' => round((float) $row->referral_points, 2),
                    'total_points'    => round((float) $row->total_points, 2),
                ];
            });

            return response()->json([
                'success'  => true,
                'message'  => 'Success',
                'data'     => $rows,
                'pageInfo' => [
                    'page_size'     => $page_size,
                    'page_number'   => $page_number,
                    'total_pages'   => $page_size > 0 ? (int) ceil($total_records / max(1, $page_size)) : 1,
                    'total_records' => $total_records,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Offer adminPoints failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /** Drill-down (view icon) — one user's full earning history. */
    public function adminUserHistory(Request $request, $userId)
    {
        try {
            $user = User::find($userId);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 400);
            }

            $payload = $this->historyPayload($request, $userId);
            $payload['user'] = [
                'id'          => $user->id,
                'username'    => $user->username,
                'name'        => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'customer_id' => $user->customer_id,
                'mobile'      => $user->mobile,
            ];

            return response()->json($payload, 200);
        } catch (\Throwable $e) {
            Log::error('Offer adminUserHistory failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    // ──────────────────────────── User (PWA) ─────────────────────────────────

    /**
     * Offer visibility + countdown source. `server_time` lets the PWA compute
     * the remaining hh:mm:ss without trusting the device clock.
     */
    public function userStatus()
    {
        try {
            $offer = Offer::current();
            $isActive = $offer ? (int) $offer->is_active === 1 : false;

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data'    => [
                    'is_active'      => $isActive,
                    'has_started'    => $offer ? $offer->hasStarted() : false,
                    // The PWA shows the offer menu only when this is true.
                    'is_visible'     => $isActive,
                    'title'          => $offer->title ?? '',
                    'start_at'       => $offer && $offer->start_at
                        ? Carbon::parse($offer->start_at)->toIso8601String()
                        : null,
                    'start_at_label' => $offer && $offer->start_at
                        ? Carbon::parse($offer->start_at)->format('d-m-Y h:i A')
                        : null,
                    'server_time'    => now()->toIso8601String(),
                    'top_list_count' => $offer ? $offer->topCount() : 10,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Offer userStatus failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /** The signed-in user's own/referral/total point cards. */
    public function myPoints()
    {
        try {
            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data'    => OfferPoint::totalsForUser(Auth::id()),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Offer myPoints failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /** The signed-in user's paginated earning history. */
    public function myHistory(Request $request)
    {
        try {
            return response()->json($this->historyPayload($request, Auth::id()), 200);
        } catch (\Throwable $e) {
            Log::error('Offer myHistory failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Leaderboard — Rank / Username / Name / Points. Size comes from the admin
     * config (default 10). Ties keep a stable order via user_id.
     */
    public function topList(Request $request)
    {
        try {
            $offer = Offer::current();
            $limit = $offer ? $offer->topCount() : 10;
            // Allow an explicit smaller request, never larger than configured.
            if ($request->filled('limit')) {
                $limit = max(1, min($limit, (int) $request->limit));
            }

            $rows = OfferPoint::aggregateQuery()
                ->havingRaw('COALESCE(SUM(op.points), 0) > 0')
                ->orderBy('total_points', 'DESC')
                ->orderBy('op.user_id', 'ASC')
                ->take($limit)
                ->get();

            $data = [];
            $rank = 0;
            foreach ($rows as $row) {
                $rank++;
                $data[] = [
                    'rank'     => $rank,
                    'user_id'  => $row->user_id,
                    'username' => $row->username ?? 'N/A',
                    'name'     => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')),
                    'points'   => round((float) $row->total_points, 2),
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data'    => $data,
                'meta'    => ['limit' => $limit],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Offer topList failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    // ───────────────────────────── Helpers ───────────────────────────────────

    /** Shared paginated-history builder (admin drill-down + user's own page). */
    protected function historyPayload(Request $request, $userId): array
    {
        $page_size = (int) $request->query('page_size', 10);
        $page_number = max(1, (int) $request->query('page_number', 1));

        $base = OfferPoint::with(['sourceUser:id,username,first_name,last_name'])
            ->where('user_id', $userId)
            ->where('is_deleted', 0);

        $optionFilter = $request->query('option_type');
        if ($optionFilter !== null && $optionFilter !== '') {
            $base->where('option_type', (int) $optionFilter);
        }

        $total_records = (clone $base)->count();

        $rows = $base->orderBy('id', 'desc')
            ->when($page_size > 0, function ($q) use ($page_size, $page_number) {
                return $q->skip(($page_number - 1) * $page_size)->take($page_size);
            })
            ->get()
            ->map(function ($row) {
                return [
                    'id'           => $row->id,
                    'option_type'  => (int) $row->option_type,
                    'option_label' => OfferSetting::optionLabel($row->option_type),
                    'level'        => $row->level,
                    'level_label'  => OfferSetting::levelLabel($row->level),
                    'points'       => round((float) $row->points, 2),
                    'description'  => $row->description,
                    'from_user'    => $row->sourceUser->username ?? null,
                    'from_name'    => $row->sourceUser
                        ? trim(($row->sourceUser->first_name ?? '') . ' ' . ($row->sourceUser->last_name ?? ''))
                        : null,
                    'earned_at'    => $row->earned_at
                        ? Carbon::parse($row->earned_at)->format('d-m-Y h:i A')
                        : ($row->created_at ? $row->created_at->format('d-m-Y h:i A') : '-'),
                ];
            });

        return [
            'success'  => true,
            'message'  => 'Success',
            'data'     => $rows,
            'totals'   => OfferPoint::totalsForUser($userId),
            'pageInfo' => [
                'page_size'     => $page_size,
                'page_number'   => $page_number,
                'total_pages'   => $page_size > 0 ? (int) ceil($total_records / max(1, $page_size)) : 1,
                'total_records' => $total_records,
            ],
        ];
    }

    /** Shape one option's config row for the admin form. */
    protected function presentSetting(OfferSetting $row): array
    {
        $data = [
            'id'           => $row->id,
            'option_type'  => (int) $row->option_type,
            'option_label' => OfferSetting::optionLabel($row->option_type),
            'is_active'    => (int) $row->is_active,
        ];
        foreach (OfferSetting::LEVEL_COLUMNS as $level => $column) {
            $data[$column] = (float) $row->{$column};
        }
        return $data;
    }
}

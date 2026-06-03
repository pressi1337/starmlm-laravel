<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyVideo;
use App\Models\DailyVideoWatchDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Rules\UniqueActive;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DailyVideoController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    protected $messages;
    public function __construct()
    {
        $this->messages = [
            "title.required" => "Title Required",
            "description.required" => "Description Required",
            "video_path.required" => "Video Path Required",
            "youtube_link.required" => "Youtube Link Required",
            "showing_date.required" => "Showing Date Required",
            "type.required" => "Type Required",
        ];
    }
    public function index(Request $request)
    {

        // Default sorting
        $sort_column = $request->query('sort_column', 'created_at');
        $sort_direction = $request->query('sort_direction', 'DESC');

        // Pagination parameters
        $page_size = (int) $request->query('page_size', 10); // Default to 10 items per page
        $page_number = (int) $request->query('page_number', 1);
        $search_term = $request->query('search', '');

        // Parse search_param JSON
        $search_param = $request->query('search_param', '{}');
        try {
            $search_param = json_decode($search_param, true);
            if (!is_array($search_param)) {
                $search_param = [];
            }
        } catch (\Exception $e) {
            $search_param = [];
        }

        // Start building the query
        $query = DailyVideo::query();

        // Apply default filters
        $query->where('is_deleted', 0);

        // Apply search_param filters
        foreach ($search_param as $key => $value) {
            if (is_array($value)) {
                if ($key === 'date_between' && count($value) === 2) {
                    // Handle date range filter
                    $query->whereBetween('showing_date', $value);
                } elseif (!empty($value)) {
                    // Use whereIn for array values
                    $query->whereIn($key, $value);
                }
            } else {
                if ($value !== '') {
                    // Use where for single values
                    $query->where($key, $value);
                }
            }
        }

        // Apply search filter on title and description
        if (!empty($search_term)) {
            $query->where(function ($q) use ($search_term) {
                $q->where('title', 'LIKE', '%' . $search_term . '%')
                    ->orWhere('description', 'LIKE', '%' . $search_term . '%');
            });
        }

        // Get total records for pagination
        $total_records = $query->count();

        // Apply sorting and pagination
        $daily_videos = $query->orderBy($sort_column, $sort_direction)
            ->when($page_size > 0, function ($q) use ($page_size, $page_number) {
                return $q->skip(($page_number - 1) * $page_size)
                    ->take($page_size);
            })
            ->get()
            ->map(function ($daily_video) {
                $daily_video->created_at_formatted = $daily_video->created_at
                    ? $daily_video->created_at->format('d-m-Y h:i A')
                    : '-';
                $daily_video->updated_at_formatted = $daily_video->updated_at
                    ? $daily_video->updated_at->format('d-m-Y h:i A')
                    : '-';
                return $daily_video;
            });

        // Build the response
        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $daily_videos,
            'pageInfo' => [
                'page_size' => $page_size,
                'page_number' => $page_number,
                'total_pages' => $page_size > 0 ? ceil($total_records / $page_size) : 1,
                'total_records' => $total_records
            ]
        ], 200);
    }


    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        try {
            // Validation
            $validator = Validator::make($request->all(), [
                "title" => 'required',
                "description" => 'required',
                "video_path" => 'required_without:youtube_link',
                "youtube_link" => 'required_without:video_path',
                "showing_date" => ['required', new UniqueActive('daily_videos', 'showing_date', null, [])],
                "type" => 'required',
            ], $this->messages);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

            $auth_user_id = auth()->user()->id;
            $w = new DailyVideo();
            $w->title = $request->title;
            $w->description = $request->description;
            $w->youtube_link = $request->youtube_link;
            $w->showing_date = $request->showing_date;
            $w->type = $request->type ?? 1;
            $w->video_path  =  $request->video_path;

            // Use provided is_active/active when present; default to 1 when absent
            $isActiveInput = $request->has('is_active') ? $request->input('is_active') : ($request->has('active') ? $request->input('active') : 1);
            $w->is_active = (int) $isActiveInput ? 1 : 0;
            $w->created_by =  $auth_user_id;
            $w->updated_by =  $auth_user_id;
            $w->save();

            DB::commit();

            return response()->json(['message' => 'Created successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('DailyVideo store failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
        $daily_video = DailyVideo::find($id);

        return response()->json([
            'success' => true,
            'data' => $daily_video,
        ], 200);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {

        $daily_video = DailyVideo::find($id);

        return response()->json([
            'success' => true,
            'data' => $daily_video,
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        try {
            // Validation
            $validator = Validator::make($request->all(), [
                "title" => 'required',
                "description" => 'required',
                "showing_date" => ['required', new UniqueActive(
                    'daily_videos',
                    'showing_date',
                    $id,
                    []
                )],
                "type" => 'required',
            ], $this->messages);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

            $auth_user_id = Auth::id();
            $w = DailyVideo::find($id);
            if (!$w) {
                DB::rollBack();
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }
            $w->title = $request->title;
            $w->description = $request->description;
            $w->youtube_link = $request->youtube_link;
            $w->showing_date = $request->showing_date;
            $w->type = $request->type ?? 1;
            $w->video_path  =  $request->video_path;
            // Use provided is_active/active when present; default to 1 when absent
            $isActiveInput = $request->has('is_active') ? $request->input('is_active') : ($request->has('active') ? $request->input('active') : 1);
            $w->is_active = (int) $isActiveInput ? 1 : 0;
            $w->updated_by =  $auth_user_id;
            $w->save();

            DB::commit();

            return response()->json(['message' => 'Updated successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('DailyVideo update failed', ['id' => $id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        try {
            DB::beginTransaction();

            $u = DailyVideo::find($id);
            if (!$u) {
                DB::rollBack();
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }
            $u->is_deleted = 1;
            $u->updated_by = Auth::id();
            $u->save();

            DB::commit();
            return response()->json(['message' => 'Deleted successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('DailyVideo destroy failed', ['id' => $id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    public function StatusUpdate(Request $request)
    {
        try {
            $auth_user_id = Auth::id();
            $w = DailyVideo::find($request->id);
            if (!$w) {
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }
            $isActiveInput = $request->has('is_active') ? $request->input('is_active') : ($request->has('active') ? $request->input('active') : 1);
            $w->is_active = (int) $isActiveInput ? 1 : 0;
            $w->updated_by =  $auth_user_id;
            $w->save();

            return response()->json(['message' => 'Daily Video Status updated successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            Log::error('DailyVideo status update failed', ['id' => $request->id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    /**
     * Toggle the "default new-user video" flag from the admin list (an on/off
     * switch per row, separate from create/edit). Turning one ON clears the
     * flag on every other row so there is only ever a single default; turning
     * it OFF simply leaves no default. Wrapped in a transaction so the promote
     * + demote is atomic.
     */
    public function defaultUpdate(Request $request)
    {
        try {
            $auth_user_id = Auth::id();
            $w = DailyVideo::find($request->id);
            if (!$w) {
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }

            $makeDefault = $request->boolean('is_default');

            DB::beginTransaction();

            $w->is_default = $makeDefault ? 1 : 0;
            $w->updated_by = $auth_user_id;
            $w->save();

            // Single default: promoting this one demotes every other row.
            if ($makeDefault) {
                DailyVideo::where('id', '!=', $w->id)
                    ->where('is_deleted', 0)
                    ->where('is_default', 1)
                    ->update(['is_default' => 0, 'updated_by' => $auth_user_id]);
            }

            DB::commit();

            return response()->json([
                'message' => $makeDefault
                    ? 'Default video set successfully'
                    : 'Default video cleared',
                'status' => 200,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('DailyVideo defaultUpdate failed', ['id' => $request->id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    /**
     * Toggle whether a video is part of the daily rotation pool — an on/off
     * switch per row, like the default toggle, but with NO single-row limit:
     * the admin can flag as many videos as they like. The rotation fallback
     * (pickRotatingFallback) draws only from these flagged videos.
     */
    public function rotationalUpdate(Request $request)
    {
        try {
            $auth_user_id = Auth::id();
            $w = DailyVideo::find($request->id);
            if (!$w) {
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }

            $w->is_rotational = $request->boolean('is_rotational') ? 1 : 0;
            $w->updated_by = $auth_user_id;
            $w->save();

            return response()->json([
                'message' => $w->is_rotational
                    ? 'Added to rotation successfully'
                    : 'Removed from rotation',
                'status' => 200,
            ]);
        } catch (\Throwable $e) {
            Log::error('DailyVideo rotationalUpdate failed', ['id' => $request->id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    /**
     * Single source of truth for "which daily video does THIS user see today".
     * Both todayVideo() (the watch screen) and todayVideostatus() (the
     * unlock-gate) call this so they can never disagree — if they resolved
     * differently a user could watch one video yet stay gated on another.
     *
     * Resolution order:
     *   1. NEW user + a default video is configured  ->  the default video.
     *      "New" = has never completed a daily video on a PRIOR day. Brand-new
     *      users always start on the curated default, even when a dated video
     *      also exists. Keying off prior-DAY (not "ever") keeps the choice
     *      stable for the rest of today after they watch it, instead of
     *      flipping them onto the rotation mid-day.
     *   2. A video explicitly scheduled for today (showing_date = today) —
     *      the normal/"regular working" case, shown to every (returning) user.
     *   3. Otherwise a rotating video from the admin-curated rotation pool
     *      (see pickRotatingFallback) — common to ALL users, a different one
     *      each day. (Empty if the admin hasn't flagged any rotation videos.)
     *
     * Returns a DailyVideo model, or null only when the library is empty.
     */
    private function resolveTodaysVideo($userId, string $today)
    {
        $isNewUser = ! DB::table('daily_video_watch_details')
            ->where('user_id', $userId)
            ->where('is_deleted', 0)
            ->whereDate('watched_date', '<', $today)
            ->exists();

        if ($isNewUser) {
            $default = DailyVideo::where('is_active', 1)
                ->where('is_deleted', 0)
                ->where('is_default', 1)
                ->first();
            if ($default) {
                return $default;
            }
            // No default configured -> fall through to the common flow.
        }

        $dated = DailyVideo::where('is_active', 1)
            ->where('is_deleted', 0)
            ->whereDate('showing_date', $today)
            ->first();
        if ($dated) {
            return $dated;
        }

        return $this->pickRotatingFallback($today);
    }

    /**
     * Deterministic, date-driven pick from the ADMIN-CURATED rotation pool —
     * only videos flagged is_rotational = 1 (the single default video is always
     * excluded). On a day with no scheduled upload this is what users get, so:
     *   - the pick CHANGES every day — no repeat across consecutive days, even
     *     over a multi-day gap — as long as 2+ rotation videos exist, and
     *   - everyone sees the SAME video that day (consistent with scheduled
     *     videos being global).
     *
     * It is "random-looking" but deterministic, which is what guarantees the
     * no-repeat property that a plain random pick cannot. Returns null when the
     * admin hasn't flagged any rotation videos — i.e. no fallback that day.
     */
    private function pickRotatingFallback(string $today)
    {
        $pool = DailyVideo::where('is_active', 1)
            ->where('is_deleted', 0)
            ->where('is_rotational', 1)
            ->where('is_default', 0)
            ->orderBy('id')
            ->get();

        $count = $pool->count();
        if ($count === 0) {
            return null;
        }

        // Whole days elapsed since a fixed epoch increments by exactly 1 each
        // calendar day, so consecutive days land on consecutive pool indices
        // (mod count) — i.e. a clean rotation that never repeats day-to-day.
        $epoch = new \DateTimeImmutable('2000-01-01');
        $todayDt = new \DateTimeImmutable($today);
        $dayIndex = (int) $epoch->diff($todayDt)->days;

        return $pool[$dayIndex % $count];
    }

    public function todayVideo()
    {
        try {
            $today = date('Y-m-d');
            $userId = Auth::id();

            $daily_video = $this->resolveTodaysVideo($userId, $today);

            if ($daily_video) {
                $daily_video->created_at_formatted = $daily_video->created_at
                    ? $daily_video->created_at->format('d-m-Y h:i A')
                    : '-';
                $daily_video->updated_at_formatted = $daily_video->updated_at
                    ? $daily_video->updated_at->format('d-m-Y h:i A')
                    : '-';

                // From the user's perspective this is just "today's" daily video,
                // so surface today's date — NOT the row's original showing_date,
                // which for a rotating/default video can be an old or unrelated
                // date. Uses the same server date that drove the selection so the
                // shown date always lines up with "today".
                $daily_video->display_date = $today;

                $checkvideowatched = DB::table('daily_video_watch_details')
                    ->where('daily_video_id', $daily_video->id)
                    ->where('user_id', $userId)
                    ->whereDate('watched_date', $today)
                    ->first();
                $daily_video->watched = $checkvideowatched ? 1 : 0;
                return response()->json([
                    'success' => true,
                    'message' => 'Success',
                    'data' => $daily_video,
                ], 200);
            } else {
                return response()->json([
                    'message' => 'No Data found',
                    'success' => false
                ], 400);
            }
        } catch (\Throwable $e) {
            Log::error('DailyVideo todayVideo failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    public function todayVideostatus()
    {
        try {
            $today = date('Y-m-d');
            $userId = Auth::id();

            $daily_video = $this->resolveTodaysVideo($userId, $today);

            if ($daily_video) {
                $checkvideowatched = DB::table('daily_video_watch_details')
                    ->where('daily_video_id', $daily_video->id)
                    ->where('user_id', $userId)
                    ->whereDate('watched_date', $today)
                    ->first();
                $data = ['watched' => $checkvideowatched ? 1 : 0];
                return response()->json([
                    'success' => true,
                    'message' => 'Success',
                    'data' => $data,
                ], 200);
            } else {
                $data = ['watched' => 0];
                return response()->json([
                    'message' => 'No Data found',
                    'success' => true,
                    'data'=>$data,
                ], 200);
            }
        } catch (\Throwable $e) {
            Log::error('DailyVideo todayVideostatus failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    public function todayVideoWatched(Request $request)
    {
        try {
            $auth_user_id = Auth::id();
            $w = DailyVideoWatchDetail::where('daily_video_id', $request->daily_video_id)->whereDate('watched_date',date('Y-m-d'))->where('user_id', $auth_user_id)->first();
            if ($w) {
                $w->watchedcount = (float)$w->watchedcount + 1;
                $w->save();
            } else {
                $w = new DailyVideoWatchDetail();
                $w->daily_video_id = $request->daily_video_id;
                $w->user_id = $auth_user_id;
                $w->watched_date = date('Y-m-d');
                $w->watchedstatus = $request->watchedstatus ?? 1;
                $w->created_by =  $auth_user_id;
                $w->updated_by =  $auth_user_id;
                $w->save();
            }

            return response()->json(['message' => 'Daily Video Watched Added successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            Log::error('DailyVideo todayVideoWatched failed', ['daily_video_id' => $request->daily_video_id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }

    }
}

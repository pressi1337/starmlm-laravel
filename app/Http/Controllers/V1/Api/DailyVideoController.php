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
    public function todayVideo()
    {
        try {
            $today = date('Y-m-d');

            $daily_video = DailyVideo::where('is_active', 1)
                ->where('is_deleted', 0)
                ->whereDate('showing_date', $today)
                ->first();

            if ($daily_video) {
                $daily_video->created_at_formatted = $daily_video->created_at->format('d-m-Y h:i A');
                $daily_video->updated_at_formatted = $daily_video->updated_at->format('d-m-Y h:i A');

                $checkvideowatched = DB::table('daily_video_watch_details')
                    ->where('daily_video_id', $daily_video->id)
                    ->where('user_id', Auth::id())
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

            $daily_video = DailyVideo::where('is_active', 1)
                ->where('is_deleted', 0)
                ->whereDate('showing_date', $today)
                ->first();

            if ($daily_video) {
                $checkvideowatched = DB::table('daily_video_watch_details')
                    ->where('daily_video_id', $daily_video->id)
                    ->where('user_id', Auth::id())
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

<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyVideo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Rules\UniqueActive;
use Illuminate\Support\Facades\Auth;

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
        if ($request->query('is_pagination') == 1) {
            // Default sorting
            $sort_column = $request->query('sort_column', 'created_at');
            $sort_direction = $request->query('sort_direction', 'DESC');

            // Pagination parameters
            $page_size = (int) $request->query('page_size', 0);
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

            // Build the query
            $query = DailyVideo::where('is_deleted', 0);

            // Apply search_param filters
            foreach ($search_param as $key => $value) {
                if (is_array($value)) {
                    // Use whereIn for array values
                    $query->whereIn($key, $value);
                } else {
                    if ($value) {
                        // Use where for single values
                        $query->where($key, $value);
                    }
                }
            }

            // Apply search filter on category_name
            if (!empty($search_term)) {
                $query->where('title', 'LIKE', '%' . $search_term . '%');
            }

            // Get total records for pageInfo
            $total_records = $query->count();

            // Apply pagination
            $daily_videos_query = $query

                ->orderBy($sort_column, $sort_direction);
            // Apply pagination only if page_size is valid
            if ($page_size > 0) {
                $daily_videos_query->skip(($page_number - 1) * $page_size)
                    ->take($page_size);
            }
            $daily_videos = $daily_videos_query
                ->get()->map(function ($daily_video) {
                    $daily_video->created_at_formatted =  $daily_video->created_at->format('d-m-Y h:i A');
                    $daily_video->updated_at_formatted = $daily_video->updated_at->format('d-m-Y h:i A');
                    return $daily_video;
                });

            // Build the response
            return response()->json([
                'success' => true,
                'message' => 'success',
                'data' => $daily_videos,
                'pageInfo' => [
                    'page_size' => $page_size,
                    'page_number' => $page_number,
                    'recordsTotal' => $total_records
                ]
            ], 200);
        } else {
            // Retrieve categories based on pagination, sorting, and filtering
            $daily_videos = DailyVideo::where(['is_active' => 1, 'is_deleted' => 0])

                ->get()->map(function ($daily_video) {
                    $daily_video->created_at_formatted =  $daily_video->created_at->format('d-m-Y h:i A');
                    $daily_video->updated_at_formatted = $daily_video->updated_at->format('d-m-Y h:i A');
                    return $daily_video;
                });
            // Return data as JSON response with the expected structure
            return response()->json([
                'data' => $daily_videos,
                'success' => true,
                'message' => 'success',
            ], 200);
        }
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
        // showing date unique validation pending
        $validator = Validator::make($request->all(), [
            "title" => 'required',
            "description" => 'required',
            "video_path" => 'required_without:youtube_link',
            "youtube_link" => 'required_without:video_path',
            "showing_date" => ['required', new UniqueActive('daily_videos', 'showing_date', null, [])],
            "type" => 'required',
        ], $this->messages);

        if ($validator->fails()) {
            // Validation failed, return a JSON response with validation errors
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $auth_user_id = Auth::id();
        $w = DailyVideo::create();
        $w->title = $request->title;
        $w->description = $request->description;
        $w->youtube_link = $request->youtube_link;
        $w->showing_date = $request->showing_date;
        $w->type = $request->type;
        if ($request->hasFile('video_path')) {
            $file = $request->file('video_path');
            $original_name = $file->getClientOriginalName();
            $modified_name = str_replace(' ', '_', $original_name);
            $video_full_name = date('d-m-y_H-i-s') .  $modified_name;
            $upload_path = 'uploads/daily_video/';
            $video_url = $upload_path . $video_full_name;
            $file->move($upload_path, $video_full_name);
            $w->video_path  =  $video_url;
        }
        $w->is_active = $request->has('is_active') ? 1 : 0;
        $w->created_by =  $auth_user_id;
        $w->updated_by =  $auth_user_id;
        $w->save();



        // create one user with role 8
        return response()->json(['message' => 'New Daily Video Created successfully', 'status' => 200,]);
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
            'daily_video' => $daily_video,

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
        // Log::info('Update Request Data:', $request->all());
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
            // Validation failed, return a JSON response with validation errors
            return response()->json(['errors' => $validator->errors()], 422);
        }
        // user table email unique validation pending

        $auth_user_id = Auth::id();
        $w = DailyVideo::find($id);
        $w->title = $request->title;
        $w->description = $request->description;
        $w->youtube_link = $request->youtube_link;
        $w->showing_date = $request->showing_date;
        $w->type = $request->type;
        if ($request->hasFile('video_path')) {
            $file = $request->file('video_path');
            $original_name = $file->getClientOriginalName();
            $modified_name = str_replace(' ', '_', $original_name);
            $video_full_name = date('d-m-y_H-i-s') .  $modified_name;
            $upload_path = 'uploads/daily_video/';
            $video_url = $upload_path . $video_full_name;
            $file->move($upload_path, $video_full_name);
            $w->video_path = $video_url;
        } else {
            $w->video_path =   $w->video_path;
        }

        $w->is_active = $request->has('is_active') ? 1 : 0;
        $w->updated_by =  $auth_user_id;
        $w->save();


        return response()->json(['message' => 'Daily Video Details updated successfully', 'status' => 200]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $u = DailyVideo::find($id);
        $u->is_deleted = 1;
        $u->updated_by = Auth::id();
        $u->save();
        // ShopProductStock::where('shop_id', $id)->update(['is_active' => 0]);
        return response()->json(['status' => 200]);
    }

    public function StatusUpdate(Request $request)
    {

        $auth_user_id = Auth::id();
        $w = DailyVideo::find($request->id);
        $w->is_active = $request->has('is_active') ? 1 : 0;
        $w->updated_by =  $auth_user_id;
        $w->save();

        return response()->json(['message' => 'Daily Video Details updated successfully', 'status' => 200]);
    }
    public function todayVideo()
    {
        $today = date('Y-m-d'); // Get today's date in Y-m-d format

        $daily_video = DailyVideo::where('is_active', 1)
            ->where('is_deleted', 0)
            ->whereDate('showing_date', $today)
            ->first();

        if ($daily_video) {
            $daily_video->created_at_formatted = $daily_video->created_at->format('d-m-Y h:i A');
            $daily_video->updated_at_formatted = $daily_video->updated_at->format('d-m-Y h:i A');
            return response()->json([
                'daily_video' => $daily_video,
                'status' => 200
            ], 200);
        } else {
            return response()->json([
                'message' => 'No video found for today',
                'status' => 404
            ], 404);
        }
    }
}

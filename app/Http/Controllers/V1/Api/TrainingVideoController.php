<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use App\Models\TrainingVideo;
use Illuminate\Support\Facades\Validator;
use App\Rules\UniqueActive;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TrainingVideoController extends Controller
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
            // "showing_date.required" => "Showing Date Required",
            "day.required" => "Day Required",
            "session_type.required" => "Session Type Required",
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
        $query = TrainingVideo::query();

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
        $training_videos = $query->orderBy($sort_column, $sort_direction)
            ->when($page_size > 0, function ($q) use ($page_size, $page_number) {
                return $q->skip(($page_number - 1) * $page_size)
                    ->take($page_size);
            })
            ->get()
            ->map(function ($training_video) {
                $training_video->created_at_formatted = $training_video->created_at
                    ? $training_video->created_at->format('d-m-Y h:i A')
                    : '-';
                $training_video->updated_at_formatted = $training_video->updated_at
                    ? $training_video->updated_at->format('d-m-Y h:i A')
                    : '-';
                return $training_video;
            });

        // Build the response
        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $training_videos,
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
                // "showing_date" => ['required', new UniqueActive('training_videos', 'showing_date', null, [])],
                "day" => 'required',
                "session_type" => 'required',
            ], $this->messages);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

            $auth_user_id = Auth::id();
            $w = new TrainingVideo();
            $w->title = $request->title;
            $w->description = $request->description;
            $w->youtube_link = $request->youtube_link;
            // $w->showing_date = $request->showing_date;
            $w->day = $request->day;
            $w->session_type = $request->session_type;
            if ($request->hasFile('video_path')) {
                $file = $request->file('video_path');
                $original_name = $file->getClientOriginalName();
                $modified_name = str_replace(' ', '_', $original_name);
                $video_full_name = date('d-m-y_H-i-s') .  $modified_name;
                $upload_path = 'uploads/training_video/';
                $video_url = $upload_path . $video_full_name;
                $file->move($upload_path, $video_full_name);
                $w->video_path  =  $video_url;
            }
            $w->is_active = $request->has('is_active') ? 1 : 0;
            $w->quiz_applicable = $request->has('quiz_applicable') ? 1 : 0;
            $w->created_by =  $auth_user_id;
            $w->updated_by =  $auth_user_id;
            $w->save();

            DB::commit();

            return response()->json(['message' => 'Created successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('TrainingVideo store failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
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
        $training_video = TrainingVideo::find($id);

        return response()->json([
            'success' => true,
            'data' => $training_video,
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

        $training_video = TrainingVideo::find($id);

        return response()->json([
            'success' => true,
            'data' => $training_video,
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
            $validator = Validator::make($request->all(), [
                "title" => 'required',
                "description" => 'required',
                // "showing_date" => ['required', new UniqueActive('training_videos', 'showing_date', $id, [])],
                "day" => 'required',
                "session_type" => 'required',
            ], $this->messages);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

            $auth_user_id = Auth::id();
            $w = TrainingVideo::find($id);
            if (!$w) {
                DB::rollBack();
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }
            $w->title = $request->title;
            $w->description = $request->description;
            $w->youtube_link = $request->youtube_link;
            // $w->showing_date = $request->showing_date;
            $w->day = $request->day;
            $w->session_type = $request->session_type;
            if ($request->hasFile('video_path')) {
                $file = $request->file('video_path');
                $original_name = $file->getClientOriginalName();
                $modified_name = str_replace(' ', '_', $original_name);
                $video_full_name = date('d-m-y_H-i-s') .  $modified_name;
                $upload_path = 'uploads/training_video/';
                $video_url = $upload_path . $video_full_name;
                $file->move($upload_path, $video_full_name);
                $w->video_path = $video_url;
            }

            $w->quiz_applicable = $request->has('quiz_applicable') ? 1 : 0;
            $w->is_active = $request->has('is_active') ? 1 : 0;
            $w->updated_by =  $auth_user_id;
            $w->save();

            DB::commit();

            return response()->json(['message' => 'Updated successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('TrainingVideo update failed', ['id' => $id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
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

            $u = TrainingVideo::find($id);
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
            Log::error('TrainingVideo destroy failed', ['id' => $id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    public function StatusUpdate(Request $request)
    {

        $auth_user_id = auth()->user()->id;
        $w = TrainingVideo::find($request->id);
        $w->is_active = $request->has('is_active') ? 1 : 0;
        $w->updated_by =  $auth_user_id;
        $w->save();

        return response()->json(['message' => 'Training Video Details updated successfully', 'status' => 200]);
    }
}

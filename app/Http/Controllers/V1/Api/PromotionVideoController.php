<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PromotionVideo;
use Illuminate\Support\Facades\Validator;
use App\Rules\UniqueActive;
class PromotionVideoController extends Controller
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
            "video_order.required" => "Video Order Required",
            "session_type.required" => "Session Type Required",
        ];
    }
    public function index(Request $request)
    {
        if ($request->query('is_pagination') == 1) {

            // Get parameters from the request or set default values
            $current_page_number = $request->query('current_page_num', 1);
            $row_per_page = $request->query('limit', 10);

            // Calculate skip count based on current page and rows per page
            $skip_count = ($current_page_number - 1) * $row_per_page;

            // Define default sorting column and direction
            $sort_column = 'created_at';
            $sort_direction = 'asc';
            // Get the search term from the request
            $search_term = $request->query('search', '');
            // Retrieve shops based on pagination, sorting, and filtering
            $promotion_videos = PromotionVideo::where(['is_active' => 1, 'is_deleted' => 0])
                ->where(function ($query) use ($search_term) {
                    if (isset($search_term)) {

                        $query->where('title', 'LIKE', '%' . $search_term . '%');
                    }
                })

                ->orderBy($sort_column, $sort_direction)
                ->skip($skip_count)
                ->take($row_per_page == -1 ? PromotionVideo::count() : $row_per_page)
                ->get()->map(function ($promotion_video) {
                    $promotion_video->created_at_formatted =  $promotion_video->created_at->format('d-m-Y h:i A');
                    $promotion_video->updated_at_formatted = $promotion_video->updated_at->format('d-m-Y h:i A');
                    return $promotion_video;
                });

            // Calculate total records and total pages
            $total_records = PromotionVideo::where(['is_active' => 1, 'is_deleted' => 0])->where(function ($query) use ($search_term) {
                $query->where('title', 'LIKE', '%' . $search_term . '%');
            })->count();
            $total_pages = ceil($total_records / $row_per_page);

            // Return data as JSON response with the expected structure
            return response()->json([
                'promotion_videos' => $promotion_videos,
                'count' => $total_records,
                'next' => $total_pages > $current_page_number ? $current_page_number + 1 : null,
            ], 200);
        } else {
            // Retrieve categories based on pagination, sorting, and filtering
            $promotion_videos = PromotionVideo::where(['is_active' => 1, 'is_deleted' => 0])

                ->get()->map(function ($promotion_video) {
                    $promotion_video->created_at_formatted =  $promotion_video->created_at->format('d-m-Y h:i A');
                    $promotion_video->updated_at_formatted = $promotion_video->updated_at->format('d-m-Y h:i A');
                    return $promotion_video;
                });

            // Return data as JSON response with the expected structure
            return response()->json([
                'promotion_videos' => $promotion_videos,

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
            "showing_date" => ['required', new UniqueActive('promotion_videos', 'showing_date', null, [])],
            "video_order" => 'required',
            "session_type" => 'required',
        ], $this->messages);

        if ($validator->fails()) {
            // Validation failed, return a JSON response with validation errors
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $auth_user_id = auth()->user()->id;
        $w = PromotionVideo::create();
        $w->title = $request->title;
        $w->description = $request->description;
        $w->youtube_link = $request->youtube_link;
        $w->showing_date = $request->showing_date;
        $w->video_order = $request->video_order;
        // 1 to 4 inside session
        $w->session_type = $request->session_type;
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
        return response()->json(['message' => 'New Promotion Video Created successfully', 'status' => 200,]);
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

        $promotion_video = PromotionVideo::find($id);

        return response()->json([
            'promotion_video' => $promotion_video,

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
            "showing_date" => ['required', new UniqueActive('promotion_videos', 'showing_date',
             $id, [])],
            "video_order" => 'required',
            "session_type" => 'required',

        ], $this->messages);

        if ($validator->fails()) {
            // Validation failed, return a JSON response with validation errors
            return response()->json(['errors' => $validator->errors()], 422);
        }
        // user table email unique validation pending

        $auth_user_id = auth()->user()->id;
        $w = PromotionVideo::find($id);
        $w->title = $request->title;
        $w->description = $request->description;
        $w->youtube_link = $request->youtube_link;
        $w->showing_date = $request->showing_date;
        $w->video_order = $request->video_order;
        $w->session_type = $request->session_type;
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


        return response()->json(['message' => 'Promotion Video Details updated successfully', 'status' => 200]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $u = PromotionVideo::find($id);
        $u->is_deleted = 1;
        $u->updated_by = auth()->user()->id;
        $u->save();
        // ShopProductStock::where('shop_id', $id)->update(['is_active' => 0]);
        return response()->json(['status' => 200]);
    }

    public function StatusUpdate(Request $request)
    {

        $auth_user_id = auth()->user()->id;
        $w = PromotionVideo::find($request->id);
        $w->is_active = $request->has('is_active') ? 1 : 0;
        $w->updated_by =  $auth_user_id;
        $w->save();

        return response()->json(['message' => 'Promotion Video Details updated successfully', 'status' => 200]);
    }
}

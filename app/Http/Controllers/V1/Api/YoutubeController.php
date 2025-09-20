<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\YoutubeChannel;
use Illuminate\Support\Facades\Validator;
use App\Rules\UniqueActive;

class YoutubeController extends Controller
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
            "channel_name.required" => "Channel Name Required",
            "description.required" => "Description Required",
            "url.required" => "Url Required",
            "is_running.required" => "Is Running Required",
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
            $query = YoutubeChannel::where('is_deleted', 0);

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
                $query->where('channel_name', 'LIKE', '%' . $search_term . '%');
            }

            // Get total records for pageInfo
            $total_records = $query->count();

            // Apply pagination
            $youtube_channels_query = $query
                ->orderBy($sort_column, $sort_direction);
            // Apply pagination only if page_size is valid
            if ($page_size > 0) {
                $youtube_channels_query->skip(($page_number - 1) * $page_size)
                    ->take($page_size);
            }
            $youtube_channels = $youtube_channels_query
                ->get()->map(function ($youtube_channel) {
                    $youtube_channel->created_at_formatted =  $youtube_channel->created_at->format('d-m-Y h:i A');
                    $youtube_channel->updated_at_formatted = $youtube_channel->updated_at->format('d-m-Y h:i A');
                    return $youtube_channel;
                });

            // Build the response
            return response()->json([
                'success' => true,
                'message' => 'success',
                'data' => $youtube_channels,
                'pageInfo' => [
                    'page_size' => $page_size,
                    'page_number' => $page_number,
                    'recordsTotal' => $total_records
                ]
            ], 200);
        } else {
            // Retrieve categories based on pagination, sorting, and filtering
            $youtube_channels = YoutubeChannel::where(['is_active' => 1, 'is_deleted' => 0])

                ->get()->map(function ($youtube_channel) {
                    $youtube_channel->created_at_formatted =  $youtube_channel->created_at->format('d-m-Y h:i A');
                    $youtube_channel->updated_at_formatted = $youtube_channel->updated_at->format('d-m-Y h:i A');
                    return $youtube_channel;
                });

            // Return data as JSON response with the expected structure
            return response()->json([
                'youtube_channels' => $youtube_channels,

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
            "channel_name" => 'required',
            "description" => 'required',
            "url" => 'required',
            "is_running" => 'required',
        ], $this->messages);

        if ($validator->fails()) {
            // Validation failed, return a JSON response with validation errors
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $auth_user_id = auth()->user()->id;
        $w = YoutubeChannel::create();
        $w->channel_name = $request->channel_name;
        $w->description = $request->description;
        $w->url = $request->url;
        $w->is_running = $request->is_running;
        $w->is_active = $request->has('is_active') ? 1 : 0;
        $w->created_by =  $auth_user_id;
        $w->updated_by =  $auth_user_id;
        $w->save();



        // create one user with role 8
        return response()->json(['message' => 'New Youtube Channel Created successfully', 'status' => 200,]);
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

        $youtube_channel = YoutubeChannel::find($id);

        return response()->json([
            'youtube_channel' => $youtube_channel,

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
            "channel_name" => 'required',
            "description" => 'required',
            "url" => 'required',
            "is_running" => 'required',

        ], $this->messages);

        if ($validator->fails()) {
            // Validation failed, return a JSON response with validation errors
            return response()->json(['errors' => $validator->errors()], 422);
        }
        // user table email unique validation pending

        $auth_user_id = auth()->user()->id;
        $w = YoutubeChannel::find($id);
        $w->channel_name = $request->channel_name;
        $w->description = $request->description;
        $w->url = $request->url;
        $w->is_running = $request->is_running;


        $w->is_active = $request->has('is_active') ? 1 : 0;
        $w->updated_by =  $auth_user_id;
        $w->save();


        return response()->json(['message' => 'Youtube Channel Details updated successfully', 'status' => 200]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $u = YoutubeChannel::find($id);
        $u->is_deleted = 1;
        $u->updated_by = auth()->user()->id;
        $u->save();
        // ShopProductStock::where('shop_id', $id)->update(['is_active' => 0]);
        return response()->json(['status' => 200]);
    }

    public function StatusUpdate(Request $request)
    {

        $auth_user_id = auth()->user()->id;
        $w = YoutubeChannel::find($request->id);
        $w->is_active = $request->has('is_active') ? 1 : 0;
        $w->updated_by =  $auth_user_id;
        $w->save();

        return response()->json(['message' => 'Youtube Channel Details updated successfully', 'status' => 200]);
    }
}

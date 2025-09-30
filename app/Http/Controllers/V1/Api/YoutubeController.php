<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\YoutubeChannel;
use Illuminate\Support\Facades\Validator;
use App\Rules\UniqueActive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        // Default sorting
        $sort_column = $request->query('sort_column', 'created_at');
        $sort_direction = $request->query('sort_direction', 'DESC');

        // Pagination parameters
        $page_size = (int) $request->query('page_size', 10);
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
        $query = YoutubeChannel::query();
        $query->where('is_deleted', 0);

        // Apply search_param filters
        foreach ($search_param as $key => $value) {
            if (is_array($value)) {
                if ($key === 'date_between' && count($value) === 2) {
                    $query->whereBetween('created_at', $value);
                } elseif (!empty($value)) {
                    $query->whereIn($key, $value);
                }
            } else {
                if ($value !== '') {
                    $query->where($key, $value);
                }
            }
        }

        // Apply search across specified columns or default to channel_name
        if (!empty($search_term)) {
            $columns = [];
            if (!empty($search_param['search_columns']) && is_array($search_param['search_columns'])) {
                $columns = $search_param['search_columns'];
            } else {
                $columns = ['channel_name', 'description'];
            }
            $query->where(function ($q) use ($columns, $search_term) {
                foreach ($columns as $i => $col) {
                    if ($i === 0) {
                        $q->where($col, 'LIKE', '%' . $search_term . '%');
                    } else {
                        $q->orWhere($col, 'LIKE', '%' . $search_term . '%');
                    }
                }
            });
        }

        // Get total records for pageInfo
        $total_records = $query->count();

        // Apply sorting and pagination
        $youtube_channels = $query->orderBy($sort_column, $sort_direction)
            ->when($page_size > 0, function ($q) use ($page_size, $page_number) {
                return $q->skip(($page_number - 1) * $page_size)
                         ->take($page_size);
            })
            ->get()
            ->map(function ($youtube_channel) {
                $youtube_channel->created_at_formatted = optional($youtube_channel->created_at)->format('d-m-Y h:i A');
                $youtube_channel->updated_at_formatted = optional($youtube_channel->updated_at)->format('d-m-Y h:i A');
                return $youtube_channel;
            });

        // Build the response
        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $youtube_channels,
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
            $validator = Validator::make($request->all(), [
                "channel_name" => 'required',
                "description" => 'required',
                "url" => 'required',
                "is_running" => 'required|boolean',
            ], $this->messages);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

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

            DB::commit();

            return response()->json(['message' => 'Created successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('YoutubeChannel store failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
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
        $youtube_channel = YoutubeChannel::find($id);

        return response()->json([
            'success'=>true,
            'data' => $youtube_channel,

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

        $youtube_channel = YoutubeChannel::find($id);

        return response()->json([
            'success'=>true,
            'data' => $youtube_channel,

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
                "channel_name" => 'required',
                "description" => 'required',
                "url" => 'required',
                "is_running" => 'required|boolean',
            ], $this->messages);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

            $auth_user_id = auth()->user()->id;
            $w = YoutubeChannel::find($id);
            if (!$w) {
                DB::rollBack();
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }
            $w->channel_name = $request->channel_name;
            $w->description = $request->description;
            $w->url = $request->url;
            $w->is_running = $request->is_running;
            $w->is_active = $request->has('is_active') ? 1 : 0;
            $w->updated_by =  $auth_user_id;
            $w->save();

            DB::commit();

            return response()->json(['message' => 'Updated successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('YoutubeChannel update failed', ['id' => $id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
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

            $u = YoutubeChannel::find($id);
            if (!$u) {
                DB::rollBack();
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }
            $u->is_deleted = 1;
            $u->updated_by = auth()->user()->id;
            $u->save();

            DB::commit();
            return response()->json(['message' => 'Deleted successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('YoutubeChannel destroy failed', ['id' => $id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
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

<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ReferralScratchLevel;
use App\Models\ReferralScratchRange;
use Illuminate\Support\Facades\Validator;
use App\Rules\UniqueActive;
use Illuminate\Support\Facades\Auth;

class ScratchSetupController extends Controller
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
            "promotor_level.required" => "Promotor Level Required",
            "start_range.required" => "Start Range Required",
            "end_range.required" => "End Range Required",
            "amount.required" => "Amount Required",
            "msg.required" => "Message Required",

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
            $referral_scratch_levels = ReferralScratchLevel::where(['is_active' => 1, 'is_deleted' => 0])
                ->orderBy($sort_column, $sort_direction)
                ->skip($skip_count)
                ->with(['ranges' => function ($query) {
                    $query->where(['is_active' => 1, 'is_deleted' => 0])
                        ->orderBy('order_no', 'asc');
                }])
                ->take($row_per_page == -1 ? ReferralScratchLevel::count() : $row_per_page)
                ->get()->map(function ($referral_scratch_level) {
                    $referral_scratch_level->created_at_formatted =  $referral_scratch_level->created_at->format('d-m-Y h:i A');
                    $referral_scratch_level->updated_at_formatted = $referral_scratch_level->updated_at->format('d-m-Y h:i A');
                    return $referral_scratch_level;
                });

            // Calculate total records and total pages
            $total_records = ReferralScratchLevel::where(['is_active' => 1, 'is_deleted' => 0])->count();
            $total_pages = ceil($total_records / $row_per_page);

            // Return data as JSON response with the expected structure
            return response()->json([
                'referral_scratch_levels' => $referral_scratch_levels,
                'count' => $total_records,
                'next' => $total_pages > $current_page_number ? $current_page_number + 1 : null,
            ], 200);
        } else {
            // Retrieve categories based on pagination, sorting, and filtering
            $referral_scratch_levels = ReferralScratchLevel::where(['is_active' => 1, 'is_deleted' => 0])
                ->with(['ranges' => function ($query) {
                    $query->where(['is_active' => 1, 'is_deleted' => 0])
                        ->orderBy('order_no', 'asc');
                }])
                ->get()->map(function ($referral_scratch_level) {
                    $referral_scratch_level->created_at_formatted =  $referral_scratch_level->created_at->format('d-m-Y h:i A');
                    $referral_scratch_level->updated_at_formatted = $referral_scratch_level->updated_at->format('d-m-Y h:i A');
                    return $referral_scratch_level;
                });

            // Return data as JSON response with the expected structure
            return response()->json([
                'referral_scratch_levels' => $referral_scratch_levels,

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
    public function store(Request $request) {}

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
    public function edit($id) {}

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $auth_user_id = Auth::id();

        $validator = Validator::make($request->all(), [
            'ranges' => 'required|array|min:1',
            'ranges.*.start_range' => 'required|integer',
            'ranges.*.end_range' => 'required|integer',
            'ranges.*.amount' => 'required|integer',
            'ranges.*.msg' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            // Validation failed, return a JSON response with validation errors
            return response()->json(['errors' => $validator->errors()], 422);
        }
        // user table email unique validation pending

        
        $w = ReferralScratchLevel::find($id);
        $w->is_active = $request->has('is_active') ? 1 : 0;
        $w->updated_by =  $auth_user_id;
        $w->save();
        ReferralScratchRange::where('referral_scratch_level_id', $id)->update(['is_deleted' => 1]);
        foreach ($request->ranges ?? [] as $index => $rangeData) {
            $range = ReferralScratchRange::create();
            $range->referral_scratch_level_id = $w->id;
            $range->start_range = $rangeData['start_range'];
            $range->end_range = $rangeData['end_range'];
            $range->amount = $rangeData['amount'];
            $range->msg = $rangeData['msg'];
            $range->order_no = $index +1;
            $range->created_by =  $auth_user_id;
            $range->updated_by =  $auth_user_id;
            $range->save();
        }
        return response()->json(['message' => 'Referral Scratch Level updated successfully', 'status' => 200]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        // Soft delete the quiz
        $u = ReferralScratchLevel::find($id);
        $u->is_deleted = 1;
        $u->updated_by = Auth::id();
        $u->save();

        ReferralScratchRange::where('referral_scratch_level_id', $id)->update(['is_deleted' => 1]);

        return response()->json(['status' => 200]);
    }


    public function StatusUpdate(Request $request)
    {

        $auth_user_id = Auth::id();
        $w = ReferralScratchLevel::find($request->id);
        $w->is_active = $request->has('is_active') ? 1 : 0;
        $w->updated_by =  $auth_user_id;
        $w->save();

        return response()->json(['message' => 'Referral Scratch Level updated successfully', 'status' => 200]);
    }
}

<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\EarningHistory;
use App\Models\PromotionQuizChoice;
use App\Models\PromotionQuizQuestion;
use Illuminate\Http\Request;
use App\Models\PromotionVideo;
use App\Models\User;
use App\Models\UserPromoter;
use App\Models\UserPromoterSession;
use App\Models\UserReferral;
use Illuminate\Support\Facades\Validator;
use App\Rules\UniqueActive;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        // Start building the query
        $query = PromotionVideo::query();
        $query->where('is_deleted', 0);

        // Apply search_param filters
        foreach ($search_param as $key => $value) {
            if (is_array($value)) {
                if ($key === 'date_between' && count($value) === 2) {
                    $query->whereBetween('showing_date', $value);
                } elseif (!empty($value)) {
                    $query->whereIn($key, $value);
                }
            } else {
                if ($value !== '') {
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

        // Apply sorting, eager loading, and pagination
        $promotion_videos = $query
            ->with([
                'quiz' => function ($quizQuery) {
                    $quizQuery->where('is_deleted', 0)
                        ->with([
                            'questions' => function ($questionQuery) {
                                $questionQuery->where('is_deleted', 0)
                                    ->with([
                                        'choices' => function ($choiceQuery) {
                                            $choiceQuery->where('is_deleted', 0);
                                        }
                                    ]);
                            }
                        ]);
                }
            ])
            ->orderBy($sort_column, $sort_direction)
            ->when($page_size > 0, function ($q) use ($page_size, $page_number) {
                return $q->skip(($page_number - 1) * $page_size)
                    ->take($page_size);
            })
            ->get()
            ->map(function ($promotion_video) {
                $promotion_video->created_at_formatted = $promotion_video->created_at
                    ? $promotion_video->created_at->format('d-m-Y h:i A')
                    : '-';
                $promotion_video->updated_at_formatted =  $promotion_video->updated_at
                    ? $promotion_video->updated_at->format('d-m-Y h:i A')
                    : '-';
                return $promotion_video;
            });

        // Build the response
        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $promotion_videos,
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
                "showing_date" => [
                    'required',
                    new UniqueActive(
                        'promotion_videos',
                        'showing_date',
                        null,
                        [
                            'video_order' => $request->video_order,
                            'session_type' => $request->session_type
                        ]
                    )
                ],
                "video_order" => 'required',
                "session_type" => 'required',
            ], $this->messages);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

            $auth_user_id = Auth::id();
            $w = new PromotionVideo();
            $w->title = $request->title;
            $w->description = $request->description;
            $w->youtube_link = $request->youtube_link;
            $w->showing_date = $request->showing_date;
            $w->video_order = $request->video_order;
            $w->session_type = $request->session_type;
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
            Log::error('PromotionVideo store failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
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
        $promotion_video = PromotionVideo::find($id);

        return response()->json([
            'success' => true,
            'data' => $promotion_video,
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

        $promotion_video = PromotionVideo::find($id);

        return response()->json([
            'success' => true,
            'data' => $promotion_video,
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
                "showing_date" => [
                    'required',
                    new UniqueActive(
                        'promotion_videos',
                        'showing_date',
                        $id,
                        [
                            'video_order' => $request->video_order,
                            'session_type' => $request->session_type
                        ]
                    )
                ],
                "video_order" => 'required',
                "session_type" => 'required',

            ], $this->messages);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

            $auth_user_id = Auth::id();
            $w = PromotionVideo::find($id);
            if (!$w) {
                DB::rollBack();
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }
            $w->title = $request->title;
            $w->description = $request->description;
            $w->youtube_link = $request->youtube_link;
            $w->showing_date = $request->showing_date;
            $w->video_order = $request->video_order;
            $w->session_type = $request->session_type;
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
            Log::error('PromotionVideo update failed', ['id' => $id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
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

            $u = PromotionVideo::find($id);
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
            Log::error('PromotionVideo destroy failed', ['id' => $id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    public function StatusUpdate(Request $request)
    {
        try {
            $auth_user_id = Auth::id();
            $w = PromotionVideo::find($request->id);
            if (!$w) {
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }
            $isActiveInput = $request->has('is_active') ? $request->input('is_active') : ($request->has('active') ? $request->input('active') : 1);
            $w->is_active = (int) $isActiveInput ? 1 : 0;
            $w->updated_by =  $auth_user_id;
            $w->save();

            return response()->json(['message' => 'Promotion Video Details updated successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            Log::error('PromotionVideo status update failed', ['id' => $request->id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }
    public function userPromotionVideo()
    {
        try {
            $auth_user_id = Auth::id();
            $user = User::find($auth_user_id);
            if ($user->current_promoter_level === null) {
                return response()->json(['message' => 'User is not a promoter', 'status' => 400], 400);
            }

            // commented this code to bypass the alrearedy promotor to view promotions

            // switch ($user->promoter_status) {
            //     case User::PROMOTER_STATUS_PENDING:
            //         return response()->json(['message' => 'User promoter approval pending', 'status' => 400], 400);
            //     case User::PROMOTER_STATUS_REJECTED:
            //         return response()->json(['message' => 'User promoter approval rejected', 'status' => 400], 400);
            //     case User::PROMOTER_STATUS_SHOW_TERM:
            //         return response()->json(['message' => 'User promoter show term pending', 'status' => 400], 400);
            //     case User::PROMOTER_STATUS_ACCEPTED_TERM:
            //         return response()->json(['message' => 'User promoter  accepted term pending', 'status' => 400], 400);
            //     case User::PROMOTER_STATUS_APPROVED:
            //         return response()->json(['message' => 'User promoter approved but not yet activated', 'status' => 400], 400);
            // }

            $user_promoter = UserPromoter::where('user_id', $auth_user_id)
                ->where('status', UserPromoter::PIN_STATUS_ACTIVATED)->orderBy('level','DESC')->first();
            if (!$user_promoter) {
                return response()->json(['message' => 'User promoter not found', 'status' => 400], 400);
            }
            $current_session_type = (Carbon::now()->hour < 12) ? 1 : 2;
            $user_promoter_session = UserPromoterSession::where('user_id', $auth_user_id)
                ->where('user_promoter_id', $user_promoter->id)->whereDate('attend_at', today())
                ->where('session_type', $current_session_type)
                ->orderBy('id', 'desc')
                ->first();
            if (!$user_promoter_session) {
                $user_promoter_session = new UserPromoterSession();
                $user_promoter_session->user_id = $auth_user_id;
                $user_promoter_session->user_promoter_id = $user_promoter->id;
                $user_promoter_session->current_video_order_set1 = UserPromoterSession::SET1_VIDEO_ORDER_1;
                $user_promoter_session->session_type = $current_session_type;
                $user_promoter_session->session_status = 0;
                $user_promoter_session->attend_at = today();
                $user_promoter_session->save();
            }

            if ($user_promoter_session->set1_status > 2 && $user->current_promoter_level < 3) {
                return response()->json(['message' => 'Session already completed or expired', 'status' => 200], 200);
            } elseif ($user_promoter_session->set2_status > 2 && $user->current_promoter_level > 2) {
                return response()->json(['message' => 'Session already completed or expired', 'status' => 200], 200);
            }

            $currentSet = ($user_promoter_session->set1_status > 2) ? 2 : 1;
            $currentOrder = ($currentSet === 1)
                ? $user_promoter_session->current_video_order_set1
                : $user_promoter_session->current_video_order_set2;
            if (
                $currentSet == 1 && $currentOrder == 1 &&
                $user_promoter_session->set1_status == 2
            ) {
                $user_promoter_session->current_video_order_set1 = UserPromoterSession::SET1_VIDEO_ORDER_2;
                $currentOrder = UserPromoterSession::SET1_VIDEO_ORDER_2;
                $user_promoter_session->earned_amount_set1 = 0;
                $user_promoter_session->set1_status = 0;
                $user_promoter_session->save();
            }
            if (
                $currentSet == 2 && $currentOrder == 3 &&
                $user_promoter_session->set2_status == 2
            ) {
                $user_promoter_session->current_video_order_set2 = 4;
                $currentOrder = 4;
                $user_promoter_session->earned_amount_set2 = 0;
                $user_promoter_session->set2_status = 0;
                $user_promoter_session->save();
            }

            $promotion_video = PromotionVideo::where('is_active', 1)
                ->whereDate('showing_date', today())
                ->where('is_deleted', 0)
                ->where('session_type', $current_session_type)
                ->where('video_order', $currentOrder)
                ->with([
                    'quiz' => function ($quizQuery) {
                        $quizQuery->where('is_deleted', 0)
                            ->select(
                                "id",
                                "promotion_video_id",
                            )
                            ->with([
                                'questions' => function ($questionQuery) {
                                    $questionQuery->where('is_deleted', 0)
                                        ->select('id', 'promotion_video_quiz_id', 'lang_type', 'question', 'time_limit')
                                        ->with([
                                            'choices' => function ($choiceQuery) {
                                                $choiceQuery->where('is_deleted', 0)
                                                    ->select('id', 'promotion_quiz_question_id', 'lang_type', 'choice_value');
                                            }
                                        ]);
                                }
                            ]);
                    }
                ])
                ->select(
                    "id",
                    "title",
                    "description",
                    "video_path",
                    "youtube_link",
                    "showing_date",
                    "video_order",
                    "session_type",
                )
                ->first();

            if ($promotion_video && $promotion_video->quiz) {
                $quiz = $promotion_video->quiz;

                if ($quiz->questions && $quiz->questions->count() > 0) {
                    $questionsByLang = $quiz->questions->groupBy('lang_type');
                    $shuffledQuestions = collect();
                    foreach ($questionsByLang as $langType => $questions) {
                        $selectedQuestions = $questions->shuffle()->take(3);
                        $shuffledQuestions = $shuffledQuestions->merge($selectedQuestions);
                    }
                    $quiz->setRelation('questions', $shuffledQuestions);
                }
            }

            if (!$promotion_video) {
                return response()->json(['message' => 'No promotion video available for this session', 'status' => 400], 400);
            }
            $user_promoter_session = UserPromoterSession::find($user_promoter_session->id);
            $data = [
                'promotion_video' => $promotion_video,
                "user_promoter_session" => $user_promoter_session
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('PromotionVideo userPromotionVideo failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }
    public function userPromoterQuizResult(Request $request)
    {
        try {
            $auth_user_id = Auth::id();
            $auth_user = User::find($auth_user_id);
            $questions_with_selected_choice = $request->questions;
            $promotion_video_id = $request->promotion_video_id;

            $promotion_video = PromotionVideo::find($promotion_video_id);
            if (!$promotion_video) {
                return response()->json(['message' => 'Promotion video not found', 'status' => 400], 400);
            }
            $current_session_type = (Carbon::now()->hour < 12) ? 1 : 2;
            if ($current_session_type != $promotion_video->session_type) {
                return response()->json(['message' => 'Session Expired', 'status' => 400], 400);
            }
            $user_promoter = UserPromoter::where('user_id', $auth_user_id)
                ->where('status', UserPromoter::PIN_STATUS_ACTIVATED)->orderBy('level','DESC')->first();
            if (!$user_promoter) {
                return response()->json(['message' => 'User promoter not found', 'status' => 400], 400);
            }

            $user_promoter_session = UserPromoterSession::where('user_id', $auth_user_id)
                ->where('user_promoter_id', $user_promoter->id)->whereDate('attend_at', today())
                ->where('session_type', $current_session_type)
                ->orderBy('id', 'desc')
                ->first();
            if (!$user_promoter_session) {
                return response()->json(['message' => 'User promoter session expired', 'status' => 400], 400);
            }
            $total_earning = 0;
            $default_video_total_earnable_amount = 2.5;
            $max_earnable_per_video = 2.5;
            switch ($user_promoter->level) {
                case 0:
                    $default_video_total_earnable_amount = 2.5;
                    $max_earnable_per_video = 2.5;
                    break;
                case 1:
                    $default_video_total_earnable_amount = 5;
                    $max_earnable_per_video = 35;
                    break;
                case 2:
                    $default_video_total_earnable_amount = 50;
                    $max_earnable_per_video = 230;
                    break;
                case 3:
                    $default_video_total_earnable_amount = 62.5;
                    $max_earnable_per_video = 182.5;
                    break;
                case 4:
                    $default_video_total_earnable_amount = 92.5;
                    $max_earnable_per_video = 265;
                    break;
            }
            $video_total_earnable_amount = $default_video_total_earnable_amount;
            if ($user_promoter->level > 0) {
                $referred_users = User::where([
                    'referred_by' => $auth_user_id,
                    'is_deleted' => 0,
                    "is_active" => 1,
                    "promoter_status" => User::PROMOTER_STATUS_ACTIVATED
                ])->where('current_promoter_level', '<=', $user_promoter->level)
                    ->where('current_promoter_level', '!=', 0)->get();
                foreach ($referred_users as $referred_user) {
                    $add_amount = match ($referred_user->current_promoter_level) {
                        1 => 2.5,
                        2 => 25,
                        3 => 17.5,
                        4 => 25,
                        default => 0,
                    };
                    $remaining_allowance = $max_earnable_per_video - $video_total_earnable_amount;
                    if ($remaining_allowance <= 0) {
                        break;
                    }
                    if ($add_amount > $remaining_allowance) {
                        $video_total_earnable_amount += $remaining_allowance;
                        break;
                    } else {
                        $video_total_earnable_amount += $add_amount;
                    }
                }
            }
            $correct_count = 0;
            $failed_questions_count = 0;
            $total_questions = count($questions_with_selected_choice);

            foreach ($questions_with_selected_choice as $question) {
                $choice = PromotionQuizChoice::find($question['choice_id']);
                if ($choice && $choice->is_correct == 1) {
                    $correct_count++;
                } else {
                    $failed_questions_count++;
                }
            }
            $percentage_correct = ($total_questions > 0) ? ($correct_count / $total_questions) : 0;
            $total_earning = round($video_total_earnable_amount * $percentage_correct, 2);

            $user_promoter_session = UserPromoterSession::where('user_id', $auth_user_id)
                ->where('user_promoter_id', $user_promoter->id)->whereDate('attend_at', today())
                ->where('session_type', $current_session_type)
                ->orderBy('id', 'desc')
                ->first();
            $currentSet = ($user_promoter_session->set1_status > 2) ? 2 : 1;
            if ($currentSet == 1) {
                $user_promoter_session->set1_status = 2;
                $user_promoter_session->earned_amount_set1 = $total_earning;
                $user_promoter_session->save();
            } else {
                $user_promoter_session->set2_status = 2;
                $user_promoter_session->earned_amount_set2 = $total_earning;
                $user_promoter_session->save();
            }
            $retry = false;
            if ($user_promoter_session->set1_status <= 2) {
                if ($user_promoter_session->current_video_order_set1 == 1) {
                    $retry = true;
                }
            } else {
                if ($user_promoter_session->set2_status <= 2 && $user_promoter_session->current_video_order_set2 == 3) {
                    $retry = true;
                }
            }

            $data = [
                'total_questions' => $total_questions,
                'correct_count' => $correct_count,
                'failed_questions_count' => $failed_questions_count,
                'percentage_correct' => $percentage_correct * 100,
                'total_earning' => $total_earning,
                'user_promoter_session' => $user_promoter_session,
                'retry' => $retry
            ];

            return response()->json([
                'message' => 'Promotion video quiz result calculated successfully',
                'status' => 200,
                'data' => $data
            ], 200);
        } catch (\Throwable $e) {
            Log::error('PromotionVideo userPromoterQuizResult failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }
    public function userPromoterQuizResultConfirmation(Request $request)
    {
        try {
            DB::beginTransaction();
            $session_id = $request->user_promoter_session_id;
            $user_promoter_session = UserPromoterSession::find($session_id);
            if (!$user_promoter_session) {
                DB::rollBack();
                return response()->json(['message' => 'User promoter session not found', 'status' => 400], 400);
            }
            $earned_amount = 0;
            $earning_type = 1;
            $description = '';
            if ($user_promoter_session->set1_status == UserPromoterSession::SET1_STATUS_QUIZ_COMPLETED) {
                $user_promoter_session->set1_status = UserPromoterSession::SET1_STATUS_SUBMITTED;
                $user_promoter_session->session_status = 3;
                $user_promoter_session->save();
                $earned_amount = $user_promoter_session->earned_amount_set1;
                if ($user_promoter_session->session_type == UserPromoterSession::SESSION_TYPE_MORNING) {
                    $earning_type = EarningHistory::EARNING_TYPE_SESSION_1_SET_1_VIDEO;
                    $description = 'Morning Session Video Quiz ' . now()->toDateString();
                } else {
                    $earning_type = EarningHistory::EARNING_TYPE_SESSION_2_SET_1_VIDEO;
                    $description = 'Evening Session Video Quiz  ' . now()->toDateString();
                }
            } else {
                $user_promoter_session->set2_status = UserPromoterSession::SET2_STATUS_SUBMITTED;
                $user_promoter_session->session_status = 3;
                $user_promoter_session->save();
                $earned_amount = $user_promoter_session->earned_amount_set2;
                if ($user_promoter_session->session_type == UserPromoterSession::SESSION_TYPE_MORNING) {
                    $earning_type = EarningHistory::EARNING_TYPE_SESSION_1_SET_2_VIDEO;
                    $description = 'Morning Session Video  Quiz set2 ' . now()->toDateString();
                } else {
                    $earning_type = EarningHistory::EARNING_TYPE_SESSION_2_SET_2_VIDEO;
                    $description = 'Evening Session Video Quiz set2 ' . now()->toDateString();
                }
            }
            $saving_percentage = 5;
            $saving_amount = ($earned_amount * $saving_percentage) / 100;
            $main_wallet_amount = $earned_amount - $saving_amount;
            $earning_history = new EarningHistory();
            $earning_history->user_id = $user_promoter_session->user_id;
            $earning_history->amount = $main_wallet_amount;
            $earning_history->earning_date = today();
            $earning_history->earning_type = $earning_type;
            $earning_history->description = $description;
            $earning_history->earning_status = 1;
            $earning_history->save();
            $saving_earning_history = new EarningHistory();
            $saving_earning_history->user_id = $user_promoter_session->user_id;
            $saving_earning_history->amount = $saving_amount;
            $saving_earning_history->earning_date = today();
            $saving_earning_history->earning_type = EarningHistory::EARNING_TYPE_SAVINGS_EARNING;
            $saving_earning_history->description = $description;
            $saving_earning_history->earning_status = 1;
            $saving_earning_history->save();
            $user = User::find($user_promoter_session->user_id);
            $user->quiz_total_earning += $main_wallet_amount;
            $user->saving_total_earning += $saving_amount;
            $user->save();
            DB::commit();
            return response()->json([
                'message' => 'User promoter session confirmed successfully',
                'status' => 200,
                'data' => $user_promoter_session
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('PromotionVideo userPromoterQuizResultConfirmation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }
}

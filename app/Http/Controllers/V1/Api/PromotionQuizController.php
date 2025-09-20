<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\PromotionQuizChoice;
use App\Models\PromotionQuizQuestion;
use Illuminate\Http\Request;
use App\Models\PromotionVideoQuiz;
use Illuminate\Support\Facades\Validator;
use App\Rules\UniqueActive;

class PromotionQuizController extends Controller
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
            "promotion_video_id.required" => "Promotion Video ID Required",
            "questions.required" => "Questions Required",
            "questions.*.lang_type.required" => "Language Type Required",
            "questions.*.question.required" => "Question Required",
            "questions.*.time_limit.required" => "Time Limit Required",
            "questions.*.promotor.required" => "Promotor Required",
            "questions.*.promotor1.required" => "Promotor1 Required",
            "questions.*.promotor2.required" => "Promotor2 Required",
            "questions.*.promotor3.required" => "Promotor3 Required",
            "questions.*.promotor4.required" => "Promotor4 Required",
            "questions.*.choices.required" => "Choices Required",
            "questions.*.choices.*.lang_type.required" => "Language Type Required",
            "questions.*.choices.*.choice_value.required" => "Choice Value Required",
            "questions.*.choices.*.is_correct.required" => "Is Correct Required",
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
            $query = PromotionVideoQuiz::where('is_deleted', 0);

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

            // // Apply search filter on category_name
            // if (!empty($search_term)) {
            //     $query->where('title', 'LIKE', '%' . $search_term . '%');
            // }

            // Get total records for pageInfo
            $total_records = $query->count();

            // Apply pagination
            $promotion_video_quizzes_query = $query

                ->orderBy($sort_column, $sort_direction);
            // Apply pagination only if page_size is valid
            if ($page_size > 0) {
                $promotion_video_quizzes_query->skip(($page_number - 1) * $page_size)
                    ->take($page_size);
            }
            $promotion_video_quizzes = $promotion_video_quizzes_query
                ->get()->map(function ($promotion_video_quiz) {
                    $promotion_video_quiz->created_at_formatted =  $promotion_video_quiz->created_at->format('d-m-Y h:i A');
                    $promotion_video_quiz->updated_at_formatted = $promotion_video_quiz->updated_at->format('d-m-Y h:i A');
                    return $promotion_video_quiz;
                });

            // Build the response
            return response()->json([
                'success' => true,
                'message' => 'success',
                'data' => $promotion_video_quizzes,
                'pageInfo' => [
                    'page_size' => $page_size,
                    'page_number' => $page_number,
                    'recordsTotal' => $total_records
                ]
            ], 200);
        } else {
            // Retrieve categories based on pagination, sorting, and filtering
            $promotion_video_quizzes = PromotionVideoQuiz::where(['is_active' => 1, 'is_deleted' => 0])
                ->get()->map(function ($promotion_video_quiz) {
                    $promotion_video_quiz->created_at_formatted =  $promotion_video_quiz->created_at->format('d-m-Y h:i A');
                    $promotion_video_quiz->updated_at_formatted = $promotion_video_quiz->updated_at->format('d-m-Y h:i A');
                    return $promotion_video_quiz;
                });

            // Return data as JSON response with the expected structure
            return response()->json([
                'promotion_video_quizzes' => $promotion_video_quizzes,

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
            "promotion_video_id" => ['required', new UniqueActive('promotion_video_quizzes', 'promotion_video_id', null, [])],
            "questions" => 'required|array',
            "questions.*.lang_type" => 'required|in:1,2',
            "questions.*.question" => 'required|string',
            "questions.*.time_limit" => 'required|integer',
            "questions.*.promotor" => 'required|integer',
            "questions.*.promotor1" => 'required|integer',
            "questions.*.promotor2" => 'required|integer',
            "questions.*.promotor3" => 'required|integer',
            "questions.*.promotor4" => 'required|integer',
            "questions.*.choices" => 'required|array',
            "questions.*.choices.*.lang_type" => 'required|in:1,2',
            "questions.*.choices.*.choice_value" => 'required|string',
            "questions.*.choices.*.is_correct" => 'required|boolean',
        ], $this->messages);

        if ($validator->fails()) {
            // Validation failed, return a JSON response with validation errors
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $auth_user_id = auth()->user()->id;
        $w = PromotionVideoQuiz::create();
        $w->promotion_video_id = $request->promotion_video_id;
        $w->is_active = $request->has('is_active') ? 1 : 0;
        $w->created_by =  $auth_user_id;
        $w->updated_by =  $auth_user_id;
        $w->save();
        foreach ($request->questions ?? [] as $index => $question) {
            $promotion_quiz_question = PromotionQuizQuestion::create();
            $promotion_quiz_question->promotion_video_quiz_id = $w->id;
            // 1-english, 2-tamil
            $promotion_quiz_question->lang_type = $question['lang_type'];
            $promotion_quiz_question->question = $question['question'];
            $promotion_quiz_question->time_limit = $question['time_limit'];
            $promotion_quiz_question->promotor = $question['promotor'];
            $promotion_quiz_question->promotor1 = $question['promotor1'];
            $promotion_quiz_question->promotor2 = $question['promotor2'];
            $promotion_quiz_question->promotor3 = $question['promotor3'];
            $promotion_quiz_question->promotor4 = $question['promotor4'];
            $promotion_quiz_question->created_by =  $auth_user_id;
            $promotion_quiz_question->updated_by =  $auth_user_id;
            $promotion_quiz_question->save();
            foreach ($question['choices'] ?? [] as $index => $choice) {
                $promotion_quiz_choice = PromotionQuizChoice::create();
                $promotion_quiz_choice->promotion_quiz_question_id = $promotion_quiz_question->id;
                $promotion_quiz_choice->lang_type = $choice['lang_type'];
                $promotion_quiz_choice->choice_value = $choice['choice_value'];
                $promotion_quiz_choice->is_correct = $choice['is_correct'] ? 1 : 0;
                $promotion_quiz_choice->created_by =  $auth_user_id;
                $promotion_quiz_choice->updated_by =  $auth_user_id;
                $promotion_quiz_choice->save();
            }
        }


        // create one user with role 8
        return response()->json(['message' => 'New Promotion Video Quiz Created successfully', 'status' => 200,]);
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

        $promotion_video_quiz = PromotionVideoQuiz::where('id', $id)->with([
            'questions' => function ($q) {
                $q->where('is_active', 1)->where('is_deleted', 0);
            },
            'questions.choices' => function ($q) {
                $q->where('is_active', 1)->where('is_deleted', 0);
            },
            'promotion_video' => function ($q) {
                $q->where('is_active', 1)->where('is_deleted', 0);
            }
        ])
            ->first();

        return response()->json([
            'promotion_video_quiz' => $promotion_video_quiz,

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
            "promotion_video_id" => 'required',
            "questions" => 'required|array',
            "questions.*.lang_type" => 'required|in:1,2',
            "questions.*.question" => 'required|string',
            "questions.*.time_limit" => 'required|integer',
            "questions.*.promotor" => 'required|integer',
            "questions.*.promotor1" => 'required|integer',
            "questions.*.promotor2" => 'required|integer',
            "questions.*.promotor3" => 'required|integer',
            "questions.*.promotor4" => 'required|integer',
            "questions.*.choices" => 'required|array',
            "questions.*.choices.*.lang_type" => 'required|in:1,2',
            "questions.*.choices.*.choice_value" => 'required|string',
            "questions.*.choices.*.is_correct" => 'required|boolean',

        ], $this->messages);

        if ($validator->fails()) {
            // Validation failed, return a JSON response with validation errors
            return response()->json(['errors' => $validator->errors()], 422);
        }
        // user table email unique validation pending

        $auth_user_id = auth()->user()->id;
        $w = PromotionVideoQuiz::find($id);
        $w->promotion_video_id = $request->promotion_video_id;
        $w->type = $request->type;
        $w->is_active = $request->has('is_active') ? 1 : 0;
        $w->updated_by =  $auth_user_id;
        $w->save();
        PromotionQuizQuestion::where('promotion_video_quiz_id', $id)->update(['is_deleted' => 1]);
        foreach ($request->questions ?? [] as $index => $question) {

            $quiz_question = PromotionQuizQuestion::create();
            $quiz_question->promotion_video_quiz_id = $w->id;
            // 1-english, 2-tamil
            $quiz_question->lang_type = $question['lang_type'];
            $quiz_question->question = $question['question'];
            $quiz_question->time_limit = $question['time_limit'];
            $quiz_question->promotor = $question['promotor'];
            $quiz_question->promotor1 = $question['promotor1'];
            $quiz_question->promotor2 = $question['promotor2'];
            $quiz_question->promotor3 = $question['promotor3'];
            $quiz_question->promotor4 = $question['promotor4'];
            $quiz_question->created_by =  $auth_user_id;
            $quiz_question->updated_by =  $auth_user_id;
            $quiz_question->save();
            PromotionQuizChoice::where('promotion_quiz_question_id', $quiz_question->id)->update(['is_deleted' => 1]);
            foreach ($question['choices'] ?? [] as $index => $choice) {
                $quiz_choice = PromotionQuizChoice::create();
                $quiz_choice->promotion_quiz_question_id = $quiz_question->id;
                $quiz_choice->lang_type = $choice['lang_type'];
                $quiz_choice->choice_value = $choice['choice_value'];
                $quiz_choice->is_correct = $choice['is_correct'] ? 1 : 0;
                $quiz_choice->created_by =  $auth_user_id;
                $quiz_choice->updated_by =  $auth_user_id;
                $quiz_choice->save();
            }
        }

        return response()->json(['message' => 'Promotion Video Quiz Details updated successfully', 'status' => 200]);
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
        $u = PromotionVideoQuiz::find($id);
        $u->is_deleted = 1;
        $u->updated_by = auth()->user()->id;
        $u->save();

        // Soft delete related questions
        $questions = PromotionQuizQuestion::where('promotion_video_quiz_id', $id)->get();
        $questionIds = $questions->pluck('id'); // get all question IDs

        PromotionQuizQuestion::where('promotion_video_quiz_id', $id)->update(['is_deleted' => 1]);

        // Soft delete related choices
        PromotionQuizChoice::whereIn('promotion_quiz_question_id', $questionIds)->update(['is_deleted' => 1]);

        return response()->json(['status' => 200]);
    }


    public function StatusUpdate(Request $request)
    {

        $auth_user_id = auth()->user()->id;
        $w = PromotionVideoQuiz::find($request->id);
        $w->is_active = $request->has('is_active') ? 1 : 0;
        $w->updated_by =  $auth_user_id;
        $w->save();

        return response()->json(['message' => 'Promotion Video Quiz Details updated successfully', 'status' => 200]);
    }
}

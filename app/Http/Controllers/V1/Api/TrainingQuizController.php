<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\TrainingQuizChoice;
use App\Models\TrainingQuizQuestion;
use Illuminate\Http\Request;
use App\Models\TrainingVideoQuiz;
use Illuminate\Support\Facades\Validator;
use App\Rules\UniqueActive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TrainingQuizController extends Controller
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
            "training_video_id.required" => "Training Video ID Required",
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
        // Default sorting like PromotionVideoController
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
        $query = TrainingVideoQuiz::query();
        $query->where('is_deleted', 0);

        // Apply search_param filters (supports whereBetween, whereIn and simple where)
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

        // Optionally apply a generic search if a valid column is provided via search_param['search_columns']
        if (!empty($search_term) && !empty($search_param['search_columns']) && is_array($search_param['search_columns'])) {
            $columns = $search_param['search_columns'];
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

        // Get total records for pagination
        $total_records = $query->count();

        // Apply sorting and pagination
        $training_video_quizzes = $query->orderBy($sort_column, $sort_direction)
            ->when($page_size > 0, function ($q) use ($page_size, $page_number) {
                return $q->skip(($page_number - 1) * $page_size)
                         ->take($page_size);
            })
            ->get()
            ->map(function ($training_video_quiz) {
                $training_video_quiz->created_at_formatted = $training_video_quiz->created_at 
                    ? $training_video_quiz->created_at->format('d-m-Y h:i A') 
                    : '-';
                $training_video_quiz->updated_at_formatted = $training_video_quiz->updated_at 
                    ? $training_video_quiz->updated_at->format('d-m-Y h:i A') 
                    : '-';
                return $training_video_quiz;
            });

        // Build the response (same structure style as PromotionVideoController)
        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $training_video_quizzes,
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
                "training_video_id" => ['required', new UniqueActive('training_video_quizzes', 'training_video_id', null, [])],
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
                return response()->json(['errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

            $auth_user_id = auth()->user()->id;
            $w = TrainingVideoQuiz::create();
            $w->training_video_id = $request->training_video_id;
            // Use provided is_active/active when present; default to 1 when absent
            $isActiveInput = $request->has('is_active') ? $request->input('is_active') : ($request->has('active') ? $request->input('active') : 1);
            $w->is_active = (int) $isActiveInput ? 1 : 0;
            $w->created_by =  $auth_user_id;
            $w->updated_by =  $auth_user_id;
            $w->save();

            foreach ($request->questions ?? [] as $index => $question) {
                $training_quiz_question = TrainingQuizQuestion::create();
                $training_quiz_question->training_quiz_id = $w->id;
                // 1-english, 2-tamil
                $training_quiz_question->lang_type = $question['lang_type'];
                $training_quiz_question->question = $question['question'];
                $training_quiz_question->time_limit = $question['time_limit'];
                $training_quiz_question->promotor = $question['promotor'];
                $training_quiz_question->promotor1 = $question['promotor1'];
                $training_quiz_question->promotor2 = $question['promotor2'];
                $training_quiz_question->promotor3 = $question['promotor3'];
                $training_quiz_question->promotor4 = $question['promotor4'];
                $training_quiz_question->created_by =  $auth_user_id;
                $training_quiz_question->updated_by =  $auth_user_id;
                $training_quiz_question->save();

                foreach ($question['choices'] ?? [] as $index => $choice) {
                    $training_quiz_choice = TrainingQuizChoice::create();
                    $training_quiz_choice->training_quiz_question_id = $training_quiz_question->id;
                    $training_quiz_choice->lang_type = $choice['lang_type'];
                    $training_quiz_choice->choice_value = $choice['choice_value'];
                    $training_quiz_choice->is_correct = $choice['is_correct'] ? 1 : 0;
                    $training_quiz_choice->created_by =  $auth_user_id;
                    $training_quiz_choice->updated_by =  $auth_user_id;
                    $training_quiz_choice->save();
                }
            }

            DB::commit();

            return response()->json(['message' => 'Created successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('TrainingVideoQuiz store failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
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
        $training_video_quiz = TrainingVideoQuiz::where('id', $id)->with([
            'questions' => function ($q) {
                $q->where('is_active', 1)->where('is_deleted', 0);
            },
            'questions.choices' => function ($q) {
                $q->where('is_active', 1)->where('is_deleted', 0);
            },
            'training_video' => function ($q) {
                $q->where('is_active', 1)->where('is_deleted', 0);
            }
        ])
            ->first();

        return response()->json([
            'success' => true,
            'data' => $training_video_quiz,
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

        $training_video_quiz = TrainingVideoQuiz::where('id', $id)->with([
            'questions' => function ($q) {
                $q->where('is_active', 1)->where('is_deleted', 0);
            },
            'questions.choices' => function ($q) {
                $q->where('is_active', 1)->where('is_deleted', 0);
            },
            'training_video' => function ($q) {
                $q->where('is_active', 1)->where('is_deleted', 0);
            }
        ])
            ->first();

        return response()->json([
            'success' => true,
            'data' => $training_video_quiz,
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
                "training_video_id" => 'required',
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
                return response()->json(['errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

            $auth_user_id = auth()->user()->id;
            $w = TrainingVideoQuiz::find($id);
            if (!$w) {
                DB::rollBack();
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }
            $w->training_video_id = $request->training_video_id;
            // Use provided is_active/active when present; default to 1 when absent
            $isActiveInput = $request->has('is_active') ? $request->input('is_active') : ($request->has('active') ? $request->input('active') : 1);
            $w->is_active = (int) $isActiveInput ? 1 : 0;
            $w->updated_by =  $auth_user_id;
            $w->save();

            // Soft delete old questions and choices
            $oldQuestions = TrainingQuizQuestion::where('training_quiz_id', $id)->get();
            $oldQuestionIds = $oldQuestions->pluck('id');
            TrainingQuizQuestion::where('training_quiz_id', $id)->update(['is_deleted' => 1]);
            if ($oldQuestionIds->count() > 0) {
                TrainingQuizChoice::whereIn('training_quiz_question_id', $oldQuestionIds)->update(['is_deleted' => 1]);
            }

            // Create new questions and choices
            foreach ($request->questions ?? [] as $index => $question) {
                $training_quiz_question = TrainingQuizQuestion::create();
                $training_quiz_question->training_quiz_id = $w->id;
                // 1-english, 2-tamil
                $training_quiz_question->lang_type = $question['lang_type'];
                $training_quiz_question->question = $question['question'];
                $training_quiz_question->time_limit = $question['time_limit'];
                $training_quiz_question->promotor = $question['promotor'];
                $training_quiz_question->promotor1 = $question['promotor1'];
                $training_quiz_question->promotor2 = $question['promotor2'];
                $training_quiz_question->promotor3 = $question['promotor3'];
                $training_quiz_question->promotor4 = $question['promotor4'];
                $training_quiz_question->created_by =  $auth_user_id;
                $training_quiz_question->updated_by =  $auth_user_id;
                $training_quiz_question->save();

                foreach ($question['choices'] ?? [] as $index => $choice) {
                    $training_quiz_choice = TrainingQuizChoice::create();
                    $training_quiz_choice->training_quiz_question_id = $training_quiz_question->id;
                    $training_quiz_choice->lang_type = $choice['lang_type'];
                    $training_quiz_choice->choice_value = $choice['choice_value'];
                    $training_quiz_choice->is_correct = $choice['is_correct'] ? 1 : 0;
                    $training_quiz_choice->created_by =  $auth_user_id;
                    $training_quiz_choice->updated_by =  $auth_user_id;
                    $training_quiz_choice->save();
                }
            }

            DB::commit();

            return response()->json(['message' => 'Updated successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('TrainingVideoQuiz update failed', ['id' => $id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
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

            $u = TrainingVideoQuiz::find($id);
            if (!$u) {
                DB::rollBack();
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }

            // Soft delete the quiz
            $u->is_deleted = 1;
            $u->updated_by = auth()->user()->id;
            $u->save();

            // Soft delete related questions and choices
            $questions = TrainingQuizQuestion::where('training_quiz_id', $id)->get();
            $questionIds = $questions->pluck('id');
            TrainingQuizQuestion::where('training_quiz_id', $id)->update(['is_deleted' => 1]);
            if ($questionIds->count() > 0) {
                TrainingQuizChoice::whereIn('training_quiz_question_id', $questionIds)->update(['is_deleted' => 1]);
            }

            DB::commit();
            return response()->json(['message' => 'Deleted successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('TrainingVideoQuiz destroy failed', ['id' => $id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }
    

    public function StatusUpdate(Request $request)
    {
        try {
            $auth_user_id = auth()->user()->id;
            $w = TrainingVideoQuiz::find($request->id);
            if (!$w) {
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }
            $isActiveInput = $request->has('is_active') ? $request->input('is_active') : ($request->has('active') ? $request->input('active') : 1);
            $w->is_active = (int) $isActiveInput ? 1 : 0;
            $w->updated_by =  $auth_user_id;
            $w->save();

            return response()->json(['message' => 'Training Video Quiz Details updated successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            Log::error('TrainingVideoQuiz status update failed', ['id' => $request->id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }
}

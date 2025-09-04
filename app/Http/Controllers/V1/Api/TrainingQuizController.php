<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\TrainingQuizChoice;
use App\Models\TrainingQuizQuestion;
use Illuminate\Http\Request;
use App\Models\TrainingVideoQuiz;
use Illuminate\Support\Facades\Validator;
use App\Rules\UniqueActive;

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
            $training_video_quizzes = TrainingVideoQuiz::where(['is_active' => 1, 'is_deleted' => 0])
                ->orderBy($sort_column, $sort_direction)
                ->skip($skip_count)
                ->take($row_per_page == -1 ? TrainingVideoQuiz::count() : $row_per_page)
                ->get()->map(function ($training_video_quiz) {
                    $training_video_quiz->created_at_formatted =  $training_video_quiz->created_at->format('d-m-Y h:i A');
                    $training_video_quiz->updated_at_formatted = $training_video_quiz->updated_at->format('d-m-Y h:i A');
                    return $training_video_quiz;
                });

            // Calculate total records and total pages
            $total_records = TrainingVideoQuiz::where(['is_active' => 1, 'is_deleted' => 0])->count();
            $total_pages = ceil($total_records / $row_per_page);

            // Return data as JSON response with the expected structure
            return response()->json([
                'training_video_quizzes' => $training_video_quizzes,
                'count' => $total_records,
                'next' => $total_pages > $current_page_number ? $current_page_number + 1 : null,
            ], 200);
        } else {
            // Retrieve categories based on pagination, sorting, and filtering
            $training_video_quizzes = TrainingVideoQuiz::where(['is_active' => 1, 'is_deleted' => 0])
                ->get()->map(function ($training_video_quiz) {
                    $training_video_quiz->created_at_formatted =  $training_video_quiz->created_at->format('d-m-Y h:i A');
                    $training_video_quiz->updated_at_formatted = $training_video_quiz->updated_at->format('d-m-Y h:i A');
                    return $training_video_quiz;
                });

            // Return data as JSON response with the expected structure
            return response()->json([
                'training_video_quizzes' => $training_video_quizzes,

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
            // Validation failed, return a JSON response with validation errors
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $auth_user_id = auth()->user()->id;
        $w = TrainingVideoQuiz::create();
        $w->training_video_id = $request->training_video_id;
        $w->is_active = $request->has('is_active') ? 1 : 0;
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


        // create one user with role 8
        return response()->json(['message' => 'New Training Video Quiz Created successfully', 'status' => 200,]);
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
            'training_video_quiz' => $training_video_quiz,

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
            // Validation failed, return a JSON response with validation errors
            return response()->json(['errors' => $validator->errors()], 422);
        }
        // user table email unique validation pending

        $auth_user_id = auth()->user()->id;
        $w = TrainingVideoQuiz::find($id);
        $w->training_video_id = $request->training_video_id;
        $w->type = $request->type;
        $w->is_active = $request->has('is_active') ? 1 : 0;
        $w->updated_by =  $auth_user_id;
        $w->save();
        TrainingQuizQuestion::where('training_quiz_id', $id)->update(['is_deleted' => 1]);
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
            TrainingQuizChoice::where('training_quiz_question_id', $training_quiz_question->id)->update(['is_deleted' => 1]);
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

        return response()->json(['message' => 'Training Video Quiz Details updated successfully', 'status' => 200]);
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
        $u = TrainingVideoQuiz::find($id);
        $u->is_deleted = 1;
        $u->updated_by = auth()->user()->id;
        $u->save();
    
        // Soft delete related questions
        $questions = TrainingQuizQuestion::where('training_quiz_id', $id)->get();
        $questionIds = $questions->pluck('id'); // get all question IDs
    
        TrainingQuizQuestion::where('training_quiz_id', $id)->update(['is_deleted' => 1]);
    
        // Soft delete related choices
        TrainingQuizChoice::whereIn('training_quiz_question_id', $questionIds)->update(['is_deleted' => 1]);
    
        return response()->json(['status' => 200]);
    }
    

    public function StatusUpdate(Request $request)
    {

        $auth_user_id = auth()->user()->id;
        $w = TrainingVideoQuiz::find($request->id);
        $w->is_active = $request->has('is_active') ? 1 : 0;
        $w->updated_by =  $auth_user_id;
        $w->save();

        return response()->json(['message' => 'Training Video Quiz Details updated successfully', 'status' => 200]);
    }
}

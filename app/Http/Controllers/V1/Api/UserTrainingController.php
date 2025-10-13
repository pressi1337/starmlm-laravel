<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\TrainingVideo;
use App\Models\User;
use App\Models\UserTrainingVideo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserTrainingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
    public function completeTraining(Request $request)
    {
        $user = User::find(Auth::id());

        // Get the current training (not completed yet)
        $userTraining = UserTrainingVideo::where('user_id', $user->id)
            ->where('status', '!=', UserTrainingVideo::STATUS_COMPLETED)
            ->with('trainingVideo')
            ->orderBy('day', 'asc')
            ->first();
        if (!$userTraining) {
            return response()->json([
                'message' => 'No current training found for this user',
                'status' => false,
            ], 400);
        }
       
        if($request->status == 2){
        // Mark as completed
        $userTraining->status = UserTrainingVideo::STATUS_COMPLETED;
        $userTraining->completed_at = now();
        $userTraining->save();

        // Find next training day
        $currentDay = $userTraining->day;
        $nextDay = $currentDay + 1;

        $nextVideo = TrainingVideo::where('day', $nextDay)
            ->where('is_active', 1)
            ->where('is_deleted', 0)
            ->first();
     
        if ($nextVideo) {
            $user_training = new UserTrainingVideo();
            $user_training->user_id = $user->id;
            $user_training->training_video_id = $nextVideo->id;
            $user_training->day = $nextDay;
            $user_training->status = UserTrainingVideo::STATUS_ASSIGNED;
            $user_training->assigned_at = now();
            $user_training->created_by = $user->id;
            $user_training->updated_by = $user->id;
            $user_training->save();
            $user->training_status = User::TRAINING_STATUS_IN_PROGRESS;
            $user->save();
        } else {
            // mark as training all completed pending

            $user->training_status = User::TRAINING_STATUS_COMPLETED;
            $user->updated_by = $user->id;
            $user->save();
        }
            $msg= "Video Watched";
        }else{
            $userTraining->status = UserTrainingVideo::STATUS_IN_PROGRESS;
            $userTraining->save();
            $msg= "Training marked as completed";
        }
        $data = [
            'training_status' => $user->training_status,
        ];
        return response()->json([
            'message'          => $msg,
            'data'    => $data,
            'status'=> true,
        ], 200);
    }
    public function getCurrentTrainingVideo()
    {
        $user = User::find(Auth::id());
        $training = UserTrainingVideo::where('user_id', $user->id)
            ->where('assigned_at','<',today())
            ->with([
                'trainingVideo' => function ($q) {
                    $q->where('is_deleted', 0)
                        ->where('is_active', 1);
                },
                'trainingVideo.quiz' => function ($q) {
                    $q->where('is_deleted', 0)
                        ->where('is_active', 1);
                },
                'trainingVideo.quiz.questions' => function ($q) {
                    $q->where('is_deleted', 0)
                        ->where('is_active', 1);
                },
                'trainingVideo.quiz.questions.choices' => function ($q) {
                    $q->where('is_deleted', 0)
                        ->where('is_active', 1);
                },
            ])
            ->where('status', '!=', UserTrainingVideo::STATUS_COMPLETED)
            ->orderBy('day', 'asc')
            ->first();
        $data = [
            'training' => $training ?? null,
            'training_status' => $user->training_status,
            'nextday' => ($training && $training->day !== null) ? $training->day + 1 : null,
        ];
        return response()->json([
            'message' => 'Training data',
            'data' => $data,
            'status' => true,
        ], 200);
    }
}

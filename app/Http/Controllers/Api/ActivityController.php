<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivityStep;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ActivityController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Get all activities with their steps
        $activities = Activity::with('activitySteps')->orderBy('created_at', 'desc')->get();
        
        // Log how many activities are found
        Log::info('Found ' . $activities->count() . ' activities');
        
        return response()->json($activities);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Only teachers can add activities'], 403);
        }
        
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'environment' => 'required|string|in:Home,School,Both',
            'difficulty' => 'required|string|in:Easy,Medium,Hard',
            'min_age' => 'required|numeric|min:0|max:6',
            'max_age' => 'required|numeric|min:0|max:6|gte:min_age',
            'duration' => 'nullable|integer|min:1',
            'next_activity_id' => 'nullable|exists:activities,id',
            'steps' => 'required|array|min:1',
            'steps.*' => 'required|string',
            'photos' => 'nullable|array',
            'photos.*' => 'nullable|string',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        // Create the activity
        $activity = Activity::create([
            'title' => $request->title,
            'description' => $request->description,
            'environment' => $request->environment,
            'difficulty' => $request->difficulty,
            'min_age' => $request->min_age,
            'max_age' => $request->max_age,
            'duration' => $request->duration,
            'next_activity_id' => $request->next_activity_id,
            'created_by' => $user->id,
        ]);
        
        // Create activity steps for the teacher
        ActivityStep::create([
            'activity_id' => $activity->id,
            'teacher_id' => $user->id,
            'steps' => $request->steps,
            'photos' => $request->photos ?? [],
        ]);
        
        // Load the steps relationship
        $activity->load('activitySteps');
        
        return response()->json($activity, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $activity = Activity::with('activitySteps')->find($id);
        
        if (!$activity) {
            return response()->json(['message' => 'Activity not found'], 404);
        }
        
        return response()->json($activity);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $user = Auth::user();
        
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Only teachers can update activities'], 403);
        }
        
        $activity = Activity::find($id);
        
        if (!$activity) {
            return response()->json(['message' => 'Activity not found'], 404);
        }
        
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'environment' => 'sometimes|required|string|in:Home,School,Both',
            'difficulty' => 'sometimes|required|string|in:Easy,Medium,Hard',
            'min_age' => 'sometimes|required|numeric|min:0|max:6',
            'max_age' => 'sometimes|required|numeric|min:0|max:6|gte:min_age',
            'duration' => 'nullable|integer|min:1',
            'next_activity_id' => 'nullable|exists:activities,id',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        // Don't allow updates to activities created by other teachers
        if ($activity->created_by !== $user->id) {
            return response()->json(['message' => 'You can only update your own activities'], 403);
        }
        
        $activity->update($request->only([
            'title', 'description', 'environment', 'difficulty', 
            'min_age', 'max_age', 'duration', 'next_activity_id'
        ]));
        
        return response()->json($activity);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = Auth::user();
        
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Only teachers can delete activities'], 403);
        }
        
        $activity = Activity::find($id);
        
        if (!$activity) {
            return response()->json(['message' => 'Activity not found'], 404);
        }
        
        // Don't allow deletion of activities created by other teachers
        if ($activity->created_by !== $user->id) {
            return response()->json(['message' => 'You can only delete your own activities'], 403);
        }
        
        $activity->delete();
        
        return response()->json(['message' => 'Activity deleted successfully']);
    }
    
    /**
     * Add custom steps to an activity.
     */
    public function addCustomSteps(Request $request, string $id)
    {
        $user = Auth::user();
        
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Only teachers can add custom steps'], 403);
        }
        
        $activity = Activity::find($id);
        
        if (!$activity) {
            return response()->json(['message' => 'Activity not found'], 404);
        }
        
        $validator = Validator::make($request->all(), [
            'steps' => 'required|array|min:1',
            'steps.*' => 'required|string',
            'photos' => 'nullable|array',
            'photos.*' => 'nullable|string',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        // Check if teacher already has custom steps for this activity
        $activityStep = ActivityStep::where('activity_id', $id)
            ->where('teacher_id', $user->id)
            ->first();
            
        if ($activityStep) {
            // Update existing steps
            $activityStep->update([
                'steps' => $request->steps,
                'photos' => $request->photos ?? $activityStep->photos ?? [],
            ]);
        } else {
            // Create new steps
            ActivityStep::create([
                'activity_id' => $id,
                'teacher_id' => $user->id,
                'steps' => $request->steps,
                'photos' => $request->photos ?? [],
            ]);
        }
        
        // Load the updated steps
        $activity->load('activitySteps');
        
        return response()->json($activity);
    }
}

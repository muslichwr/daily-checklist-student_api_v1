<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlannedActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class PlanController extends Controller
{
    /**
     * Display a listing of the plans.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = Plan::query()->with('plannedActivities');
        
        if ($user->isTeacher()) {
            // Teachers see plans they created
            $query->where('teacher_id', $user->id);
        } else {
            // Parents see plans for their children
            $childIds = $user->parentChildren->pluck('id')->toArray();
            
            $query->where(function($query) use ($childIds) {
                $query->whereIn('child_id', $childIds)
                      ->orWhereNull('child_id'); // Also include global plans
            });
        }
        
        if ($request->has('child_id')) {
            $query->where(function($query) use ($request) {
                $query->where('child_id', $request->child_id)
                      ->orWhereNull('child_id');
            });
        }
        
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        
        // Sort by start date, newest first
        $plans = $query->orderBy('start_date', 'desc')->get();
        
        return response()->json($plans);
    }

    /**
     * Store a newly created plan in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Only teachers can create plans
        if (!Auth::user()->isTeacher()) {
            return response()->json(['message' => 'Hanya guru yang dapat membuat rencana aktivitas'], 403);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:weekly,daily',
            'start_date' => 'required|date',
            'child_id' => 'nullable|exists:children,id',
            'activities' => 'required|array',
            'activities.*.activity_id' => 'required|exists:activities,id',
            'activities.*.scheduled_date' => 'required|date',
            'activities.*.scheduled_time' => 'nullable|string',
            'activities.*.reminder' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Create plan
        $plan = Plan::create([
            'teacher_id' => Auth::id(),
            'type' => $request->type,
            'start_date' => $request->start_date,
            'child_id' => $request->child_id,
        ]);

        // Create planned activities
        foreach ($request->activities as $activity) {
            PlannedActivity::create([
                'plan_id' => $plan->id,
                'activity_id' => $activity['activity_id'],
                'scheduled_date' => $activity['scheduled_date'],
                'scheduled_time' => $activity['scheduled_time'] ?? null,
                'reminder' => $activity['reminder'] ?? true,
                'completed' => false,
            ]);
        }

        // Load the planned activities
        $plan->load('plannedActivities');

        return response()->json($plan, 201);
    }

    /**
     * Display the specified plan.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $plan = Plan::with('plannedActivities.activity')->findOrFail($id);
        
        // Check authorization
        if (Auth::user()->isTeacher()) {
            if ($plan->teacher_id !== Auth::id()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        } else {
            // For parents
            $childIds = Auth::user()->parentChildren->pluck('id')->toArray();
            if ($plan->child_id && !in_array($plan->child_id, $childIds)) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }
        
        return response()->json($plan);
    }

    /**
     * Update the specified plan.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        // Only teachers can update plans
        if (!Auth::user()->isTeacher()) {
            return response()->json(['message' => 'Hanya guru yang dapat mengubah rencana aktivitas'], 403);
        }

        $plan = Plan::findOrFail($id);
        
        // Check if the plan belongs to the user
        if ($plan->teacher_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'type' => 'nullable|in:weekly,daily',
            'start_date' => 'nullable|date',
            'child_id' => 'nullable|exists:children,id',
            'activities' => 'nullable|array',
            'activities.*.id' => 'nullable|exists:planned_activities,id',
            'activities.*.activity_id' => 'required|exists:activities,id',
            'activities.*.scheduled_date' => 'required|date',
            'activities.*.scheduled_time' => 'nullable|string',
            'activities.*.reminder' => 'nullable|boolean',
            'activities.*.completed' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Update plan
        $plan->update([
            'type' => $request->type ?? $plan->type,
            'start_date' => $request->start_date ?? $plan->start_date,
            'child_id' => $request->has('child_id') ? $request->child_id : $plan->child_id,
        ]);

        // Update activities if provided
        if ($request->has('activities')) {
            // Remove existing activities not in the new list
            $activityIds = collect($request->activities)
                ->filter(function ($activity) {
                    return isset($activity['id']);
                })
                ->pluck('id')
                ->toArray();
            
            PlannedActivity::where('plan_id', $plan->id)
                ->whereNotIn('id', $activityIds)
                ->delete();
            
            // Update or create activities
            foreach ($request->activities as $activity) {
                if (isset($activity['id'])) {
                    // Update existing activity
                    PlannedActivity::where('id', $activity['id'])
                        ->update([
                            'activity_id' => $activity['activity_id'],
                            'scheduled_date' => $activity['scheduled_date'],
                            'scheduled_time' => $activity['scheduled_time'] ?? null,
                            'reminder' => $activity['reminder'] ?? true,
                            'completed' => $activity['completed'] ?? false,
                        ]);
                } else {
                    // Create new activity
                    PlannedActivity::create([
                        'plan_id' => $plan->id,
                        'activity_id' => $activity['activity_id'],
                        'scheduled_date' => $activity['scheduled_date'],
                        'scheduled_time' => $activity['scheduled_time'] ?? null,
                        'reminder' => $activity['reminder'] ?? true,
                        'completed' => $activity['completed'] ?? false,
                    ]);
                }
            }
        }

        // Load the updated planned activities
        $plan->load('plannedActivities');

        return response()->json($plan);
    }

    /**
     * Remove the specified plan.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        // Only teachers can delete plans
        if (!Auth::user()->isTeacher()) {
            return response()->json(['message' => 'Hanya guru yang dapat menghapus rencana aktivitas'], 403);
        }

        $plan = Plan::findOrFail($id);
        
        // Check if the plan belongs to the user
        if ($plan->teacher_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Delete the plan (cascade will delete related planned activities)
        $plan->delete();

        return response()->json(['message' => 'Rencana aktivitas berhasil dihapus']);
    }
    
    /**
     * Update the completion status of a planned activity.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateActivityStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'completed' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $activity = PlannedActivity::findOrFail($id);
        $plan = $activity->plan;
        
        // Authorization check
        $user = Auth::user();
        if ($user->isTeacher()) {
            if ($plan->teacher_id !== $user->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        } else {
            // For parents
            $childIds = $user->parentChildren->pluck('id')->toArray();
            if ($plan->child_id && !in_array($plan->child_id, $childIds)) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }
        
        $activity->completed = $request->completed;
        $activity->save();
        
        return response()->json($activity);
    }
} 
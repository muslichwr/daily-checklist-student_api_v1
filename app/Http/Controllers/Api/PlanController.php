<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlannedActivity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class PlanController extends Controller
{
    /**
     * Helper method to check if a user is a teacher
     *
     * @param  \App\Models\User  $user
     * @return bool
     */
    private function isTeacher(User $user): bool
    {
        return $user->role === 'teacher';
    }
    
    /**
     * Display a listing of the plans.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = Plan::query()->with(['plannedActivities', 'children']);
        
        if ($this->isTeacher($user)) {
            // Teachers see plans they created
            $query->where('teacher_id', $user->id);
        } else {
            // Parents see plans for their children
            $childIds = $user->parentChildren->pluck('id')->toArray();
            
            $query->where(function($query) use ($childIds) {
                $query->whereHas('children', function($q) use ($childIds) {
                    $q->whereIn('child_id', $childIds);
                })
                ->orWhereDoesntHave('children'); // Also include global plans with no children
            });
        }
        
        if ($request->has('child_id')) {
            $query->where(function($query) use ($request) {
                $query->whereHas('children', function($q) use ($request) {
                    $q->where('child_id', $request->child_id);
                })
                ->orWhereDoesntHave('children'); // Also include global plans
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
        if (!$this->isTeacher(Auth::user())) {
            return response()->json(['message' => 'Hanya guru yang dapat membuat rencana aktivitas'], 403);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:weekly,daily',
            'start_date' => 'required|date',
            'child_id' => 'nullable|exists:children,id',
            'child_ids' => 'nullable|array',
            'child_ids.*' => 'exists:children,id',
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
            'child_id' => $request->child_id, // Keep for backward compatibility
        ]);

        // Associate children with the plan
        if ($request->has('child_ids') && is_array($request->child_ids) && !empty($request->child_ids)) {
            $plan->children()->attach($request->child_ids);
        } elseif ($request->has('child_id') && $request->child_id) {
            $plan->children()->attach($request->child_id);
        }

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

        // Load the planned activities and children
        $plan->load(['plannedActivities', 'children']);

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
        $plan = Plan::with(['plannedActivities.activity', 'children'])->findOrFail($id);
        
        // Check authorization
        if ($this->isTeacher(Auth::user())) {
            if ($plan->teacher_id !== Auth::id()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        } else {
            // For parents
            $childIds = Auth::user()->parentChildren->pluck('id')->toArray();
            
            // Check if this plan is for any of the parent's children or is a global plan
            $planForParentChild = $plan->children->isEmpty() || $plan->children->whereIn('id', $childIds)->isNotEmpty();
            
            if (!$planForParentChild) {
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
        if (!$this->isTeacher(Auth::user())) {
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
            'child_ids' => 'nullable|array',
            'child_ids.*' => 'exists:children,id',
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

        // Update child associations if provided
        if ($request->has('child_ids')) {
            // Sync will remove existing associations and add new ones
            $plan->children()->sync($request->child_ids);
        } elseif ($request->has('child_id') && $request->child_id) {
            // For backward compatibility, sync with single child_id
            $plan->children()->sync([$request->child_id]);
        }

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

        // Load the updated planned activities and children
        $plan->load(['plannedActivities', 'children']);

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
        if (!$this->isTeacher(Auth::user())) {
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
        if ($this->isTeacher($user)) {
            if ($plan->teacher_id !== $user->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        } else {
            // For parents
            $childIds = $user->parentChildren->pluck('id')->toArray();
            
            // Check if this plan is for any of the parent's children or is a global plan
            $planForParentChild = $plan->children->isEmpty() || $plan->children->whereIn('id', $childIds)->isNotEmpty();
            
            if (!$planForParentChild) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }
        
        $activity->completed = $request->completed;
        $activity->save();
        
        return response()->json($activity);
    }
} 
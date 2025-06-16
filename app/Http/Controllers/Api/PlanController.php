<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlannedActivity;
use App\Models\PlanChild;
use App\Models\User;
use App\Models\Child;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlanController extends Controller
{
    protected $notificationController;
    protected $notificationSystemController;

    public function __construct(
        NotificationController $notificationController,
        NotificationSystemController $notificationSystemController
    ) {
        $this->notificationController = $notificationController;
        $this->notificationSystemController = $notificationSystemController;
    }
    
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
                    $q->whereIn('child_id', $childIds)
                      ->select(DB::raw('DISTINCT child_id'));
                })
                ->orWhereDoesntHave('children'); // Also include global plans with no children
            });
        }
        
        if ($request->has('child_id')) {
            $query->where(function($query) use ($request) {
                $query->whereHas('children', function($q) use ($request) {
                    $q->where('child_id', $request->child_id)
                      ->select(DB::raw('DISTINCT child_id'));
                })
                ->orWhereDoesntHave('children'); // Also include global plans
            });
        }
        
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        
        // Sort by start date, newest first
        $plans = $query->orderBy('start_date', 'desc')->get();
        
                    // For parents, include activity completion status per child
            if (!$this->isTeacher($user) && $request->has('child_id')) {
                $childId = $request->child_id;
                
                foreach ($plans as $plan) {
                    // Get completion status for each activity for this child
                    $completionRecords = DB::table('plan_child')
                        ->where('plan_id', $plan->id)
                        ->where('child_id', $childId)
                        ->whereNotNull('planned_activity_id')
                        ->get(['planned_activity_id', 'completed']);
                    
                    $completions = [];
                    foreach ($completionRecords as $record) {
                        $completions[$record->planned_activity_id] = (bool)$record->completed;
                    }
                    
                    // Attach completion status to each activity
                    foreach ($plan->plannedActivities as $activity) {
                        $activityId = $activity->id;
                        $activity->completed = isset($completions[$activityId]) ? $completions[$activityId] : false;
                    }
                }
            }
        
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

        try {
            DB::beginTransaction();

            // Create plan
            $plan = Plan::create([
                'teacher_id' => Auth::id(),
                'type' => $request->type,
                'start_date' => $request->start_date,
                'child_id' => $request->child_id, // Keep for backward compatibility
            ]);

            $childIds = [];

            // Get selected child IDs
            if ($request->has('child_ids') && is_array($request->child_ids) && !empty($request->child_ids)) {
                $childIds = $request->child_ids;
            } elseif ($request->has('child_id') && $request->child_id) {
                $childIds = [$request->child_id];
            }

            // Create planned activities first
            $plannedActivities = [];
            foreach ($request->activities as $activity) {
                $plannedActivity = PlannedActivity::create([
                    'plan_id' => $plan->id,
                    'activity_id' => $activity['activity_id'],
                    'scheduled_date' => $activity['scheduled_date'],
                    'scheduled_time' => $activity['scheduled_time'] ?? null,
                    'reminder' => $activity['reminder'] ?? true,
                ]);
                $plannedActivities[] = $plannedActivity;
            }

            // Now attach children to the plan and create activity relationships
            if (!empty($childIds)) {
                foreach ($childIds as $childId) {
                    // Create completion records for each child and activity
                    foreach ($plannedActivities as $plannedActivity) {
                        DB::table('plan_child')->insert([
                            'plan_id' => $plan->id,
                            'child_id' => $childId,
                            'planned_activity_id' => $plannedActivity->id,
                            'completed' => false,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            // Send notifications using Backend-Heavy approach
            if (!empty($childIds)) {
                foreach ($childIds as $childId) {
                    $child = Child::find($childId);
                    if ($child) {
                        // Use the specialized notification service
                        $planTitle = $request->type == 'weekly' ? 'Mingguan' : 'Harian';
                        $this->notificationController->sendNewPlanNotification(
                            $plan->id,
                            Auth::id(),
                            $childId,
                            $planTitle
                        );
                    }
                }
            } else {
                // For global plans without specific children, use system notification
                $this->notificationSystemController->createSystemNotification(new Request([
                    'title' => 'Rencana Aktivitas Baru',
                    'message' => 'Guru telah membuat rencana aktivitas '.strtolower($request->type).' baru',
                    'type' => 'new_plan',
                    'related_id' => $plan->id,
                ]));
            }
            
            DB::commit();

            // Load the planned activities and children
            $plan->load(['plannedActivities', 'children']);

            return response()->json($plan, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating plan: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to create plan: ' . $e->getMessage()], 500);
        }
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
            $user = Auth::user();
            $childIds = $user->parentChildren->pluck('id')->toArray();
            
            // Check if this plan is for any of the parent's children or is a global plan
            $planForParentChild = $plan->children->isEmpty() || $plan->children->whereIn('id', $childIds)->isNotEmpty();
            
            if (!$planForParentChild) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
            
            // If a specific child is viewing, include completion status
            if (!empty($childIds)) {
                $childId = $childIds[0]; // Get the first child for now
                
                // Get completion status for each activity for this child
                $completionRecords = DB::table('plan_child')
                    ->where('plan_id', $plan->id)
                    ->where('child_id', $childId)
                    ->whereNotNull('planned_activity_id')
                    ->get(['planned_activity_id', 'completed']);
                
                $completions = [];
                foreach ($completionRecords as $record) {
                    $completions[$record->planned_activity_id] = (bool)$record->completed;
                }
                
                // Attach completion status to each activity
                foreach ($plan->plannedActivities as $activity) {
                    $activityId = $activity->id;
                    $activity->completed = isset($completions[$activityId]) ? $completions[$activityId] : false;
                }
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
        // Validate request
        $validator = Validator::make($request->all(), [
            'type' => 'sometimes|in:weekly,daily',
            'start_date' => 'sometimes|date',
            'child_id' => 'nullable|exists:children,id',
            'child_ids' => 'nullable|array',
            'child_ids.*' => 'exists:children,id',
            'activities' => 'sometimes|array',
            'activities.*.id' => 'sometimes|exists:planned_activities,id',
            'activities.*.activity_id' => 'required_without:activities.*.id|exists:activities,id',
            'activities.*.scheduled_date' => 'required_with:activities.*.activity_id|date',
            'activities.*.scheduled_time' => 'nullable|string',
            'activities.*.reminder' => 'nullable|boolean',
            'activities.*.completed' => 'nullable|boolean',
            'deleted_activities' => 'nullable|array',
            'deleted_activities.*' => 'exists:planned_activities,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $plan = Plan::with(['plannedActivities', 'children'])->findOrFail($id);

        // Check authorization
        if ($plan->teacher_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            DB::beginTransaction();
            
            // Update plan basic info
            if ($request->has('type')) {
                $plan->type = $request->type;
            }
            
            if ($request->has('start_date')) {
                $plan->start_date = $request->start_date;
            }
            
            $plan->save();
            
            // Update child associations if specified
            $oldChildIds = DB::table('plan_child')
                ->where('plan_id', $plan->id)
                ->select('child_id')
                ->distinct()
                ->pluck('child_id')
                ->toArray();
                
            $newChildIds = $oldChildIds;
            
            if ($request->has('child_ids')) {
                $newChildIds = $request->child_ids ?? [];
            } elseif ($request->has('child_id')) {
                $newChildIds = $request->child_id ? [$request->child_id] : [];
            }
            
            // Remove child associations for children that are no longer in the plan
            $removedChildIds = array_diff($oldChildIds, $newChildIds);
            if (!empty($removedChildIds)) {
                DB::table('plan_child')
                    ->where('plan_id', $plan->id)
                    ->whereIn('child_id', $removedChildIds)
                    ->delete();
            }
            
            // Find new children that weren't in the plan before
            $addedChildIds = array_diff($newChildIds, $oldChildIds);
            
            // Update activities
            if ($request->has('activities')) {
                foreach ($request->activities as $activityData) {
                    if (isset($activityData['id'])) {
                        // Update existing planned activity
                        $plannedActivity = PlannedActivity::findOrFail($activityData['id']);
                        
                        // Only update the fields that are provided
                        if (isset($activityData['scheduled_date'])) {
                            $plannedActivity->scheduled_date = $activityData['scheduled_date'];
                        }
                        
                        if (isset($activityData['scheduled_time'])) {
                            $plannedActivity->scheduled_time = $activityData['scheduled_time'];
                        }
                        
                        if (isset($activityData['reminder'])) {
                            $plannedActivity->reminder = $activityData['reminder'];
                        }
                        
                        $plannedActivity->save();
                        
                        // Update completion status for each child if provided
                        if (isset($activityData['completed'])) {
                            // Get the child ID from the request or use all children assigned to the plan
                            $targetChildId = $request->child_id ?? null;
                            $childIds = $targetChildId ? [$targetChildId] : $newChildIds;
                            
                            foreach ($childIds as $childId) {
                                $planChild = PlanChild::where('plan_id', $plan->id)
                                    ->where('child_id', $childId)
                                    ->where('planned_activity_id', $plannedActivity->id)
                                    ->first();
                                
                                if ($planChild) {
                                    $previousStatus = $planChild->completed;
                                    $planChild->completed = $activityData['completed'];
                                    $planChild->save();
                                    
                                    // If activity completion status changed to completed, send notification
                                    if (!$previousStatus && $activityData['completed']) {
                                        $activity = $plannedActivity->activity;
                                        
                                        // Send notifications to parents of associated children
                                        $this->notificationController->sendActivityStatusNotification(
                                            $plannedActivity->id,
                                            Auth::id(),
                                            $childId,
                                            $activity->name,
                                            'completed'
                                        );
                                    }
                                }
                                // If no existing entry, create a new one
                                else {
                                    DB::table('plan_child')->insert([
                                        'plan_id' => $plan->id,
                                        'child_id' => $childId,
                                        'planned_activity_id' => $plannedActivity->id,
                                        'completed' => $activityData['completed'] ?? false,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);
                                }
                            }
                        }
                    } else {
                        // Create new planned activity
                        $plannedActivity = PlannedActivity::create([
                            'plan_id' => $plan->id,
                            'activity_id' => $activityData['activity_id'],
                            'scheduled_date' => $activityData['scheduled_date'],
                            'scheduled_time' => $activityData['scheduled_time'] ?? null,
                            'reminder' => $activityData['reminder'] ?? true,
                        ]);
                        
                        // Create completion records for each child
                        foreach ($newChildIds as $childId) {
                            DB::table('plan_child')->insert([
                                'plan_id' => $plan->id,
                                'child_id' => $childId,
                                'planned_activity_id' => $plannedActivity->id,
                                'completed' => $activityData['completed'] ?? false,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }
            }

            // For any newly added children, create completion records for existing activities
            if (!empty($addedChildIds)) {
                foreach ($plan->plannedActivities as $activity) {
                    foreach ($addedChildIds as $childId) {
                        // Check if this child-activity combination already exists
                        $exists = DB::table('plan_child')
                            ->where('plan_id', $plan->id)
                            ->where('child_id', $childId)
                            ->where('planned_activity_id', $activity->id)
                            ->exists();
                            
                        if (!$exists) {
                            DB::table('plan_child')->insert([
                                'plan_id' => $plan->id,
                                'child_id' => $childId,
                                'planned_activity_id' => $activity->id,
                                'completed' => false,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }
            }

            // Delete activities if specified
            if ($request->has('deleted_activities') && is_array($request->deleted_activities)) {
                foreach ($request->deleted_activities as $activityId) {
                    $plannedActivity = PlannedActivity::where('id', $activityId)
                        ->where('plan_id', $plan->id)
                        ->first();
                        
                    if ($plannedActivity) {
                        // First delete all child completion records
                        DB::table('plan_child')
                            ->where('planned_activity_id', $plannedActivity->id)
                            ->delete();
                        
                        // Then delete the activity
                        $plannedActivity->delete();
                    }
                }
            }
            
            DB::commit();
            
            // Reload the plan with its relationships
            $plan = Plan::with(['plannedActivities.activity', 'children'])->find($id);
            
            // Add completion status for activities
            if (!$this->isTeacher(Auth::user()) && !empty($newChildIds)) {
                $childId = $newChildIds[0]; // Get the first child
                
                // Get completion status for each activity for this child
                $completionRecords = DB::table('plan_child')
                    ->where('plan_id', $plan->id)
                    ->where('child_id', $childId)
                    ->whereNotNull('planned_activity_id')
                    ->get(['planned_activity_id', 'completed']);
                
                $completions = [];
                foreach ($completionRecords as $record) {
                    $completions[$record->planned_activity_id] = (bool)$record->completed;
                }
                
                // Attach completion status to each activity
                foreach ($plan->plannedActivities as $activity) {
                    $activityId = $activity->id;
                    $activity->completed = isset($completions[$activityId]) ? $completions[$activityId] : false;
                }
            }

            return response()->json($plan);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating plan: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to update plan: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified plan.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $plan = Plan::findOrFail($id);
        
        // Check authorization
        if ($plan->teacher_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            DB::beginTransaction();
            
            // Delete all completion records in plan_child
            DB::table('plan_child')
                ->where('plan_id', $plan->id)
                ->delete();
            
            // Delete all related planned activities
            PlannedActivity::where('plan_id', $plan->id)->delete();
            
            // Detach all children
            $plan->children()->detach();
            
            // Delete the plan
        $plan->delete();

            DB::commit();
            
            return response()->json(['message' => 'Plan deleted successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting plan: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to delete plan: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update the status of a planned activity.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateActivityStatus(Request $request, $id)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'completed' => 'required|boolean',
            'child_id' => 'required|exists:children,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $plannedActivity = PlannedActivity::with(['activity', 'plan.children'])->findOrFail($id);
        $plan = $plannedActivity->plan;
        $childId = $request->child_id;

        // Check authorization
        // Teachers can update any activity they created
        $authorized = false;
        if ($this->isTeacher(Auth::user()) && $plan->teacher_id === Auth::id()) {
            $authorized = true;
        } 
        // Parents can only mark activities as completed for their own children
        else if (!$this->isTeacher(Auth::user())) {
            $childIds = Auth::user()->parentChildren->pluck('id')->toArray();
            $authorized = in_array($childId, $childIds);
        }

        if (!$authorized) {
                return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            DB::beginTransaction();
            
            // Find or create completion record
            $planChild = PlanChild::firstOrCreate(
                [
                    'plan_id' => $plan->id,
                    'child_id' => $childId,
                    'planned_activity_id' => $plannedActivity->id
                ],
                [
                    'completed' => false,
                ]
            );
            
            // Update the completion status
            $previousStatus = $planChild->completed;
            $planChild->completed = $request->completed;
            $planChild->save();
            
            // If status changed, send notifications using Backend-Heavy approach
            if ($previousStatus != $request->completed) {
                $activity = $plannedActivity->activity;
                
                // Send notifications to parents or teachers depending on who made the update
                if ($this->isTeacher(Auth::user())) {
                    // Teacher updated activity status - notify parents
                        $this->notificationController->sendActivityStatusNotification(
                            $plannedActivity->id,
                            Auth::id(),
                            $childId,
                            $activity->name,
                            $request->completed ? 'completed' : 'incomplete'
                        );
            } else {
                    // Parent updated activity status - notify teacher
                    $teacherId = $plan->teacher_id;
                    $notificationTitle = 'Status Aktivitas Diperbarui';
                    $notificationMessage = 'Orang tua telah menandai aktivitas "' . $activity->name . '" sebagai ' . 
                                          ($request->completed ? 'selesai' : 'belum selesai');
                    
                    $this->notificationController->store(new Request([
                        'user_id' => $teacherId,
                        'title' => $notificationTitle,
                        'message' => $notificationMessage,
                        'type' => 'activity_status',
                        'related_id' => $plannedActivity->id,
                        'child_id' => $childId,
                    ]));
                }
            }
            
            DB::commit();
            
            // Load the activity with completion status
            $plannedActivity = $plannedActivity->load(['activity', 'plan.children']);
            $plannedActivity->completed = $request->completed;
            
            return response()->json([
                'message' => 'Activity status updated successfully',
                'planned_activity' => $plannedActivity
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating activity status: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to update activity status: ' . $e->getMessage()], 500);
        }
    }
} 
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
        
        if ($user->isAdmin()) {
            // Superadmin can see all plans
            // No filtering needed
        } elseif ($this->isTeacher($user)) {
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
        // Only teachers and superadmins can create plans
        if (!$this->isTeacher(Auth::user()) && !Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Hanya guru dan administrator yang dapat membuat rencana aktivitas'], 403);
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
                'teacher_id' => Auth::user()->isAdmin() ? $request->teacher_id : Auth::id(),
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
     * Get completion status for activities in a plan.
     *
     * @param  int  $planId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getActivityCompletionStatus($planId)
    {
        try {
            // Check if plan exists
            $plan = Plan::findOrFail($planId);
            
            // Get all completion records directly from the database
            $completionRecords = DB::table('plan_child')
                ->where('plan_id', $planId)
                ->whereNotNull('planned_activity_id')
                ->select('child_id', 'planned_activity_id', 'completed')
                ->get();
            
            // Format the data for easier consumption by frontend
            $formattedData = [
                'plan_id' => $planId,
                'activities' => [],
                'children' => [],
                'completion_matrix' => []
            ];
            
            // Get all planned activities for this plan
            $plannedActivities = PlannedActivity::where('plan_id', $planId)->get();
            foreach ($plannedActivities as $activity) {
                $formattedData['activities'][] = [
                    'id' => $activity->id,
                    'activity_id' => $activity->activity_id
                ];
            }
            
            // Get all children for this plan (using distinct to avoid duplicates)
            $childIds = DB::table('plan_child')
                ->where('plan_id', $planId)
                ->select('child_id')
                ->distinct()
                ->pluck('child_id');
                
            $children = Child::whereIn('id', $childIds)->get();
            
            foreach ($children as $child) {
                $formattedData['children'][] = [
                    'id' => $child->id,
                    'name' => $child->name
                ];
            }
            
            // Format completion data as a matrix
            foreach ($completionRecords as $record) {
                $activityId = $record->planned_activity_id;
                $childId = $record->child_id;
                $completed = (bool)$record->completed;
                
                if (!isset($formattedData['completion_matrix'][$activityId])) {
                    $formattedData['completion_matrix'][$activityId] = [];
                }
                
                $formattedData['completion_matrix'][$activityId][$childId] = $completed;
            }
            
            // Also provide raw data for debugging
            $formattedData['raw_completion_records'] = $completionRecords;
            
            return response()->json($formattedData);
        } catch (\Exception $e) {
            Log::error('Error retrieving completion status: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to retrieve completion status: ' . $e->getMessage()], 500);
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
        try {
            $plan = Plan::with(['plannedActivities.activity'])->findOrFail($id);
        
            // Authorization for Superadmin
            if (Auth::user()->isAdmin()) {
                // Superadmin can view all plans
            }
            // Authorization for Teacher
            elseif ($this->isTeacher(Auth::user())) {
                if ($plan->teacher_id !== Auth::id()) {
                    return response()->json(['message' => 'Unauthorized'], 403);
                }
            } else {
                // Authorization for Parent
                $user = Auth::user();
                $childIds = $user->parentChildren->pluck('id')->toArray();
        
                // Check if this plan is for any of the parent's children
                $planForParentChild = $plan->children()
                    ->wherePivotIn('child_id', $childIds)
                    ->exists();
        
                if (!$planForParentChild) {
                    return response()->json(['message' => 'Unauthorized'], 403);
                }
            }

            // Get unique children without duplicates
            $uniqueChildren = $plan->uniqueChildren();
            
            // Get raw completion records for backwards compatibility
            $completionRecords = DB::table('plan_child')
                ->where('plan_id', $id)
                ->whereNotNull('planned_activity_id')
                ->select('child_id', 'planned_activity_id', 'completed')
                ->get();
            
            // Return the enhanced plan data with explicit completion information
            $result = [
                'id' => $plan->id,
                'teacher_id' => $plan->teacher_id,
                'type' => $plan->type,
                'start_date' => $plan->start_date,
                'child_id' => $plan->child_id,
                'created_at' => $plan->created_at,
                'updated_at' => $plan->updated_at,
                'planned_activities' => $plan->plannedActivities,
                'children' => $uniqueChildren,
                'completion_map' => $plan->plannedActivities->pluck('child_completion_map', 'id'),
                'progress_by_child' => $plan->progress_by_child,
                'overall_progress' => $plan->overall_progress,
                'raw_completion_data' => $completionRecords, // For backwards compatibility
            ];
            
            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Error retrieving plan: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to retrieve plan: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Generate a summarized version of completion status for easier consumption by frontend.
     *
     * @param  \App\Models\Plan  $plan
     * @param  array  $completionMap
     * @param  \Illuminate\Database\Eloquent\Collection  $children
     * @return array
     */
    private function generateCompletionSummary($plan, $completionMap, $children)
    {
        $summary = [
            'by_child' => [],
            'by_activity' => [],
            'overall' => [
                'total_activities' => $plan->plannedActivities->count(),
                'total_children' => $children->count(),
                'total_combinations' => $plan->plannedActivities->count() * $children->count(),
                'total_completed' => 0
            ]
        ];
        
        // Initialize counters for each child
        foreach ($children as $child) {
            $childId = $child->id;
            $summary['by_child'][$childId] = [
                'name' => $child->name,
                'total_activities' => $plan->plannedActivities->count(),
                'completed_activities' => 0,
                'completion_percentage' => 0
            ];
        }
        
        // Initialize counters for each activity
        foreach ($plan->plannedActivities as $activity) {
            $activityId = $activity->id;
            $summary['by_activity'][$activityId] = [
                'activity_id' => $activity->activity_id,
                'total_children' => $children->count(),
                'completed_by_children' => 0,
                'completion_percentage' => 0
            ];
        }
        
        // Count completions
        $totalCompleted = 0;
        
        foreach ($completionMap as $activityId => $childCompletions) {
            foreach ($childCompletions as $childId => $completed) {
                if ($completed) {
                    $totalCompleted++;
                    
                    // Increment child's completed activities count
                    if (isset($summary['by_child'][$childId])) {
                        $summary['by_child'][$childId]['completed_activities']++;
                    }
                    
                    // Increment activity's completed by children count
                    if (isset($summary['by_activity'][$activityId])) {
                        $summary['by_activity'][$activityId]['completed_by_children']++;
                    }
                }
            }
        }
        
        // Calculate percentages
        foreach ($summary['by_child'] as $childId => $childData) {
            if ($childData['total_activities'] > 0) {
                $summary['by_child'][$childId]['completion_percentage'] = 
                    ($childData['completed_activities'] / $childData['total_activities']) * 100;
            }
        }
        
        foreach ($summary['by_activity'] as $activityId => $activityData) {
            if ($activityData['total_children'] > 0) {
                $summary['by_activity'][$activityId]['completion_percentage'] = 
                    ($activityData['completed_by_children'] / $activityData['total_children']) * 100;
            }
        }
        
        // Update overall stats
        $summary['overall']['total_completed'] = $totalCompleted;
        if ($summary['overall']['total_combinations'] > 0) {
            $summary['overall']['completion_percentage'] = 
                ($totalCompleted / $summary['overall']['total_combinations']) * 100;
        } else {
            $summary['overall']['completion_percentage'] = 0;
        }
        
        return $summary;
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
        if (Auth::user()->isAdmin()) {
            // Superadmin can update all plans
        } elseif ($plan->teacher_id !== Auth::id()) {
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
        if (Auth::user()->isAdmin()) {
            // Superadmin can delete all plans
        } elseif ($plan->teacher_id !== Auth::id()) {
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

        try {
            // Load the activity with its relations
            $plannedActivity = PlannedActivity::with(['activity', 'plan'])->findOrFail($id);
            $plan = $plannedActivity->plan;
            $childId = $request->child_id;

            // Check authorization
            // Superadmins can update any activity
            $authorized = false;
            if (Auth::user()->isAdmin()) {
                $authorized = true;
            }
            // Teachers can update any activity they created
            elseif ($this->isTeacher(Auth::user()) && $plan->teacher_id === Auth::id()) {
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
            
            // Store previous status for notifications
            $previousStatus = $planChild->completed;
            
            // Update the completion status - ensure boolean conversion
            $isCompleted = (bool)$request->completed;
            $planChild->completed = $isCompleted;
            $planChild->save();
            
            // If status changed, send notifications using Backend-Heavy approach
            if ($previousStatus != $isCompleted) {
                $activity = $plannedActivity->activity;
                
                // Send notifications to parents or teachers depending on who made the update
                if ($this->isTeacher(Auth::user())) {
                    // Teacher updated activity status - notify parents
                        $this->notificationController->sendActivityStatusNotification(
                            $plannedActivity->id,
                            Auth::id(),
                            $childId,
                            $activity->name,
                            $isCompleted ? 'completed' : 'incomplete'
                        );
                } else {
                    // Parent updated activity status - notify teacher
                    $teacherId = $plan->teacher_id;
                    $notificationTitle = 'Status Aktivitas Diperbarui';
                    $notificationMessage = 'Orang tua telah menandai aktivitas "' . $activity->name . '" sebagai ' . 
                                          ($isCompleted ? 'selesai' : 'belum selesai');
                    
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
            
            // Refresh the planned activity to get updated completion status
            $plannedActivity->refresh();
            
            // Get the child model
            $child = Child::find($childId);
            
            // Create structured response with new computed properties
            $response = [
                'status' => 'success',
                'message' => 'Activity status updated successfully',
                'data' => [
                    'activity_id' => $plannedActivity->id,
                    'plan_id' => $plan->id,
                    'updated_child' => [
                        'id' => $childId,
                        'name' => $child ? $child->name : 'Unknown',
                        'completed' => $isCompleted
                    ],
                    'all_child_statuses' => $plannedActivity->child_completion_map,
                    'is_completed' => $plannedActivity->is_completed,
                    'activity' => [
                        'id' => $plannedActivity->id,
                        'title' => $plannedActivity->activity->title ?? 'Activity',
                        'scheduled_date' => $plannedActivity->scheduled_date,
                        'scheduled_time' => $plannedActivity->scheduled_time,
                    ],
                    'plan_progress' => [
                        'by_child' => $plan->progress_by_child,
                        'overall' => $plan->overall_progress
                    ]
                ]
            ];
            
            return response()->json($response);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating activity status: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to update activity status: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Debug endpoint to directly check plan-child completion status for a specific activity.
     *
     * @param  int  $planId
     * @param  int  $activityId
     * @param  int  $childId
     * @return \Illuminate\Http\JsonResponse
     */
    public function debugCompletionStatus($planId, $activityId, $childId)
    {
        try {
            // Get the raw database record
            $planChild = DB::table('plan_child')
                ->where('plan_id', $planId)
                ->where('planned_activity_id', $activityId)
                ->where('child_id', $childId)
                ->first();
            
            // Get other relevant data
            $child = DB::table('children')->where('id', $childId)->first();
            $activity = DB::table('planned_activities')->where('id', $activityId)->first();
            $plan = DB::table('plans')->where('id', $planId)->first();
            
            // Format data for response
            $result = [
                'plan_id' => $planId,
                'activity_id' => $activityId,
                'child_id' => $childId,
                'debug_time' => now()->toDateTimeString(),
                'raw_plan_child_record' => $planChild,
                'completed_raw_value' => $planChild ? $planChild->completed : null,
                'completed_boolean' => $planChild ? (bool)$planChild->completed : null,
                'planned_activity' => $activity,
                'child' => $child,
                'plan' => $plan,
            ];
            
            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Error in debug endpoint: ' . $e->getMessage());
            return response()->json(['error' => 'Debug error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get completion status of a specific activity.
     *
     * @param  int  $activityId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getActivityChildrenStatus($activityId)
    {
        try {
            // Get the planned activity with its plan
            $plannedActivity = PlannedActivity::with(['plan', 'activity'])->findOrFail($activityId);
            $plan = $plannedActivity->plan;
            
            // Check authorization
            if (Auth::user()->isAdmin()) {
                // Superadmin can view all activities
            } elseif ($this->isTeacher(Auth::user())) {
                if ($plan->teacher_id !== Auth::id()) {
                    return response()->json(['message' => 'Unauthorized'], 403);
                }
            } else {
                // For parents, check if any of their children are in this plan
                $user = Auth::user();
                $childIds = $user->parentChildren->pluck('id')->toArray();
                $planForParentChild = $plan->children()->wherePivotIn('child_id', $childIds)->exists();
                
                if (!$planForParentChild) {
                    return response()->json(['message' => 'Unauthorized'], 403);
                }
            }
            
            // Get all unique children for this plan
            $children = $plan->uniqueChildren();
            
            // Get all completion records for this activity
            $completionRecords = DB::table('plan_child')
                ->where('plan_id', $plan->id)
                ->where('planned_activity_id', $activityId)
                ->get(['child_id', 'completed', 'updated_at']);
                
            // Create a map of child_id -> completion status
            $childrenStatus = [];
            foreach ($children as $child) {
                $childId = $child->id;
                $childrenStatus[$childId] = [
                    'child_id' => $childId,
                    'name' => $child->name,
                    'completed' => false,
                    'last_updated' => null
                ];
            }
            
            // Update with actual completion status
            foreach ($completionRecords as $record) {
                $childId = $record->child_id;
                if (isset($childrenStatus[$childId])) {
                    $childrenStatus[$childId]['completed'] = (bool)$record->completed;
                    $childrenStatus[$childId]['last_updated'] = $record->updated_at;
                }
            }
            
            // Calculate statistics
            $totalChildren = count($childrenStatus);
            $completedCount = count(array_filter($childrenStatus, function($status) {
                return $status['completed'] === true;
            }));
            
            $response = [
                'activity_id' => $activityId,
                'plan_id' => $plan->id,
                'activity_details' => [
                    'title' => $plannedActivity->activity->title ?? 'Activity',
                    'scheduled_date' => $plannedActivity->scheduled_date,
                    'scheduled_time' => $plannedActivity->scheduled_time,
                ],
                'children_status' => array_values($childrenStatus), // Convert to indexed array
                'stats' => [
                    'total_children' => $totalChildren,
                    'completed_count' => $completedCount,
                    'completion_percentage' => $totalChildren > 0 ? ($completedCount / $totalChildren) * 100 : 0,
                ],
                'timestamp' => now()->toDateTimeString(),
            ];
            
            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Error getting activity children status: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to get activity status: ' . $e->getMessage()], 500);
        }
    }
} 
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Models\Child;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class NotificationSystemController extends Controller
{
    /**
     * @var NotificationController
     */
    protected $notificationController;
    
    /**
     * Create a new controller instance.
     */
    public function __construct(NotificationController $notificationController)
    {
        $this->notificationController = $notificationController;
    }
    
    /**
     * Create a system notification that will be sent to all parents
     */
    public function createSystemNotification(Request $request)
    {
        // Authorize: Only teachers and admins can send system notifications
        if (!Auth::user()->isTeacher() && !Auth::user()->isAdmin()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|string',
            'related_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        try {
            // Begin transaction for multiple inserts
            DB::beginTransaction();
            
            // Get all parent users
            $parentUsers = User::where('role', 'parent')->get();
            $notifications = [];
            $failedCount = 0;
            
            foreach ($parentUsers as $user) {
                try {
                    $notification = Notification::create([
                        'user_id' => $user->id,
                        'title' => $request->title,
                        'message' => $request->message,
                        'type' => $request->type,
                        'related_id' => $request->related_id,
                        'is_read' => false,
                        'sent_by' => Auth::id(),
                    ]);
                    
                    $notifications[] = $notification;
                    
                    // Send push notification using the controller
                    $this->notificationController->sendPushNotification($notification);
                } catch (\Exception $e) {
                    Log::error("Failed to send notification to user {$user->id}: {$e->getMessage()}");
                    $failedCount++;
                }
            }
            
            DB::commit();
            
            return response()->json([
                'message' => 'System notification created for all parents',
                'notification_count' => count($notifications),
                'failed_count' => $failedCount
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating system notification: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to create system notification: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * Send notifications to parents of specific children
     */
    public function sendToParents(Request $request)
    {
        // Authorize: Only teachers and admins can send these notifications
        if (!Auth::user()->isTeacher() && !Auth::user()->isAdmin()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|string',
            'related_id' => 'nullable|string',
            'child_ids' => 'required|array',
            'child_ids.*' => 'exists:children,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        try {
            // Begin transaction for multiple inserts
            DB::beginTransaction();
            
            $notifiedParentIds = [];
            $notifications = [];
            $failedCount = 0;
            
            foreach ($request->child_ids as $childId) {
                // Get child record with parent info
                $child = Child::find($childId);
                
                if ($child && $child->parent_id) {
                    // Skip if we already notified this parent
                    if (in_array($child->parent_id, $notifiedParentIds)) {
                        continue;
                    }
                    
                    try {
                        // Create notification for the child's parent
                        $notification = Notification::create([
                            'user_id' => $child->parent_id,
                            'child_id' => $childId,
                            'title' => $request->title,
                            'message' => $request->message,
                            'type' => $request->type,
                            'related_id' => $request->related_id,
                            'is_read' => false,
                            'sent_by' => Auth::id(),
                        ]);
                        
                        $notifications[] = $notification;
                        $notifiedParentIds[] = $child->parent_id;
                        
                        // Send push notification
                        $this->notificationController->sendPushNotification($notification);
                    } catch (\Exception $e) {
                        Log::error("Failed to send notification for child {$childId}: {$e->getMessage()}");
                        $failedCount++;
                    }
                }
            }
            
            DB::commit();
            
            return response()->json([
                'message' => 'Notifications sent to parents',
                'notification_count' => count($notifications),
                'failed_count' => $failedCount
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error sending notifications to parents: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to send notifications to parents: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * Send notification when a teacher creates a new activity plan
     */
    public function notifyNewActivityPlan(Request $request)
    {
        // Authorize: Only teachers can create plans
        if (!Auth::user()->isTeacher()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $validator = Validator::make($request->all(), [
            'plan_id' => 'required|string',
            'plan_title' => 'required|string',
            'child_id' => 'required|exists:children,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $success = $this->notificationController->sendNewPlanNotification(
            $request->plan_id,
            Auth::id(),
            $request->child_id,
            $request->plan_title
        );
        
        if ($success) {
            return response()->json(['message' => 'Plan notification sent successfully']);
        } else {
            return response()->json(['error' => 'Failed to send plan notification'], 500);
        }
    }
    
    /**
     * Send notification when a teacher updates an activity status
     */
    public function notifyActivityStatusUpdate(Request $request)
    {
        // Authorize: Only teachers can update activity status
        if (!Auth::user()->isTeacher()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $validator = Validator::make($request->all(), [
            'activity_id' => 'required|string',
            'activity_title' => 'required|string',
            'child_id' => 'required|exists:children,id',
            'status' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $success = $this->notificationController->sendActivityStatusNotification(
            $request->activity_id,
            Auth::id(),
            $request->child_id,
            $request->activity_title,
            $request->status
        );
        
        if ($success) {
            return response()->json(['message' => 'Activity status notification sent successfully']);
        } else {
            return response()->json(['error' => 'Failed to send activity status notification'], 500);
        }
    }
}
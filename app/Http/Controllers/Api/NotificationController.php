<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Models\Child;
use App\Models\FirebaseToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Services\FCMService;

class NotificationController extends Controller
{
    protected $fcmService;
    
    public function __construct(FCMService $fcmService)
    {
        $this->fcmService = $fcmService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        $query = Notification::where('user_id', $user->id)
            ->orderBy('created_at', 'desc');
        
        // Get child-specific notifications if provided
        if ($request->has('child_id') && $request->child_id) {
            $childId = $request->child_id;
            $query->where(function($query) use ($user, $childId) {
                $query->where('user_id', $user->id)
                      ->orWhere('child_id', $childId);
            });
        }
        
        $notifications = $query->get();
        
        return response()->json($notifications);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|string',
            'related_id' => 'nullable|string',
            'child_id' => 'nullable|exists:children,id',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        try {
            DB::beginTransaction();
            
            // Create the notification
            $notification = Notification::create([
                'user_id' => $request->user_id,
                'title' => $request->title,
                'message' => $request->message,
                'type' => $request->type,
                'related_id' => $request->related_id,
                'child_id' => $request->child_id,
                'is_read' => false,
                'sent_by' => Auth::id(), // Record who sent this notification
            ]);
            
            // Send push notification if the recipient has Firebase token
            $this->sendPushNotification($notification);
            
            DB::commit();
            
            return response()->json($notification, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating notification: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to create notification: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = Auth::user();
        $notification = Notification::find($id);
        
        if (!$notification) {
            return response()->json(['message' => 'Notification not found'], 404);
        }
        
        // Ensure user can only see their own notifications
        if ($notification->user_id != $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        return response()->json($notification);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $user = Auth::user();
        $notification = Notification::find($id);
        
        if (!$notification) {
            return response()->json(['message' => 'Notification not found'], 404);
        }
        
        // Ensure user can only update their own notifications
        if ($notification->user_id != $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $validator = Validator::make($request->all(), [
            'is_read' => 'sometimes|boolean',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $notification->update($request->only(['is_read']));
        
        return response()->json($notification);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = Auth::user();
        $notification = Notification::find($id);
        
        if (!$notification) {
            return response()->json(['message' => 'Notification not found'], 404);
        }
        
        // Ensure user can only delete their own notifications
        if ($notification->user_id != $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $notification->delete();
        
        return response()->json(['message' => 'Notification deleted successfully']);
    }
    
    /**
     * Mark all user's notifications as read.
     */
    public function markAllAsRead()
    {
        $user = Auth::user();
        
        Notification::where('user_id', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);
        
        return response()->json(['message' => 'All notifications marked as read']);
    }
    
    /**
     * Send notification to parent when a new plan is created
     */
    public function sendNewPlanNotification($planId, $teacherId, $childId, $planTitle)
    {
        try {
            // Get the child's parent
            $child = Child::find($childId);
            
            if (!$child || !$child->parent_id) {
                Log::warning("Cannot send notification: Child $childId has no linked parent");
                return false;
            }
            
            $notification = Notification::create([
                'user_id' => $child->parent_id,
                'title' => 'Rencana Baru',
                'message' => "Guru telah membuat rencana baru: $planTitle",
                'type' => 'new_plan',
                'related_id' => $planId,
                'child_id' => $childId,
                'is_read' => false,
                'sent_by' => $teacherId,
            ]);
            
            // Send push notification if applicable
            $this->sendPushNotification($notification);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Error sending new plan notification: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send notification when an activity status is updated
     */
    public function sendActivityStatusNotification($activityId, $teacherId, $childId, $activityTitle, $status)
    {
        try {
            // Get the child's parent
            $child = Child::find($childId);
            
            if (!$child || !$child->parent_id) {
                Log::warning("Cannot send notification: Child $childId has no linked parent");
                return false;
            }
            
            $statusText = $status == 'completed' ? 'selesai' : 'diperbarui';
            
            $notification = Notification::create([
                'user_id' => $child->parent_id,
                'title' => 'Status Aktivitas Diperbarui',
                'message' => "Aktivitas '$activityTitle' telah $statusText",
                'type' => 'activity_completed',
                'related_id' => $activityId,
                'child_id' => $childId,
                'is_read' => false,
                'sent_by' => $teacherId,
            ]);
            
            // Send push notification if applicable
            $this->sendPushNotification($notification);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Error sending activity status notification: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get unread notification count for a user
     */
    public function getUnreadCount()
    {
        $user = Auth::user();
        $count = Notification::where('user_id', $user->id)
            ->where('is_read', false)
            ->count();
            
        return response()->json(['unread_count' => $count]);
    }
    
    /**
     * Send FCM push notification using FCM Service
     */
    public function sendPushNotification(Notification $notification)
    {
        try {
            // Check if the user has registered Firebase tokens
            $tokens = FirebaseToken::where('user_id', $notification->user_id)
                                 ->where('is_active', true)
                                 ->pluck('token')
                                 ->toArray();
            
            if (empty($tokens)) {
                Log::info("User {$notification->user_id} has no active Firebase tokens. Skipping push notification.");
                return;
            }
            
            $data = [
                'notification_id' => $notification->id,
                'type' => $notification->type,
                'related_id' => $notification->related_id ?? '',
                'child_id' => $notification->child_id ?? '',
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            ];
            
            // Use FCM service to send notification to all tokens
            $this->fcmService->sendNotificationToDevices(
                $tokens, 
                $notification->title, 
                $notification->message,
                $data
            );
            
        } catch (\Exception $e) {
            Log::error("Error sending push notification: {$e->getMessage()}");
        }
    }
    
    /**
     * Register or update Firebase token for the current user
     */
    public function registerFirebaseToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string'
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        try {
            $user = Auth::user();
            $token = $request->token;
            
            // Update or create the token record
            FirebaseToken::updateOrCreate(
                ['token' => $token],
                [
                    'user_id' => $user->id,
                    'device_info' => $request->device_info ?? null,
                    'is_active' => true,
                    'last_used' => now()
                ]
            );
            
            return response()->json(['message' => 'Firebase token registered successfully']);
        } catch (\Exception $e) {
            Log::error('Failed to register Firebase token: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to register token'], 500);
        }
    }
}

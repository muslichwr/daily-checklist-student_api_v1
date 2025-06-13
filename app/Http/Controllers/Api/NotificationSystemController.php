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
     * Create a system notification that will be sent to all parents
     */
    public function createSystemNotification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|string',
            'related_id' => 'nullable|string',
            'sender_id' => 'required|exists:users,id',
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
            
            foreach ($parentUsers as $user) {
                $notification = Notification::create([
                    'user_id' => $user->id,
                    'title' => $request->title,
                    'message' => $request->message,
                    'type' => $request->type,
                    'related_id' => $request->related_id,
                    'is_read' => false,
                    'sent_by' => $request->sender_id,
                ]);
                
                $notifications[] = $notification;
            }
            
            DB::commit();
            
            // Implement FCM notification here if needed
            // This would use Firebase to send push notifications to all parent users
            
            return response()->json([
                'message' => 'System notification created for all parents',
                'notification_count' => count($notifications)
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating system notification: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to create system notification'], 500);
        }
    }
    
    /**
     * Send notifications to parents of specific children
     */
    public function sendToParents(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|string',
            'related_id' => 'nullable|string',
            'child_ids' => 'required|array',
            'child_ids.*' => 'exists:children,id',
            'sender_id' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        try {
            // Begin transaction for multiple inserts
            DB::beginTransaction();
            
            $notifiedParentIds = [];
            $notifications = [];
            
            foreach ($request->child_ids as $childId) {
                // Get child record with parent info
                $child = Child::find($childId);
                
                if ($child && $child->parent_id) {
                    // Skip if we already notified this parent
                    if (in_array($child->parent_id, $notifiedParentIds)) {
                        continue;
                    }
                    
                    // Create notification for the child's parent
                    $notification = Notification::create([
                        'user_id' => $child->parent_id,
                        'child_id' => $childId,
                        'title' => $request->title,
                        'message' => $request->message,
                        'type' => $request->type,
                        'related_id' => $request->related_id,
                        'is_read' => false,
                        'sent_by' => $request->sender_id,
                    ]);
                    
                    $notifications[] = $notification;
                    $notifiedParentIds[] = $child->parent_id;
                }
            }
            
            DB::commit();
            
            // Implement FCM notification here if needed
            // This would use Firebase to send push notifications to the specific parent users
            
            return response()->json([
                'message' => 'Notifications sent to parents',
                'notification_count' => count($notifications)
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error sending notifications to parents: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to send notifications to parents'], 500);
        }
    }
}
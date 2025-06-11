<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
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
            $query->orWhere('child_id', $childId);
        }
        
        $notifications = $query->get();
        
        return response()->json($notifications);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
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
        
        $notification = Notification::create([
            'user_id' => $request->user_id,
            'title' => $request->title,
            'message' => $request->message,
            'type' => $request->type,
            'related_id' => $request->related_id,
            'child_id' => $request->child_id,
            'is_read' => false,
        ]);
        
        return response()->json($notification, 201);
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
}

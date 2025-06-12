<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    /**
     * Display a listing of the users.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $currentUser = Auth::user();
        $query = User::query();
        
        if ($request->has('role')) {
            $role = $request->role;
            $query->where('role', $role);
            
            // Allow parents to list teachers
            if ($currentUser->role === 'parent' && $role === 'teacher') {
                // Parents can see all teachers
                return response()->json($query->orderBy('created_at', 'desc')->get());
            }
        }
        
        // Only teachers and parents with specific permissions can list other users
        if ($currentUser->role !== 'teacher' && $currentUser->role !== 'parent') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        // For parents, restrict to only see teachers or self
        if ($currentUser->role === 'parent') {
            $query->where(function($q) use ($currentUser) {
                $q->where('role', 'teacher')
                  ->orWhere('id', $currentUser->id);
            });
        } else {
            // For teachers, filter by created_by if specified, otherwise show only users created by current teacher
            if ($request->has('created_by')) {
                if ($request->created_by == Auth::id()) {
                    // Show users created by the specified user
                    $query->where(function ($query) {
                        $query->where('created_by', Auth::id())
                              ->orWhere('id', Auth::id()); // Include self
                    });
                } else {
                    // A teacher is trying to see users created by another teacher - not allowed
                    return response()->json(['message' => 'Unauthorized'], 403);
                }
            } else {
                // Default: Filter users created by the current teacher
                $query->where(function ($query) {
                    $query->where('created_by', Auth::id())
                          ->orWhere('id', Auth::id()); // Include self
                });
            }
        }

        $users = $query->orderBy('created_at', 'desc')->get();
        
        return response()->json($users);
    }

    /**
     * Display the specified user.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $user = User::findOrFail($id);
        
        // Check if user has permission to view this user
        $currentUser = Auth::user();
        
        // Allow parent users to view teacher data
        if ($user->role === 'teacher' && $currentUser->role === 'parent') {
            return response()->json($user);
        }
        
        // Otherwise use standard permission checks
        if ($currentUser->role !== 'teacher' && $currentUser->id !== $user->id && $currentUser->id !== $user->created_by) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($user);
    }

    /**
     * Update the specified user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        
        // Check if user has permission to update this user
        $currentUser = Auth::user();
        if ($currentUser->role !== 'teacher' && $currentUser->id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $id,
            'phone_number' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'profile_picture' => 'nullable|string',
            'status' => 'sometimes|string|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->fill($request->only([
            'name',
            'email',
            'phone_number',
            'address',
            'profile_picture',
            'status',
        ]));
        
        // Only teachers can change status
        if ($request->has('status') && $currentUser->role !== 'teacher') {
            unset($user->status);
        }
        
        $user->save();

        return response()->json($user);
    }

    /**
     * Remove the specified user.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        // Only teachers can delete users
        if (Auth::user()->role !== 'teacher') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $user = User::findOrFail($id);
        
        // Cannot delete self or users not created by you
        if ($user->id === Auth::id() || ($user->created_by !== Auth::id())) {
            return response()->json(['message' => 'Cannot delete this user'], 403);
        }
        
        // Soft delete by changing status
        $user->status = 'inactive';
        $user->save();

        return response()->json(['message' => 'User deactivated successfully']);
    }

    /**
     * Change password for a specific user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function changePassword(Request $request, $id)
    {
        $user = User::findOrFail($id);
        
        // Only teachers can change other users' passwords
        $currentUser = Auth::user();
        if ($currentUser->role !== 'teacher' && $currentUser->id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        // Further restrict: teachers can only reset passwords for users they created
        if ($currentUser->role === 'teacher' && $user->created_by !== $currentUser->id) {
            return response()->json(['message' => 'You can only reset passwords for users you created'], 403);
        }

        $validator = Validator::make($request->all(), [
            'new_password' => 'required|string|min:6',
            'is_temp_password' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->password = Hash::make($request->new_password);
        
        if ($request->has('is_temp_password')) {
            $user->is_temp_password = $request->is_temp_password;
        }
        
        $user->save();

        return response()->json(['message' => 'Password changed successfully']);
    }
} 
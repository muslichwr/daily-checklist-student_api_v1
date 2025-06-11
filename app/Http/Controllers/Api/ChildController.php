<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Child;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ChildController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = Auth::user();
        
        if ($user->isTeacher()) {
            $children = Child::where('teacher_id', $user->id)->get();
        } else {
            $children = Child::where('parent_id', $user->id)->get();
        }
        
        return response()->json($children);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        
        if (!$user->isTeacher()) {
            return response()->json(['message' => 'Only teachers can add children'], 403);
        }
        
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'age' => 'required|integer|min:1|max:20',
            'date_of_birth' => 'nullable|date',
            'parent_id' => 'required|exists:users,id',
            'avatar_url' => 'nullable|string',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $child = Child::create([
            'name' => $request->name,
            'age' => $request->age,
            'date_of_birth' => $request->date_of_birth,
            'parent_id' => $request->parent_id,
            'teacher_id' => $user->id,
            'avatar_url' => $request->avatar_url,
        ]);
        
        return response()->json($child, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = Auth::user();
        $child = Child::find($id);
        
        if (!$child) {
            return response()->json(['message' => 'Child not found'], 404);
        }
        
        // Check if user is authorized to view this child
        if ($user->isTeacher() && $child->teacher_id != $user->id) {
            return response()->json(['message' => 'Unauthorized to view this child'], 403);
        }
        
        if ($user->isParent() && $child->parent_id != $user->id) {
            return response()->json(['message' => 'Unauthorized to view this child'], 403);
        }
        
        return response()->json($child);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $user = Auth::user();
        $child = Child::find($id);
        
        if (!$child) {
            return response()->json(['message' => 'Child not found'], 404);
        }
        
        // Check if user is authorized to update this child
        if ($user->isTeacher() && $child->teacher_id != $user->id) {
            return response()->json(['message' => 'Unauthorized to update this child'], 403);
        }
        
        if ($user->isParent() && $child->parent_id != $user->id) {
            return response()->json(['message' => 'Unauthorized to update this child'], 403);
        }
        
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'age' => 'sometimes|required|integer|min:1|max:20',
            'date_of_birth' => 'sometimes|nullable|date',
            'avatar_url' => 'nullable|string',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $child->update($request->only(['name', 'age', 'date_of_birth', 'avatar_url']));
        
        return response()->json($child);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = Auth::user();
        $child = Child::find($id);
        
        if (!$child) {
            return response()->json(['message' => 'Child not found'], 404);
        }
        
        // Only teachers can delete children
        if (!$user->isTeacher() || $child->teacher_id != $user->id) {
            return response()->json(['message' => 'Unauthorized to delete this child'], 403);
        }
        
        $child->delete();
        
        return response()->json(['message' => 'Child deleted successfully']);
    }
}

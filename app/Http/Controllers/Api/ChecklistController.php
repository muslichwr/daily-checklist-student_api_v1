<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Checklist;
use App\Models\HomeObservation;
use App\Models\SchoolObservation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ChecklistController extends Controller
{
    /**
     * Display a listing of the resource by child ID.
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'child_id' => 'required|exists:children,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = Auth::user();
        $childId = $request->child_id;
        
        // Check if user has access to this child's checklists
        $checklistQuery = Checklist::with(['homeObservation', 'schoolObservation'])
            ->where('child_id', $childId)
            ->orderBy('assigned_date', 'desc');
        
        $checklists = $checklistQuery->get();
        
        return response()->json($checklists);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Only teachers can assign activities'], 403);
        }
        
        $validator = Validator::make($request->all(), [
            'child_id' => 'required|exists:children,id',
            'activity_id' => 'required|exists:activities,id',
            'custom_steps_used' => 'required|array',
            'due_date' => 'nullable|date',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $checklist = Checklist::create([
            'child_id' => $request->child_id,
            'activity_id' => $request->activity_id,
            'assigned_date' => now(),
            'due_date' => $request->due_date,
            'status' => 'pending',
            'custom_steps_used' => $request->custom_steps_used,
        ]);
        
        // Create empty observations
        HomeObservation::create([
            'checklist_id' => $checklist->id,
            'completed' => false,
        ]);
        
        SchoolObservation::create([
            'checklist_id' => $checklist->id,
            'completed' => false,
        ]);
        
        // Load relationships
        $checklist->load(['homeObservation', 'schoolObservation']);
        
        return response()->json($checklist, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $checklist = Checklist::with(['homeObservation', 'schoolObservation', 'activity', 'child'])->find($id);
        
        if (!$checklist) {
            return response()->json(['message' => 'Checklist not found'], 404);
        }
        
        return response()->json($checklist);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $checklist = Checklist::find($id);
        
        if (!$checklist) {
            return response()->json(['message' => 'Checklist not found'], 404);
        }
        
        $validator = Validator::make($request->all(), [
            'due_date' => 'nullable|date',
            'custom_steps_used' => 'sometimes|required|array',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $checklist->update($request->only(['due_date', 'custom_steps_used']));
        
        // Load relationships
        $checklist->load(['homeObservation', 'schoolObservation']);
        
        return response()->json($checklist);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = Auth::user();
        
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Only teachers can delete checklists'], 403);
        }
        
        $checklist = Checklist::find($id);
        
        if (!$checklist) {
            return response()->json(['message' => 'Checklist not found'], 404);
        }
        
        $checklist->delete();
        
        return response()->json(['message' => 'Checklist deleted successfully']);
    }
    
    /**
     * Update the status of a checklist.
     */
    public function updateStatus(Request $request, string $id)
    {
        $checklist = Checklist::find($id);
        
        if (!$checklist) {
            return response()->json(['message' => 'Checklist not found'], 404);
        }
        
        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:pending,in-progress,completed',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $checklist->update([
            'status' => $request->status,
        ]);
        
        return response()->json($checklist);
    }
    
    /**
     * Add a home observation to a checklist.
     */
    public function addHomeObservation(Request $request, string $id)
    {
        $checklist = Checklist::find($id);
        
        if (!$checklist) {
            return response()->json(['message' => 'Checklist not found'], 404);
        }
        
        $validator = Validator::make($request->all(), [
            'duration' => 'required|integer|min:1',
            'engagement' => 'required|integer|min:1|max:5',
            'notes' => 'required|string',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        // Update or create home observation
        $homeObservation = HomeObservation::where('checklist_id', $id)->first();
        
        if ($homeObservation) {
            $homeObservation->update([
                'completed' => true,
                'completed_at' => now(),
                'duration' => $request->duration,
                'engagement' => $request->engagement,
                'notes' => $request->notes,
            ]);
        } else {
            $homeObservation = HomeObservation::create([
                'checklist_id' => $id,
                'completed' => true,
                'completed_at' => now(),
                'duration' => $request->duration,
                'engagement' => $request->engagement,
                'notes' => $request->notes,
            ]);
        }
        
        // Update checklist status
        $checklist->update([
            'status' => 'completed',
        ]);
        
        // Load updated data
        $checklist->load(['homeObservation', 'schoolObservation']);
        
        return response()->json($checklist);
    }
    
    /**
     * Add a school observation to a checklist.
     */
    public function addSchoolObservation(Request $request, string $id)
    {
        $user = Auth::user();
        
        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'Only teachers can add school observations'], 403);
        }
        
        $checklist = Checklist::find($id);
        
        if (!$checklist) {
            return response()->json(['message' => 'Checklist not found'], 404);
        }
        
        $validator = Validator::make($request->all(), [
            'duration' => 'required|integer|min:1',
            'engagement' => 'required|integer|min:1|max:5',
            'notes' => 'required|string',
            'learning_outcomes' => 'required|string',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        // Update or create school observation
        $schoolObservation = SchoolObservation::where('checklist_id', $id)->first();
        
        if ($schoolObservation) {
            $schoolObservation->update([
                'completed' => true,
                'completed_at' => now(),
                'duration' => $request->duration,
                'engagement' => $request->engagement,
                'notes' => $request->notes,
                'learning_outcomes' => $request->learning_outcomes,
            ]);
        } else {
            $schoolObservation = SchoolObservation::create([
                'checklist_id' => $id,
                'completed' => true,
                'completed_at' => now(),
                'duration' => $request->duration,
                'engagement' => $request->engagement,
                'notes' => $request->notes,
                'learning_outcomes' => $request->learning_outcomes,
            ]);
        }
        
        // Update checklist status
        $checklist->update([
            'status' => 'completed',
        ]);
        
        // Load updated data
        $checklist->load(['homeObservation', 'schoolObservation']);
        
        return response()->json($checklist);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Observation;
use App\Models\Plan;
use App\Models\Child;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ObservationController extends Controller
{
    /**
     * Helper method to check if a user is a teacher
     *
     * @param  \App\Models\User  $user
     * @return bool
     */
    private function isTeacher($user): bool
    {
        return $user->role === 'teacher';
    }

    /**
     * Display a listing of observations for a specific plan.
     *
     * @param  int  $planId
     * @return \Illuminate\Http\JsonResponse
     */
    public function index($planId)
    {
        try {
            $user = Auth::user();
            $plan = Plan::with(['plannedActivities', 'children'])->findOrFail($planId);

            // Check authorization
            if (Auth::user()->isAdmin()) {
                // Superadmin can view all observations
            } elseif ($this->isTeacher($user)) {
                if ($plan->teacher_id !== $user->id) {
                    return response()->json(['message' => 'Unauthorized'], 403);
                }
            } else {
                // For parents, check if any of their children are in this plan
                $childIds = $user->parentChildren->pluck('id')->toArray();
                $planForParentChild = $plan->children()->wherePivotIn('child_id', $childIds)->exists();
                
                if (!$planForParentChild) {
                    return response()->json(['message' => 'Unauthorized'], 403);
                }
            }

            $observations = Observation::with(['child'])
                ->where('plan_id', $planId)
                ->orderBy('observation_date', 'desc')
                ->get();

            return response()->json([
                'plan' => $plan,
                'observations' => $observations
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving observations: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to retrieve observations: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Store a newly created observation in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $planId
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request, $planId)
    {
        // Only teachers and superadmins can create observations
        if (!$this->isTeacher(Auth::user()) && !Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Hanya guru dan administrator yang dapat membuat observasi'], 403);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'child_id' => 'required|exists:children,id',
            'observation_date' => 'required|date',
            'observation_result' => 'nullable|string',
            'conclusions' => 'required|array',
            'conclusions.presentasi_ulang' => 'required|boolean',
            'conclusions.extension' => 'required|boolean',
            'conclusions.bahasa' => 'required|boolean',
            'conclusions.presentasi_langsung' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Check if plan exists and user has access
            $plan = Plan::findOrFail($planId);
            
            if (Auth::user()->isAdmin()) {
                // Superadmin can create observations for any plan
            } elseif ($plan->teacher_id !== Auth::id()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            // Check if child is part of this plan
            $childInPlan = $plan->children()->where('child_id', $request->child_id)->exists();
            if (!$childInPlan) {
                return response()->json(['message' => 'Child is not part of this plan'], 422);
            }

            // Check if observation already exists for this plan, child, and date
            $existingObservation = Observation::where('plan_id', $planId)
                ->where('child_id', $request->child_id)
                ->where('observation_date', $request->observation_date)
                ->first();

            if ($existingObservation) {
                return response()->json(['message' => 'Observation already exists for this child and date'], 422);
            }

            // Create observation
            $observation = Observation::create([
                'plan_id' => $planId,
                'child_id' => $request->child_id,
                'observation_date' => $request->observation_date,
                'observation_result' => $request->observation_result,
                'conclusions' => $request->conclusions,
            ]);

            // Load relationships
            $observation->load(['child', 'plan']);

            return response()->json($observation, 201);
        } catch (\Exception $e) {
            Log::error('Error creating observation: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to create observation: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified observation.
     *
     * @param  int  $planId
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($planId, $id)
    {
        try {
            $observation = Observation::with(['child', 'plan.plannedActivities'])
                ->where('plan_id', $planId)
                ->findOrFail($id);

            $user = Auth::user();

            // Check authorization
            if (Auth::user()->isAdmin()) {
                // Superadmin can view all observations
            } elseif ($this->isTeacher($user)) {
                if ($observation->plan->teacher_id !== $user->id) {
                    return response()->json(['message' => 'Unauthorized'], 403);
                }
            } else {
                // For parents, check if this observation is for their child
                $childIds = $user->parentChildren->pluck('id')->toArray();
                if (!in_array($observation->child_id, $childIds)) {
                    return response()->json(['message' => 'Unauthorized'], 403);
                }
            }

            return response()->json($observation);
        } catch (\Exception $e) {
            Log::error('Error retrieving observation: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to retrieve observation: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update the specified observation.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $planId
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $planId, $id)
    {
        // Only teachers and superadmins can update observations
        if (!$this->isTeacher(Auth::user()) && !Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Hanya guru dan administrator yang dapat mengupdate observasi'], 403);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'observation_date' => 'sometimes|required|date',
            'observation_result' => 'nullable|string',
            'conclusions' => 'sometimes|required|array',
            'conclusions.presentasi_ulang' => 'required_with:conclusions|boolean',
            'conclusions.extension' => 'required_with:conclusions|boolean',
            'conclusions.bahasa' => 'required_with:conclusions|boolean',
            'conclusions.presentasi_langsung' => 'required_with:conclusions|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $observation = Observation::with(['plan'])
                ->where('plan_id', $planId)
                ->findOrFail($id);

            // Check authorization
            if (Auth::user()->isAdmin()) {
                // Superadmin can update all observations
            } elseif ($observation->plan->teacher_id !== Auth::id()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            // Update observation
            $observation->update($request->only([
                'observation_date',
                'observation_result',
                'conclusions'
            ]));

            // Load relationships
            $observation->load(['child', 'plan']);

            return response()->json($observation);
        } catch (\Exception $e) {
            Log::error('Error updating observation: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to update observation: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified observation.
     *
     * @param  int  $planId
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($planId, $id)
    {
        // Only teachers and superadmins can delete observations
        if (!$this->isTeacher(Auth::user()) && !Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Hanya guru dan administrator yang dapat menghapus observasi'], 403);
        }

        try {
            $observation = Observation::with(['plan'])
                ->where('plan_id', $planId)
                ->findOrFail($id);

            // Check authorization
            if (Auth::user()->isAdmin()) {
                // Superadmin can delete all observations
            } elseif ($observation->plan->teacher_id !== Auth::id()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $observation->delete();

            return response()->json(['message' => 'Observation deleted successfully']);
        } catch (\Exception $e) {
            Log::error('Error deleting observation: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to delete observation: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get observations for a specific child in a plan.
     *
     * @param  int  $planId
     * @param  int  $childId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getChildObservations($planId, $childId)
    {
        try {
            $user = Auth::user();
            $plan = Plan::findOrFail($planId);

            // Check authorization
            if (Auth::user()->isAdmin()) {
                // Superadmin can view all observations
            } elseif ($this->isTeacher($user)) {
                if ($plan->teacher_id !== $user->id) {
                    return response()->json(['message' => 'Unauthorized'], 403);
                }
            } else {
                // For parents, check if this child belongs to them
                $childIds = $user->parentChildren->pluck('id')->toArray();
                if (!in_array($childId, $childIds)) {
                    return response()->json(['message' => 'Unauthorized'], 403);
                }
            }

            $observations = Observation::with(['child'])
                ->where('plan_id', $planId)
                ->where('child_id', $childId)
                ->orderBy('observation_date', 'desc')
                ->get();

            return response()->json([
                'plan' => $plan,
                'child' => Child::find($childId),
                'observations' => $observations
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving child observations: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to retrieve child observations: ' . $e->getMessage()], 500);
        }
    }
}
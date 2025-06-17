<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

class Plan extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'teacher_id', 
        'type', 
        'start_date', 
        'child_id'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'start_date' => 'date',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['progress_by_child', 'overall_progress'];

    /**
     * Get the teacher that owns the plan.
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /**
     * Get the child that this plan is for (if any).
     */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class, 'child_id');
    }

    /**
     * Get all children that this plan is assigned to.
     */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Child::class, 'plan_child')
            ->using(PlanChild::class)
            ->withTimestamps()
            ->withPivot(['id', 'planned_activity_id', 'completed'])
            ->distinct();
    }

    /**
     * Get unique children associated with this plan.
     * Uses a cleaner approach to avoid duplicates.
     */
    public function uniqueChildren()
    {
        $childIds = DB::table('plan_child')
            ->where('plan_id', $this->id)
            ->select('child_id')
            ->distinct()
            ->pluck('child_id');
            
        return Child::whereIn('id', $childIds)->get();
    }

    /**
     * Get all completed activities for a specific child
     */
    public function childActivityCompletions($childId)
    {
        return $this->belongsToMany(PlannedActivity::class, 'plan_child', 'plan_id', 'planned_activity_id')
            ->using(PlanChild::class)
            ->withPivot(['child_id', 'completed'])
            ->wherePivot('child_id', $childId);
    }

    /**
     * Get the planned activities for this plan.
     */
    public function plannedActivities(): HasMany
    {
        return $this->hasMany(PlannedActivity::class);
    }

    /**
     * Get all planned activities for a specific date.
     */
    public function getActivitiesForDate($date)
    {
        return $this->plannedActivities()
            ->whereDate('scheduled_date', $date)
            ->get();
    }
    
    /**
     * Get progress percentage for each child.
     * 
     * @return array
     */
    public function getProgressByChildAttribute()
    {
        $result = [];
        $children = $this->uniqueChildren();
        $totalActivities = $this->plannedActivities()->count();
        
        if ($totalActivities === 0) {
            return $result;
        }
        
        foreach ($children as $child) {
            $completedActivities = DB::table('plan_child')
                ->where('plan_id', $this->id)
                ->where('child_id', $child->id)
                ->where('completed', true)
                ->count();
                
            $result[$child->id] = [
                'child_id' => $child->id,
                'name' => $child->name,
                'completed' => $completedActivities,
                'total' => $totalActivities,
                'percentage' => round(($completedActivities / $totalActivities) * 100, 1)
            ];
        }
        
        return $result;
    }
    
    /**
     * Get overall progress percentage across all children.
     * 
     * @return array
     */
    public function getOverallProgressAttribute()
    {
        $children = $this->uniqueChildren();
        $totalActivities = $this->plannedActivities()->count();
        $childCount = $children->count();
        
        if ($totalActivities === 0 || $childCount === 0) {
            return [
                'completed' => 0,
                'total' => 0,
                'percentage' => 0
            ];
        }
        
        $totalCompletions = DB::table('plan_child')
            ->where('plan_id', $this->id)
            ->where('completed', true)
            ->count();
            
        $totalPossible = $totalActivities * $childCount;
        
        return [
            'completed' => $totalCompletions,
            'total' => $totalPossible,
            'percentage' => round(($totalCompletions / $totalPossible) * 100, 1)
        ];
    }
} 
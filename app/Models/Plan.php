<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
            ->withPivot(['id', 'planned_activity_id', 'completed']);
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
} 
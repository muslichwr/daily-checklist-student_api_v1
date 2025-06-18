<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class PlannedActivity extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'plan_id',
        'activity_id',
        'scheduled_date',
        'scheduled_time',
        'reminder'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'scheduled_date' => 'date',
        'reminder' => 'boolean',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['child_completion_map', 'is_completed'];

    /**
     * Get the plan that owns this planned activity.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Get the activity associated with this planned activity.
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /**
     * Get the children who have completed this activity.
     */
    public function completedByChildren()
    {
        return $this->belongsToMany(Child::class, 'plan_child', 'planned_activity_id', 'child_id')
            ->using(PlanChild::class)
            ->withTimestamps()
            ->withPivot(['completed', 'plan_id'])
            ->wherePivot('completed', true);
    }
    
    /**
     * Get all children with their completion status for this activity.
     */
    public function children()
    {
        return $this->belongsToMany(Child::class, 'plan_child', 'planned_activity_id', 'child_id')
            ->using(PlanChild::class)
            ->withTimestamps()
            ->withPivot(['completed', 'plan_id']);
    }
    
    /**
     * Get the completion status for this activity by a specific child.
     *
     * @param int $childId
     * @return bool
     */
    public function isCompletedByChild($childId)
    {
        $completion = DB::table('plan_child')
            ->where('planned_activity_id', $this->id)
            ->where('child_id', $childId)
            ->first();
            
        return $completion ? (bool)$completion->completed : false;
    }
    
    /**
     * Get all completion records for this activity.
     *
     * @return array
     */
    public function getCompletionStatusForAllChildren()
    {
        $records = DB::table('plan_child')
            ->where('planned_activity_id', $this->id)
            ->get(['child_id', 'completed']);
            
        $result = [];
        foreach ($records as $record) {
            $result[$record->child_id] = (bool)$record->completed;
        }
        
        return $result;
    }
    
    /**
     * Get completion map for all children as attribute.
     *
     * @return array
     */
    public function getChildCompletionMapAttribute()
    {
        return $this->getCompletionStatusForAllChildren();
    }
    
    /**
     * Determine if this activity is completed by any child.
     * 
     * @return bool
     */
    public function getIsCompletedAttribute()
    {
        $completionMap = $this->getChildCompletionMapAttribute();
        return !empty($completionMap) && in_array(true, $completionMap);
    }
} 
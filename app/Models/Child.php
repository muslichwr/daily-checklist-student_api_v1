<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Child extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'age',
        'date_of_birth',
        'parent_id',
        'teacher_id',
        'avatar_url',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date_of_birth' => 'date',
    ];

    /**
     * Get the parent of the child.
     */
    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    /**
     * Get the teacher of the child.
     */
    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /**
     * Get the activities for the child.
     */
    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * Get the checklists for the child.
     */
    public function checklists()
    {
        return $this->hasMany(Checklist::class);
    }

    /**
     * Generate DiceBear avatar URL for a child if none is provided
     */
    public function getAvatarUrlAttribute($value)
    {
        // If avatar_url is not available or empty, use DiceBear
        if (empty($value)) {
            return $this->getDiceBearUrl();
        }

        // If avatar_url is available, use it
        return $value;
    }

    /**
     * Get DiceBear URL as fallback for avatar
     */
    public function getDiceBearUrl()
    {
        $seed = urlencode($this->name);
        return "https://api.dicebear.com/9.x/thumbs/png?seed={$seed}";
    }

    /**
     * Get all plans that this child is assigned to.
     */
    public function plans()
    {
        return $this->belongsToMany(Plan::class, 'plan_child')
            ->using(PlanChild::class)
            ->withTimestamps()
            ->withPivot(['id', 'planned_activity_id', 'completed']);
    }
    
    /**
     * Get all planned activities with their completion status for this child.
     */
    public function plannedActivities()
    {
        return $this->belongsToMany(PlannedActivity::class, 'plan_child', 'child_id', 'planned_activity_id')
            ->using(PlanChild::class)
            ->withPivot(['plan_id', 'completed'])
            ->withTimestamps();
    }
    
    /**
     * Check if a specific activity is completed by this child.
     */
    public function hasCompletedActivity($plannedActivityId)
    {
        $completion = DB::table('plan_child')
            ->where('child_id', $this->id)
            ->where('planned_activity_id', $plannedActivityId)
            ->first();
            
        return $completion ? (bool)$completion->completed : false;
    }
} 
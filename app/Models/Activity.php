<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'description',
        'environment',
        'difficulty',
        'min_age',
        'max_age',
        'duration',
        'next_activity_id',
        'created_by',
    ];

    /**
     * Get the next activity that follows this one.
     */
    public function nextActivity()
    {
        return $this->belongsTo(Activity::class, 'next_activity_id');
    }

    /**
     * Get the activities that have this activity as their next activity.
     */
    public function previousActivities()
    {
        return $this->hasMany(Activity::class, 'next_activity_id');
    }

    /**
     * Get the user who created the activity.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the custom steps for this activity.
     */
    public function activitySteps()
    {
        return $this->hasMany(ActivityStep::class);
    }

    /**
     * Get the checklists for this activity.
     */
    public function checklists()
    {
        return $this->hasMany(Checklist::class);
    }

    /**
     * Check if the activity is appropriate for a given age.
     */
    public function isAppropriateForAge($age): bool
    {
        return $age >= $this->min_age && $age <= $this->max_age;
    }
}

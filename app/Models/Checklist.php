<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Checklist extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'child_id',
        'activity_id',
        'assigned_date',
        'due_date',
        'status',
        'custom_steps_used',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'assigned_date' => 'datetime',
        'due_date' => 'datetime',
        'custom_steps_used' => 'json',
    ];

    /**
     * Get the child that this checklist belongs to.
     */
    public function child()
    {
        return $this->belongsTo(Child::class);
    }

    /**
     * Get the activity for this checklist.
     */
    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    /**
     * Get the home observation for this checklist.
     */
    public function homeObservation()
    {
        return $this->hasOne(HomeObservation::class);
    }

    /**
     * Get the school observation for this checklist.
     */
    public function schoolObservation()
    {
        return $this->hasOne(SchoolObservation::class);
    }

    /**
     * Check if the checklist is overdue.
     */
    public function isOverdue(): bool
    {
        if (!$this->due_date) {
            return false;
        }
        
        if ($this->status === 'completed') {
            return false;
        }

        return $this->due_date < now();
    }

    /**
     * Check if the checklist is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed' || 
               ($this->homeObservation && $this->homeObservation->completed) ||
               ($this->schoolObservation && $this->schoolObservation->completed);
    }

    /**
     * Check if the checklist is in progress.
     */
    public function isInProgress(): bool
    {
        return $this->status === 'in-progress';
    }

    /**
     * Get the status icon for this checklist.
     */
    public function getStatusIcon(): string
    {
        if ($this->isCompleted()) {
            return '✓';
        }
        
        if ($this->isOverdue()) {
            return '⚠️';
        }
        
        if ($this->isInProgress()) {
            return '🔄';
        }
        
        return '⏱️';
    }
}

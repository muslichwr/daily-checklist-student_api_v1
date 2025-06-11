<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'reminder',
        'completed'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'scheduled_date' => 'date',
        'reminder' => 'boolean',
        'completed' => 'boolean',
    ];

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
} 
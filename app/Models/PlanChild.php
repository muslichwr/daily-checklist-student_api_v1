<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class PlanChild extends Pivot
{
    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'plan_child';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'plan_id',
        'child_id',
        'planned_activity_id',
        'completed'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'completed' => 'boolean',
    ];

    /**
     * Make sure completed is always cast to a boolean.
     *
     * @param mixed $value
     * @return bool
     */
    public function getCompletedAttribute($value)
    {
        return (bool) $value;
    }
    
    /**
     * Make sure completed is explicitly set as boolean when saving.
     *
     * @param mixed $value
     * @return void
     */
    public function setCompletedAttribute($value)
    {
        $this->attributes['completed'] = (bool) $value;
    }
} 
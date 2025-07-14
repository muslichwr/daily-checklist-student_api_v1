<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Observation extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'plan_id',
        'child_id',
        'observation_date',
        'observation_result',
        'conclusions',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'observation_date' => 'date',
        'conclusions' => 'array',
    ];

    /**
     * Get the plan that owns this observation.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Get the child that this observation is for.
     */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }
}
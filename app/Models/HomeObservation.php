<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HomeObservation extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'checklist_id',
        'completed',
        'completed_at',
        'duration',
        'engagement',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'completed' => 'boolean',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the checklist that owns this observation.
     */
    public function checklist()
    {
        return $this->belongsTo(Checklist::class);
    }
} 
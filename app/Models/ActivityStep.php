<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityStep extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'activity_id',
        'teacher_id',
        'steps',
        'photos',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'steps' => 'json',
        'photos' => 'json',
    ];

    /**
     * Get the activity that owns the steps.
     */
    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    /**
     * Get the teacher who created these steps.
     */
    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
} 
<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'created_by',
        'is_temp_password',
        'phone_number',
        'address',
        'profile_picture',
        'status',
        'fcm_token',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_temp_password' => 'boolean',
        ];
    }

    /**
     * Check if user is a teacher.
     *
     * @return bool
     */
    public function isTeacher(): bool
    {
        return $this->role === 'teacher';
    }

    /**
     * Check if user is a parent.
     *
     * @return bool
     */
    public function isParent(): bool
    {
        return $this->role === 'parent';
    }

    /**
     * Get the children created by this user (if teacher).
     */
    public function teacherChildren()
    {
        return $this->hasMany(Child::class, 'teacher_id');
    }

    /**
     * Get the children belonging to this user (if parent).
     */
    public function parentChildren()
    {
        return $this->hasMany(Child::class, 'parent_id');
    }

    /**
     * Get the users created by this user (if teacher).
     */
    public function createdUsers()
    {
        return $this->hasMany(User::class, 'created_by');
    }

    /**
     * Get the user who created this user.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

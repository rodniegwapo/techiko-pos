<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImpersonationLog extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'impersonator_id',
        'impersonated_user_id',
        'started_at',
        'ended_at',
        'ip_address',
        'user_agent',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    /**
     * Get the user who performed the impersonation (the super admin).
     */
    public function impersonator()
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    /**
     * Get the user who was impersonated.
     */
    public function impersonatedUser()
    {
        return $this->belongsTo(User::class, 'impersonated_user_id');
    }

    /**
     * Check if this impersonation session is still active.
     */
    public function isActive(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Get the duration of the impersonation session in minutes.
     */
    public function getDuration(): ?int
    {
        if (!$this->ended_at) {
            return null;
        }

        return $this->started_at->diffInMinutes($this->ended_at);
    }
}

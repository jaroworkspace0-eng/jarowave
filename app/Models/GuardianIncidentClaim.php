<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// app/Models/GuardianIncidentClaim.php
class GuardianIncidentClaim extends Model
{
    protected $fillable = [
        'emergency_alert_id',
        'claimed_by_user_id',
        'status',
        'claimed_at',
        'arrived_at',
        'resolved_at',
        'resolution_note',
        'outcome',
        'police_responded',
        'still_needs_help_count',
    ];

    protected $casts = [
        'claimed_at'  => 'datetime',
        'arrived_at'  => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(EmergencyAlert::class, 'emergency_alert_id');
    }

    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }
}

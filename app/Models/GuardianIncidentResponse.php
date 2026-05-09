<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuardianIncidentResponse extends Model
{
    protected $fillable = [
        'emergency_alert_id',
        'guardian_user_id',
        'action',
        'note',
        'responded_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(EmergencyAlert::class, 'emergency_alert_id');
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guardian_user_id');
    }
}

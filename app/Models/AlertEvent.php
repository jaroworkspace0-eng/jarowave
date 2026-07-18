<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertEvent extends Model
{
    protected $fillable = [
        'emergency_alert_id',
        'actor_type',
        'actor_id',
        'event_type',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function emergencyAlert()
    {
        return $this->belongsTo(EmergencyAlert::class);
    }
}
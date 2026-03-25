<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmergencyResolution extends Model
{
    protected $fillable = [
        'emergency_alert_id',
        'responder_user_id',
        'accepted_at',
        'resolution_time',
        'arrival_time',
        'arrival_latitude',
        'arrival_longitude',
        'start_latitude',
        'start_longitude',
        'response_duration',
        'distance_traveled',
        'status',
        'notes',
    ];

    public function emergencyAlert()
    {
        return $this->belongsTo(EmergencyAlert::class);
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'responder_user_id');
    }

    public function responder()
    {
        return $this->belongsTo(User::class, 'responder_user_id');
    }
}

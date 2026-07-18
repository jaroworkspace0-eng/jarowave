<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertGuardianNotification extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'emergency_alert_id',
        'guardian_id',
        'notified_at',
        'responded_at',
        'response_type',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function emergencyAlert()
    {
        return $this->belongsTo(EmergencyAlert::class);
    }

    public function guardian()
    {
        return $this->belongsTo(User::class, 'guardian_id');
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertFlagEvent extends Model
{
    protected $fillable = [
        'user_id', 'emergency_alert_id', 'channel_id',
        'event_type', 'actor_id', 'actor_role',
        'alert_count_at_event', 'note',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function emergencyAlert()
    {
        return $this->belongsTo(EmergencyAlert::class);
    }
}
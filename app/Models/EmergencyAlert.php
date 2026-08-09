<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmergencyAlert extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'channel_id',
        'client_id',
        'latitude',
        'longitude',
        'alert_location_source',
        'is_estate',
        'address_line_1',
        'complex_name',
        'suburb',
        'unit_number',
        'name',
        'phone',
        'email',
        'accuracy',
        'is_resolved',
        'resolved_at',
        'resolved_by',
        'alert_type',
        'cancel_pin_used',
        'trigger_lat',
        'trigger_lng',
        'last_lat',
        'last_lng',
        'location_updated_at',
        'first_ack_at',
        'muted',
    ];

    protected $casts = [
        'muted' => 'boolean',
        'is_resolved' => 'boolean',
        'is_estate' => 'boolean',
        'resolved_at' => 'datetime',
        'first_ack_at' => 'datetime',
        'location_updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function resolution()
    {
        return $this->hasOne(EmergencyResolution::class);
    }

    public function dvRecording()
    {
        return $this->hasOne(DvRecording::class, 'alert_id');
    }

    public function events()
    {
        return $this->hasMany(AlertEvent::class);
    }

    public function guardianNotifications()
    {
        return $this->hasMany(AlertGuardianNotification::class);
    }

    public function incidentReport() { return $this->hasOne(SosIncidentReport::class, 'emergency_alert_id'); }
    public function resolutions() { return $this->hasMany(EmergencyResolution::class); }

}
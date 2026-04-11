<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SosIncidentReport extends Model
{
    protected $fillable = [
        'emergency_alert_id',
        'household_user_id',
        'reporter_user_id',
        'outcome',
        'misuse_category',
        'narrative',
        'arrived_at',
        'departed_at',
        'injuries_reported',
        'property_damage',
        'additional_notes',
        'status',
        'admin_notes',
        'actioned_by',
        'actioned_at',
    ];

    protected $casts = [
        'arrived_at'        => 'datetime',
        'departed_at'       => 'datetime',
        'actioned_at'       => 'datetime',
        'injuries_reported' => 'boolean',
        'property_damage'   => 'boolean',
    ];

    public function emergencyAlert(): BelongsTo
    {
        return $this->belongsTo(EmergencyAlert::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(User::class, 'household_user_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function actionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'household_user_id', 'user_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeMisuse($query)
    {
        return $query->where('outcome', 'misuse');
    }
}
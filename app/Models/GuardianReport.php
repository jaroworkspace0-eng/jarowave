<?php
// app/Models/GuardianReport.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuardianReport extends Model
{
    protected $fillable = [
        'alert_id',
        'alert_type',
        'reporting_household_id',
        'description',
        'seen_perpetrator',
        'heard_disturbance',
        'severity',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'review_status',
        'incident_report_id',
    ];

    protected $casts = [
        'seen_perpetrator'  => 'boolean',
        'heard_disturbance' => 'boolean',
        'submitted_at'      => 'datetime',
        'reviewed_at'       => 'datetime',
    ];

    public function reportingHousehold(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporting_household_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function incidentReport(): BelongsTo
    {
        return $this->belongsTo(SosIncidentReport::class, 'incident_report_id');
    }

    public function scopePending($query)
    {
        return $query->where('review_status', 'pending');
    }

    public function scopeEscalated($query)
    {
        return $query->where('review_status', 'escalated');
    }
}
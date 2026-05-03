<?php
// app/Models/HouseholdPairing.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HouseholdPairing extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'requester_id',
        'receiver_id',
        'status',
        'requested_at',
        'responded_at',
        'dissolved_at',
        'dissolved_by',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'responded_at' => 'datetime',
        'dissolved_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function dissolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dissolved_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInvolving($query, int $householdId)
    {
        return $query->where('requester_id', $householdId)
                     ->orWhere('receiver_id', $householdId);
    }
}
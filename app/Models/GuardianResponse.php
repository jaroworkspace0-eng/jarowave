<?php
// app/Models/GuardianResponse.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuardianResponse extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'alert_id',
        'alert_type',
        'guardian_household_id',
        'response_type',
        'notified_at',
        'responded_at',
    ];

    protected $casts = [
        'notified_at'  => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function guardianHousehold(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guardian_household_id');
    }
}
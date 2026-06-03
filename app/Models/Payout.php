<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payout extends Model
{
    protected $fillable = [
        'client_id',
        'reference',
        'period_start',
        'period_end',
        'household_count',
        'gross_amount',
        'platform_fee',
        'net_amount',
        'status',
        'paid_at',
        'transfer_reference',
    ];

    protected $casts = [
        'period_start' => 'datetime',
        'period_end'   => 'datetime',
        'paid_at'      => 'datetime',
        'gross_amount' => 'float',
        'platform_fee' => 'float',
        'net_amount'   => 'float',
    ];

    // The watch group / CPF client
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }
}
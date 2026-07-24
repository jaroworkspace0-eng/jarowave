<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChannelSubscription extends Model
{
    protected $fillable = [
        'channel_id',
        'household_count',
        'amount_per_household',
        'amount_per_linked_account',
        'total_amount',
        'status',
        'billing_model',
        'current_period_start',
        'current_period_end',
        'paid_at',
    ];

    protected $casts = [
        'amount_per_household' => 'decimal:2',
        'total_amount'         => 'decimal:2',
        'current_period_start' => 'datetime',
        'current_period_end'   => 'datetime',
        'paid_at'              => 'datetime',
    ];

    // ── Relationships ──

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ChannelSubscriptionPayment::class);
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(ChannelSubscriptionPayment::class)->latestOfMany();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    // ── Amount helpers ──

    public function getTotalAmountInRandsAttribute(): string
    {
        return 'R' . number_format($this->total_amount, 2);
    }

    public function getAmountPerHouseholdInRandsAttribute(): string
    {
        return 'R' . number_format($this->amount_per_household, 2);
    }

    // ── Status checks ──

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isOverdue(): bool
    {
        return $this->status === 'overdue';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isBulk(): bool
    {
        return $this->billing_model === 'bulk';
    }
}
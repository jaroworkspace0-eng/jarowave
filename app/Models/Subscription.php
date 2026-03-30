<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    protected $fillable = [
        'client_id',
        'user_id',
        'gateway',
        'plan',
        'billing_cycle',
        'status',
        'price',
        'original_price',
        'discount_amount',
        'discount_percentage',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'cancelled_at',
        'ends_at', 
    ];

    protected $casts = [
        'trial_ends_at'          => 'datetime',
        'current_period_start'   => 'datetime',
        'current_period_end'     => 'datetime',
        'cancelled_at'           => 'datetime',
        'ends_at'                => 'datetime',
        'price'                  => 'integer',
        'original_price'         => 'integer',
        'discount_amount'        => 'integer',
        'discount_percentage'    => 'integer',
    ];

    // ── Relationships ──

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    // ── Helpers ──

    /**
     * Price in Rands for display e.g. R499.00
     */
    public function getPriceInRandsAttribute(): string
    {
        return 'R' . number_format($this->price / 100, 2);
    }

    public function getOriginalPriceInRandsAttribute(): string
    {
        return 'R' . number_format($this->original_price / 100, 2);
    }

    public function getSavingsInRandsAttribute(): string
    {
        return 'R' . number_format($this->discount_amount / 100, 2);
    }

    // ── Status checks ──

    public function isTrialing(): bool
    {
        return $this->status === 'trialing' && $this->trial_ends_at && now()->lessThan($this->trial_ends_at);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && now()->lessThan($this->current_period_end);
    }

    public function isExpired(): bool
    {
        return $this->status === 'active' && now()->greaterThan($this->current_period_end);
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function onTrial(): bool
    {
        return $this->isTrialing();
    }

    public function daysLeftInTrial(): int
    {
        if (!$this->isTrialing() || !$this->trial_ends_at) return 0;
        return (int) now()->diffInDays($this->trial_ends_at);
    }

    public function daysLeftInPeriod(): int
    {
        if (!$this->current_period_end) return 0;
        return (int) now()->diffInDays($this->current_period_end);
    }

    // Latest payment shortcut — useful for dashboard display
    public function latestPayment()
    {
        return $this->hasOne(SubscriptionPayment::class)->latestOfMany();
    }
}
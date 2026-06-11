<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChannelSubscriptionPayment extends Model
{
    protected $fillable = [
        'channel_subscription_id',
        'amount',
        'household_count',
        'amount_per_household',
        'payment_method',
        'status',
        'merchant_reference',
        'gateway_transaction_id',
        'gateway_payload',
        'proof_of_payment',
        'notes',
        'ip_address',
        'paid_at',
    ];

    protected $casts = [
        'amount'               => 'decimal:2',
        'amount_per_household' => 'decimal:2',
        'gateway_payload'      => 'array',
        'paid_at'              => 'datetime',
    ];

    // ── Relationships ──

    public function channelSubscription(): BelongsTo
    {
        return $this->belongsTo(ChannelSubscription::class);
    }

    public function earning(): HasOne
    {
        return $this->hasOne(Earning::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    // ── Amount helpers ──

    public function getAmountInRandsAttribute(): string
    {
        return 'R' . number_format($this->amount, 2);
    }

    public function getAmountPerHouseholdInRandsAttribute(): string
    {
        return 'R' . number_format($this->amount_per_household, 2);
    }

    // ── Status checks ──

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isEft(): bool
    {
        return $this->payment_method === 'eft';
    }

    public function isPayfast(): bool
    {
        return $this->payment_method === 'payfast';
    }

    // ── Actions ──

    public function markPaid(string $merchantReference, ?array $payload = null): void
    {
        $this->update([
            'status'             => 'paid',
            'merchant_reference' => $merchantReference,
            'gateway_payload'    => $payload ?? $this->gateway_payload,
            'paid_at'            => now(),
        ]);
    }

    public function markFailed(?array $payload = null): void
    {
        $this->update([
            'status'          => 'failed',
            'gateway_payload' => $payload ?? $this->gateway_payload,
        ]);
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SubscriptionPayment extends Model
{
    protected $fillable = [
        'subscription_id',
        'gateway',
        'gateway_transaction_id',
        'gateway_payment_reference',
        'gateway_status',
        'amount',
        'amount_gross',
        'amount_fee',
        'amount_net',
        'currency',
        'payment_method',
        'payer_name',
        'payer_email',
        'status',
        'failure_reason',
        'gateway_payload',
        'billing_period_start',
        'billing_period_end',
        'paid_at',
    ];

    protected $casts = [
        'amount'               => 'integer',
        'amount_gross'         => 'integer',
        'amount_fee'           => 'integer',
        'amount_net'           => 'integer',
        'gateway_payload'      => 'array',   // automatically encode/decode JSON
        'billing_period_start' => 'datetime',
        'billing_period_end'   => 'datetime',
        'paid_at'              => 'datetime',
    ];

    // ── Relationships ──

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function earning(): HasOne
    {
        return $this->hasOne(Earning::class);
    }

    // ── Amount helpers — display in Rands ──

    public function getAmountInRandsAttribute(): string
    {
        return 'R' . number_format($this->amount / 100, 2);
    }

    public function getAmountNetInRandsAttribute(): string
    {
        return 'R' . number_format(($this->amount_net ?? 0) / 100, 2);
    }

    public function getAmountFeeInRandsAttribute(): string
    {
        return 'R' . number_format(($this->amount_fee ?? 0) / 100, 2);
    }

    // ── Status checks ──

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isRefunded(): bool
    {
        return $this->status === 'refunded';
    }

    // ── Gateway helpers ──

    public function isPayfast(): bool
    {
        return $this->gateway === 'payfast';
    }

    public function isOzow(): bool
    {
        return $this->gateway === 'ozow';
    }

    /**
     * Store the raw ITN / webhook payload safely.
     * Called from your PayFast or Ozow webhook controller.
     */
    public function recordPayload(array $payload): void
    {
        $this->update(['gateway_payload' => $payload]);
    }

    /**
     * Mark payment as paid — called when ITN confirms success.
     */
    public function markPaid(string $gatewayTransactionId, ?array $payload = null): void
    {
        $this->update([
            'status'                 => 'paid',
            'gateway_transaction_id' => $gatewayTransactionId,
            'gateway_payload'        => $payload ?? $this->gateway_payload,
            'paid_at'                => now(),
        ]);
    }

    /**
     * Mark payment as failed — called when ITN or redirect signals failure.
     */
    public function markFailed(string $reason, ?array $payload = null): void
    {
        $this->update([
            'status'         => 'failed',
            'failure_reason' => $reason,
            'gateway_payload' => $payload ?? $this->gateway_payload,
        ]);
    }
}
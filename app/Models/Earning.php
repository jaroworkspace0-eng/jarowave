<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Earning extends Model
{
    protected $fillable = [
        'client_id',
        'subscription_payment_id',
        'resident_id',
        'resident_amount',
        'commission_pct',
        'earned_amount',
        'platform_amount',
        'status',
        'payout_at',
        'payout_reference',
        'period_start',
        'period_end',
    ];

    protected $casts = [
        'resident_amount'  => 'integer',
        'commission_pct'   => 'integer',
        'earned_amount'    => 'integer',
        'platform_amount'  => 'integer',
        'payout_at'        => 'datetime',
        'period_start'     => 'datetime',
        'period_end'       => 'datetime',
    ];

    // ── Relationships ──

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resident_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class, 'subscription_payment_id');
    }

    // ── Amount helpers ──

    public function getEarnedAmountInRandsAttribute(): string
    {
        return 'R' . number_format($this->earned_amount / 100, 2);
    }

    public function getResidentAmountInRandsAttribute(): string
    {
        return 'R' . number_format($this->resident_amount / 100, 2);
    }

    public function getPlatformAmountInRandsAttribute(): string
    {
        return 'R' . number_format($this->platform_amount / 100, 2);
    }

    // ── Status checks ──

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isWithheld(): bool
    {
        return $this->status === 'withheld';
    }

    // ── Business logic ──

    /**
     * Calculate and set amounts from the resident payment.
     * Call this when creating an earning row.
     *
     * Usage:
     * Earning::createFromPayment($payment, $client, commissionPct: 65);
     */
    public static function createFromPayment(
        SubscriptionPayment $payment,
        Client $client,
        int $commissionPct = 65
    ): self {
        $residentAmount  = $payment->amount;
        $earnedAmount    = (int) round($residentAmount * ($commissionPct / 100));
        $platformAmount  = $residentAmount - $earnedAmount;

        return self::create([
            'client_id'               => $client->id,
            'subscription_payment_id' => $payment->id,
            'resident_id'             => $payment->subscription->client->user_id,
            'resident_amount'         => $residentAmount,
            'commission_pct'          => $commissionPct,
            'earned_amount'           => $earnedAmount,
            'platform_amount'         => $platformAmount,
            'status'                  => 'pending',
            'period_start'            => $payment->billing_period_start,
            'period_end'              => $payment->billing_period_end,
        ]);
    }

    /**
     * Mark as paid out — called when you EFT the watch group.
     */
    public function markPaid(string $payoutReference): void
    {
        $this->update([
            'status'           => 'paid',
            'payout_at'        => now(),
            'payout_reference' => $payoutReference,
        ]);
    }

    /**
     * Withhold an earning — e.g. disputed payment or refund issued.
     */
    public function withhold(): void
    {
        $this->update(['status' => 'withheld']);
    }

    /**
     * Assert amounts always balance — useful in tests.
     * earned_amount + platform_amount must always equal resident_amount.
     */
    public function balances(): bool
    {
        return ($this->earned_amount + $this->platform_amount) === $this->resident_amount;
    }
}
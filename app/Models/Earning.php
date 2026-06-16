<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class Earning extends Model
{
    protected $fillable = [
        'client_id',
        'subscription_payment_id',
        'channel_subscription_payment_id',
        'resident_id',
        'resident_amount',
        'commission_percentage',
        'earned_amount',
        'platform_amount',
        'status',
        'payout_at',
        'payout_reference',
        'period_start',
        'period_end',
    ];

    protected $casts = [
        'resident_amount'       => 'integer',
        'commission_percentage' => 'integer',
        'earned_amount'         => 'integer',
        'platform_amount'       => 'integer',
        'payout_at'             => 'datetime',
        'period_start'          => 'datetime',
        'period_end'            => 'datetime',
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

    public function channelSubscriptionPayment(): BelongsTo
    {
        return $this->belongsTo(ChannelSubscriptionPayment::class);
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
     * Create an earning from an individual household payment.
     *
     * Usage:
     *   Earning::createFromPayment($payment, $client);
     */
    public static function createFromPayment(
        SubscriptionPayment $payment,
        Client $client,
    ): self {
        $commissionPct  = $client->revenue_share_percentage;
        $residentAmount = $payment->amount;
        $earnedAmount   = round($residentAmount * ($commissionPct / 100), 2);
        $platformAmount = round($residentAmount - $earnedAmount, 2);

        return self::create([
            'client_id'               => $client->user_id,
            'subscription_payment_id' => $payment->id,
            'resident_id'             => $payment->subscription->user_id,
            'resident_amount'         => $residentAmount,
            'commission_percentage'   => $commissionPct,
            'earned_amount'           => $earnedAmount,
            'platform_amount'         => $platformAmount,
            'status'                  => 'pending',
            'period_start'            => $payment->billing_period_start,
            'period_end'              => $payment->billing_period_end,
        ]);
    }

    /**
     * Create an earning from an estate bulk payment.
     * One record per channel payment — security company earns their cut
     * based on the channel's custom split (or client default if not set).
     *
     * Usage:
     *   Earning::createFromChannelPayment($payment, $client);
     */
    public static function createFromChannelPayment(
        ChannelSubscriptionPayment $payment,
        Client $client,
    ): self {
        $channelSubscription = $payment->channelSubscription;
        $channel             = $channelSubscription->channel;

        $split = \App\Services\BillingService::calculateChannelSplit(
            $channel,
            $payment->household_count
        );

        $totalAmount    = $payment->amount;
        $earnedAmount   = round($split['security_payout'], 2);
        $platformAmount = round($totalAmount - $earnedAmount - $split['guard_pool_total'], 2);

        return self::create([
            'client_id'                       => $client->user_id,
            'channel_subscription_payment_id' => $payment->id,
            'subscription_payment_id'         => null,
            'resident_id'                     => null,
            'resident_amount'                 => $totalAmount,
            'commission_percentage'           => $split['security_percentage'],
            'earned_amount'                   => $earnedAmount,
            'platform_amount'                 => $platformAmount,
            'status'                          => 'pending',
            'period_start'                    => $channelSubscription->current_period_start,
            'period_end'                      => $channelSubscription->current_period_end,
        ]);
    }

    /**
     * Create individual gate guard earnings for an estate bulk payment.
     * Splits the guard pool evenly among all gate guards in the channel.
     *
     * Usage:
     *   Earning::createGateGuardEarnings($payment);
     */
    public static function createGateGuardEarnings(ChannelSubscriptionPayment $payment): \Illuminate\Support\Collection
    {
        $channelSubscription = $payment->channelSubscription;
        $channel             = $channelSubscription->channel;

        $split = \App\Services\BillingService::calculateChannelSplit(
            $channel,
            $payment->household_count
        );

        if ($split['guard_pool_total'] <= 0) {
            return collect();
        }

        $gateGuards = User::where('role', 'employee')
        ->where('is_gate_guard', true)
        ->whereHas('employee.channels', fn($q) => $q->where('channels.id', $channel->id))
        ->get();

        if ($gateGuards->isEmpty()) {
            Log::warning('No gate guards found for channel - guard pool unallocated, flagging for review', [
                'channel_id'       => $channel->id,
                'guard_pool_total' => $split['guard_pool_total'],
            ]);

            // No guards to pay - money stays unallocated, do not silently lose it.
            // Admin should be alerted via this log / a dashboard flag.
            return collect();
        }

        $amountPerGuard = round($split['guard_pool_total'] / $gateGuards->count(), 2);

        return $gateGuards->map(function ($guard) use ($payment, $channelSubscription, $amountPerGuard) {
            return self::create([
                'client_id'                       => null,
                'channel_subscription_payment_id' => $payment->id,
                'subscription_payment_id'         => null,
                'resident_id'                     => $guard->id,
                'resident_amount'                 => $amountPerGuard,
                'commission_percentage'           => 100,
                'earned_amount'                   => $amountPerGuard,
                'platform_amount'                 => 0,
                'status'                          => 'pending',
                'period_start'                    => $channelSubscription->current_period_start,
                'period_end'                      => $channelSubscription->current_period_end,
            ]);
        });
    }

    /**
     * Mark as paid out — called when you EFT the watch group / security company.
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
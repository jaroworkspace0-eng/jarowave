<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = [
        'subscription_id',
        'subscription_payment_id',
        'channel_subscription_id',
        'channel_subscription_payment_id',
        'client_id',
        'invoice_number',
        'status',
        'subtotal',
        'discount_amount',
        'total',
        'currency',
        'notes',
        'paid_via_estate',
        'invoice_type',
        'issued_at',
        'sent_at',
        'due_date',
    ];

    protected $casts = [
        'subtotal'        => 'integer',
        'discount_amount' => 'integer',
        'total'           => 'integer',
        'paid_via_estate' => 'boolean',
        'issued_at'       => 'datetime',
        'sent_at'         => 'datetime',
    ];

    // ── Relationships ──

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class, 'subscription_payment_id');
    }

    // client_id references users.id (the watch group admin/client user)
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function channelSubscription(): BelongsTo
    {
        return $this->belongsTo(ChannelSubscription::class);
    }

    public function channelSubscriptionPayment(): BelongsTo
    {
        return $this->belongsTo(ChannelSubscriptionPayment::class);
    }

    // ── Helpers ──

    public function getSubtotalInRandsAttribute(): string
    {
        return 'R' . number_format($this->subtotal / 100, 2);
    }

    public function getDiscountInRandsAttribute(): string
    {
        return 'R' . number_format($this->discount_amount / 100, 2);
    }

    public function getTotalInRandsAttribute(): string
    {
        return 'R' . number_format($this->total / 100, 2);
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    // ── Auto-generate invoice number ──

    public static function generateNumber(): string
    {
        $year = now()->format('Y');
        $last = self::whereYear('created_at', $year)->max('id') ?? 0;
        $seq  = str_pad($last + 1, 5, '0', STR_PAD_LEFT);
        return "ECL-INV-{$year}-{$seq}";
    }

    // ── Create invoice from individual payment — called in webhook ──

    public static function createFromPayment(SubscriptionPayment $payment): self
    {
        $subscription   = $payment->subscription;
        $subtotal       = round($subscription->original_price ?? $payment->amount_gross, 2);
        $discountAmount = round($subscription->discount_amount ?? 0, 2);
        $total          = round($payment->amount, 2);

        return self::create([
            'subscription_id'         => $subscription->id,
            'subscription_payment_id' => $payment->id,
            'client_id'               => $subscription->user_id,
            'invoice_number'          => self::generateNumber(),
            'status'                  => 'paid',
            'subtotal'                => $subtotal,
            'discount_amount'         => $discountAmount,
            'total'                   => $total,
            'currency'                => 'ZAR',
            'invoice_type'            => 'individual',
            'paid_via_estate'         => false,
            'issued_at'               => now(),
            'due_date'                => $payment->billing_period_end,
        ]);
    }

    // ── Create invoice from estate bulk payment ──

    // ── createFromChannelPayment — extended signature ──
    // New 5th param: $accountLink, used only when invoiceType is
    // 'estate_linked_account'. Mirrors the existing 'estate_household'
    // pattern exactly — same bulk payment, one more invoice per linked
    // account instead of per household.
    // ── createFromChannelPayment — extended signature ──
// New 5th param: $accountLink, used only when invoiceType is
// 'estate_linked_account'. Mirrors the existing 'estate_household'
// pattern exactly — same bulk payment, one more invoice per linked
// account instead of per household.
public static function createFromChannelPayment(
    ChannelSubscriptionPayment $payment,
    ChannelSubscription $channelSubscription,
    string $invoiceType = 'estate_bulk',
    ?Subscription $subscription = null,
    ?AccountLink $accountLink = null
): self {
    $total = match ($invoiceType) {
        'estate_household'      => $channelSubscription->amount_per_household * 100,
        'estate_linked_account' => $channelSubscription->amount_per_linked_account * 100,
        default                 => $channelSubscription->total_amount * 100, // estate_bulk
    };

    $clientId = match ($invoiceType) {
        'estate_household'      => $subscription?->user_id,
        'estate_linked_account' => $accountLink?->linked_account_id,
        default                 => $channelSubscription->channel->billingContact?->user_id,
    };

    return self::create([
        'channel_subscription_id'         => $channelSubscription->id,
        'channel_subscription_payment_id' => $payment->id,
        'subscription_id'                 => $subscription?->id,
        'subscription_payment_id'         => null,
        'client_id'                       => $clientId,
        'invoice_number'                  => self::generateNumber(),
        'status'                          => 'paid',
        'subtotal'                        => $total,
        'discount_amount'                 => 0,
        'total'                           => $total,
        'currency'                        => 'ZAR',
        'paid_via_estate'                 => in_array($invoiceType, ['estate_household', 'estate_linked_account']),
        'invoice_type'                    => $invoiceType,
        'issued_at'                       => now(),
        'due_date'                        => $channelSubscription->current_period_end,
    ]);
}
    // public static function createFromChannelPayment(
    //     ChannelSubscriptionPayment $payment,
    //     ChannelSubscription $channelSubscription,
    //     string $invoiceType = 'estate_bulk',
    //     ?Subscription $subscription = null,
    //     ?AccountLink $accountLink = null
    // ): self {
    //     $total = match ($invoiceType) {
    //         'estate_household'      => $channelSubscription->amount_per_household * 100,
    //         'estate_linked_account' => $channelSubscription->amount_per_linked_account * 100,
    //         default                 => $channelSubscription->total_amount * 100, // estate_bulk
    //     };

    //     $clientId = match ($invoiceType) {
    //         'estate_household'      => $subscription?->user_id,
    //         'estate_linked_account' => $accountLink?->linked_account_id,
    //         default                 => $channelSubscription->channel->billingContact?->user_id,
    //     };

    //     return self::create([
    //         'channel_subscription_id'         => $channelSubscription->id,
    //         'channel_subscription_payment_id' => $payment->id,
    //         'subscription_id'                 => $subscription?->id,
    //         'subscription_payment_id'         => null,
    //         'client_id'                       => $clientId,
    //         'invoice_number'                  => self::generateNumber(),
    //         'status'                          => 'paid',
    //         'subtotal'                        => $total,
    //         'discount_amount'                 => 0,
    //         'total'                           => $total,
    //         'currency'                        => 'ZAR',
    //         'paid_via_estate'                 => in_array($invoiceType, ['estate_household', 'estate_linked_account']),
    //         'invoice_type'                    => $invoiceType,
    //         'issued_at'                       => now(),
    //         'due_date'                        => $channelSubscription->current_period_end,
    //     ]);
    // }
    // public static function createFromChannelPayment(
    //     ChannelSubscriptionPayment $payment,
    //     ChannelSubscription $channelSubscription,
    //     string $invoiceType = 'estate_bulk',
    //     ?Subscription $subscription = null
    // ): self {
    //     $isHousehold = $invoiceType === 'estate_household';

    //     $total = $isHousehold
    //         ? $channelSubscription->amount_per_household * 100  // R80 in cents
    //         : $channelSubscription->total_amount * 100;         // e.g. R2,720 in cents

    //     $clientId = $isHousehold
    //         ? $subscription?->user_id
    //         : $channelSubscription->channel->billingContact?->user_id;

    //     return self::create([
    //         'channel_subscription_id'         => $channelSubscription->id,
    //         'channel_subscription_payment_id' => $payment->id,
    //         'subscription_id'                 => $subscription?->id,
    //         'subscription_payment_id'         => null,
    //         'client_id'                       => $clientId,
    //         'invoice_number'                  => self::generateNumber(),
    //         'status'                          => 'paid',
    //         'subtotal'                        => $total,
    //         'discount_amount'                 => 0,
    //         'total'                           => $total,
    //         'currency'                        => 'ZAR',
    //         'paid_via_estate'                 => $isHousehold,
    //         'invoice_type'                    => $invoiceType,
    //         'issued_at'                       => now(),
    //         'due_date'                        => $channelSubscription->current_period_end,
    //     ]);
    // }
}
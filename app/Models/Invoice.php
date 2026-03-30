<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = [
        'subscription_payment_id',
        'client_id',
        'invoice_number',
        'status',
        'subtotal',
        'discount_amount',
        'total',
        'currency',
        'notes',
        'issued_at',
        'sent_at',
    ];

    protected $casts = [
        'subtotal'        => 'integer',
        'discount_amount' => 'integer',
        'total'           => 'integer',
        'issued_at'       => 'datetime',
        'sent_at'         => 'datetime',
    ];

    // ── Relationships ──

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class, 'subscription_payment_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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
        $year  = now()->format('Y');
        $last  = self::whereYear('created_at', $year)->max('id') ?? 0;
        $seq   = str_pad($last + 1, 5, '0', STR_PAD_LEFT);
        return "ECL-INV-{$year}-{$seq}";
    }

    // ── Create invoice from a payment — called in webhook ──

    public static function createFromPayment(SubscriptionPayment $payment): self
    {
        $subscription = $payment->subscription;

        return self::create([
            'subscription_payment_id' => $payment->id,
            'client_id'               => $subscription->client_id,
            'invoice_number'          => self::generateNumber(),
            'status'                  => 'issued',
            'subtotal'                => $subscription->original_price,
            'discount_amount'         => $subscription->discount_amount ?? 0,
            'total'                   => $payment->amount,
            'currency'                => 'ZAR',
            'issued_at'               => now(),
        ]);
    }
}
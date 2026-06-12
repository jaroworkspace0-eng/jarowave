<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\ChannelSubscription;
use App\Models\ChannelSubscriptionPayment;
use App\Models\Earning;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ChannelBillingService
{
    // -------------------------------------------------------------------------
    // Opt-In / Opt-Out
    // -------------------------------------------------------------------------

    /**
     * Opt a household into estate bulk billing.
     * Cancels their individual subscription and links them to the channel subscription.
     */
    public function optInHousehold(User $user, Channel $channel): void
    {
        $channelSubscription = $this->resolveActiveChannelSubscription($channel);

        DB::transaction(function () use ($user, $channel, $channelSubscription) {
            // Cancel individual subscription
            $subscription = Subscription::where('user_id', $user->id)
                ->whereNotIn('status', ['cancelled'])
                ->latest()
                ->first();

            if ($subscription) {
                $subscription->update([
                    'status'                   => 'cancelled',
                    'cancelled_at'             => now(),
                    'ends_at'                  => $subscription->current_period_end,
                    'cancellation_reason'      => 'estate_optin',
                    'channel_subscription_id'  => $channelSubscription?->id,
                ]);
            }

            // Update user status — inherit from channel subscription
            $user->update([
                'subscription_status' => $channelSubscription?->status === 'active' ? 'active' : 'pending',
            ]);
        });

        Log::info('Household opted into estate billing', [
            'user_id'                  => $user->id,
            'channel_id'               => $channel->id,
            'channel_subscription_id'  => $channelSubscription?->id,
        ]);
    }

    /**
     * Opt a household out of estate bulk billing.
     * Restores them to individual billing with a fresh subscription.
     */
   public function optOutHousehold(User $user, Channel $channel): void
    {
        DB::transaction(function () use ($user, $channel) {
            $subscription = Subscription::where('user_id', $user->id)
                ->where('cancellation_reason', 'estate_optin')
                ->latest()
                ->first();

            $periodEnd = ChannelSubscription::where('channel_id', $channel->id)
                ->where('status', 'active')
                ->where('current_period_end', '>=', now())
                ->value('current_period_end');

            $newStatus = $periodEnd ? 'active' : 'past_due';

            if ($subscription) {
                $subscription->update([
                    'status'                  => $newStatus,
                    'cancelled_at'            => null,
                    'ends_at'                 => $periodEnd ?? null,
                    'cancellation_reason'     => null,
                    'channel_subscription_id' => null,
                    'current_period_end'      => $periodEnd ?? null,
                ]);
            } else {
                Subscription::create([
                    'user_id'            => $user->id,
                    'client_id'          => $channel->client_id,
                    'status'             => $newStatus,
                    'price'              => BillingService::UNIT_PRICE / 100,
                    'currency'           => 'ZAR',
                    'billing_cycle'      => 'monthly',
                    'current_period_end' => $periodEnd ?? null,
                    'ends_at'            => $periodEnd ?? null,
                ]);
            }

            $user->update([
                'subscription_status' => $newStatus,
            ]);
        });

        Log::info('Household opted out of estate billing', [
            'user_id'    => $user->id,
            'channel_id' => $channel->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Channel Subscription Management
    // -------------------------------------------------------------------------

    /**
     * Calculate the current billing amount for a channel based on opted-in households.
     */
    public function calculateBillingAmount(Channel $channel): array
    {
        $householdCount = $this->getOptedInCount($channel);
        $amountPerHousehold = BillingService::UNIT_PRICE / 100; // R80
        $totalAmount = $householdCount * $amountPerHousehold;

        return [
            'household_count'      => $householdCount,
            'amount_per_household' => $amountPerHousehold,
            'total_amount'         => $totalAmount,
        ];
    }

    /**
     * Get count of households opted into bulk billing for a channel.
     */
    public function getOptedInCount(Channel $channel): int
    {
        return Subscription::where('cancellation_reason', 'estate_optin')
            ->whereHas('channelSubscription', fn($q) => $q->where('channel_id', $channel->id))
            ->whereNotNull('channel_subscription_id')
            ->count();
    }

    /**
     * Resolve or create the active channel subscription for the current billing period.
     */
    public function resolveActiveChannelSubscription(Channel $channel): ChannelSubscription
    {
        $existing = ChannelSubscription::where('channel_id', $channel->id)
            ->whereIn('status', ['pending', 'active'])
            ->latest()
            ->first();

        if ($existing) {
            return $existing;
        }

        $billing = $this->calculateBillingAmount($channel);

        return ChannelSubscription::create([
            'channel_id'           => $channel->id,
            'household_count'      => $billing['household_count'],
            'amount_per_household' => $billing['amount_per_household'],
            'total_amount'         => $billing['total_amount'],
            'status'               => 'pending',
            'billing_model'        => $channel->billing_model,
            'current_period_start' => now(),
            'current_period_end'   => now()->addDays(30),
        ]);
    }

    /**
     * Refresh household count and total on a channel subscription before billing.
     */
    public function refreshChannelSubscription(ChannelSubscription $channelSubscription): void
    {
        $billing = $this->calculateBillingAmount($channelSubscription->channel);

        $channelSubscription->update([
            'household_count' => $billing['household_count'],
            'total_amount'    => $billing['total_amount'],
        ]);
    }

    // -------------------------------------------------------------------------
    // EFT Payment
    // -------------------------------------------------------------------------

    /**
     * Mark an estate EFT payment as paid.
     * Creates payment record, activates all opted-in households,
     * generates estate invoice + per-household invoices, and earnings record.
     */
    public function markEftPaid(
        ChannelSubscription $channelSubscription,
        array $data,
        string $proofPath,
        string $ipAddress
    ): ChannelSubscriptionPayment {
        // Snapshot count and amount at time of payment
        $this->refreshChannelSubscription($channelSubscription);
        $channelSubscription->refresh();

        $merchantReference = 'CEFT-' . strtoupper(uniqid());
        $periodStart       = $channelSubscription->current_period_start ?? now();
        $periodEnd         = $channelSubscription->current_period_end ?? now()->addDays(30);

        $payment = DB::transaction(function () use (
            $channelSubscription, $data, $proofPath, $ipAddress,
            $merchantReference, $periodStart, $periodEnd
        ) {
            // Create payment record
            $payment = ChannelSubscriptionPayment::create([
                'channel_subscription_id' => $channelSubscription->id,
                'amount'                  => $channelSubscription->total_amount,
                'household_count'         => $channelSubscription->household_count,
                'amount_per_household'    => $channelSubscription->amount_per_household,
                'payment_method'          => 'eft',
                'status'                  => 'paid',
                'merchant_reference'      => $merchantReference,
                'proof_of_payment'        => $proofPath,
                'notes'                   => $data['note'] ?? null,
                'ip_address'              => $ipAddress,
                'paid_at'                 => now(),
            ]);

            // Activate channel subscription
            $channelSubscription->update([
                'status'               => 'active',
                'paid_at'              => now(),
                'current_period_start' => $periodStart,
                'current_period_end'   => $periodEnd,
            ]);

            // Activate all opted-in households
            $this->activateOptedInHouseholds($channelSubscription, $periodStart, $periodEnd);

            return $payment;
        });

        // Side effects outside transaction
        $this->handlePaymentSideEffects($payment, $channelSubscription);

        return $payment;
    }

    // -------------------------------------------------------------------------
    // PayFast Pay Now
    // -------------------------------------------------------------------------

    /**
     * Handle a confirmed PayFast Pay Now payment for an estate.
     */
    public function handlePayfastPayment(
        ChannelSubscription $channelSubscription,
        array $payfastData,
        string $ipAddress
    ): ChannelSubscriptionPayment {
        $this->refreshChannelSubscription($channelSubscription);
        $channelSubscription->refresh();

        $periodStart = $channelSubscription->current_period_start ?? now();
        $periodEnd   = $channelSubscription->current_period_end ?? now()->addDays(30);

        // Idempotency guard — PayFast retries ITN delivery
        $alreadyProcessed = ChannelSubscriptionPayment::where(
            'gateway_transaction_id', $payfastData['pf_payment_id'] ?? null
        )->where('status', 'paid')->exists();

        if ($alreadyProcessed) {
            Log::info('Channel PayFast ITN duplicate — skipping', [
                'pf_payment_id'            => $payfastData['pf_payment_id'] ?? null,
                'channel_subscription_id'  => $channelSubscription->id,
            ]);
            return ChannelSubscriptionPayment::where(
                'gateway_transaction_id', $payfastData['pf_payment_id']
            )->first();
        }

        $payment = DB::transaction(function () use (
            $channelSubscription, $payfastData, $ipAddress, $periodStart, $periodEnd
        ) {
            $payment = ChannelSubscriptionPayment::create([
                'channel_subscription_id' => $channelSubscription->id,
                'amount'                  => $payfastData['amount_gross'] ?? $channelSubscription->total_amount,
                'household_count'         => $channelSubscription->household_count,
                'amount_per_household'    => $channelSubscription->amount_per_household,
                'payment_method'          => 'payfast',
                'status'                  => 'paid',
                'merchant_reference'      => $payfastData['m_payment_id'] ?? null,
                'gateway_transaction_id'  => $payfastData['pf_payment_id'] ?? null,
                'gateway_payload'         => json_encode($payfastData),
                'ip_address'              => $ipAddress,
                'paid_at'                 => now(),
            ]);

            $channelSubscription->update([
                'status'               => 'active',
                'paid_at'              => now(),
                'current_period_start' => $periodStart,
                'current_period_end'   => $periodEnd,
            ]);

            $this->activateOptedInHouseholds($channelSubscription, $periodStart, $periodEnd);

            return $payment;
        });

        $this->handlePaymentSideEffects($payment, $channelSubscription);

        return $payment;
    }

    // -------------------------------------------------------------------------
    // Shared Helpers
    // -------------------------------------------------------------------------

    /**
     * Activate all opted-in households under a channel subscription.
     * Updates both subscriptions and users tables.
     */
    private function activateOptedInHouseholds(
        ChannelSubscription $channelSubscription,
        $periodStart,
        $periodEnd
    ): void {
        $subscriptions = Subscription::where('channel_subscription_id', $channelSubscription->id)
            ->where('cancellation_reason', 'estate_optin')
            ->get();

        foreach ($subscriptions as $subscription) {
            $subscription->user?->update([
                'subscription_status' => 'active',
            ]);
        }

        Log::info('Activated opted-in households', [
            'channel_subscription_id' => $channelSubscription->id,
            'count'                   => $subscriptions->count(),
            'period_end'              => $periodEnd,
        ]);
    }

    /**
     * Handle side effects after a confirmed payment:
     * - Estate-level invoice
     * - Per-household invoices
     * - Earnings record for security company
     */
    private function handlePaymentSideEffects(
        ChannelSubscriptionPayment $payment,
        ChannelSubscription $channelSubscription
    ): void {
        try {
            // Estate-level invoice
            Invoice::createFromChannelPayment($payment, $channelSubscription, 'estate_bulk');

            // Per-household invoices
            $subscriptions = Subscription::where('channel_subscription_id', $channelSubscription->id)
                ->where('cancellation_reason', 'estate_optin')
                ->with('user')
                ->get();

            foreach ($subscriptions as $subscription) {
                Invoice::createFromChannelPayment(
                    $payment,
                    $channelSubscription,
                    'estate_household',
                    $subscription
                );
            }

            // Earnings for security company client
            $client = $channelSubscription->channel->client ?? null;
            if ($client) {
                Earning::createFromChannelPayment($payment, $client);
            }

        } catch (\Throwable $e) {
            Log::warning('Channel payment: side effect failed', [
                'channel_subscription_id'         => $channelSubscription->id,
                'channel_subscription_payment_id' => $payment->id,
                'error'                           => $e->getMessage(),
            ]);
        }
    }
}
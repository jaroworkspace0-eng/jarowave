<?php

namespace App\Services;

use App\Mail\EstatePaymentApprovedMail;
use App\Mail\EstatePaymentRejectedMail;
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


    private function cancelPayfastSubscription(string $token): void
    {
        try {
            app(\App\Services\PayFastService::class)->cancelSubscription($token);
        } catch (\Exception $e) {
            Log::error('PayFast subscription cancel failed during estate opt-in', [
                'token' => $token,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Could not cancel PayFast subscription. Opt-in aborted.'
            );
        }
    }


    // 
    public function cancelIndividualSubscriptionForUser(User $user, string $reason = 'cancelled'): ?array
    {
        $subscription = $user->subscription;

        if (!$subscription || !in_array($subscription->status, ['active', 'trialing', 'past_due'])) {
            return null;
        }

        if ($subscription->payfast_token) {
            try {
                $this->cancelPayfastSubscription($subscription->payfast_token);
            } catch (\RuntimeException $e) {
                // Don't block deactivation on a PayFast API failure — log and proceed.
                Log::warning('PayFast cancellation failed during forced deactivation', [
                    'user_id' => $user->id,
                    'reason'  => $e->getMessage(),
                ]);
            }
        }

        $subscription->update([
            'status'              => 'cancelled',
            'cancelled_at'        => now(),
            'cancellation_reason' => $reason,
        ]);

        return ['cancelled' => true, 'subscription_id' => $subscription->id];
    }

    /**
     * Opt a household into estate bulk billing.
     * Cancels their individual subscription and links them to the channel subscription.
     */
    public function optInHousehold(User $user, Channel $channel): void
    {
        DB::transaction(function () use ($user, $channel) {

        // Block opt-in if household has an outstanding past_due subscription.
        // This prevents the exploit of opting out before estate payment then re-opting in for free coverage.
        $pastDue = Subscription::where('user_id', $user->id)
            ->where('status', 'past_due')
            ->exists();

        if ($pastDue) {
            throw new \Exception('Your individual subscription has an outstanding balance of R' . number_format($pastDue->price, 0) .'. Please settle it before opting into estate billing.');
        }

        $channelSubscription = $this->resolveActiveChannelSubscription($channel);


            $channelSubscription = $this->resolveActiveChannelSubscription($channel);

            $subscription = Subscription::where('user_id', $user->id)
                ->whereIn('status', ['active', 'trialing', 'past_due'])
                ->latest()
                ->first();

            if ($subscription) {
                if ($subscription->payfast_token) {
                    $this->cancelPayfastSubscription($subscription->payfast_token);
                }

                $subscription->update([
                    'status'                  => 'cancelled',
                    'cancelled_at'            => now(),
                    'ends_at'                 => $subscription->current_period_end,
                    'cancellation_reason'     => 'estate_optin',
                    'channel_subscription_id' => $channelSubscription?->id,
                ]);
            }

            $user->update([
                'subscription_status' => $channelSubscription?->status === 'active' ? 'active' : 'pending',
            ]);
        });

        Log::info('Household opted into estate billing', [
            'user_id'                 => $user->id,
            'channel_id'              => $channel->id,
        ]);
    }

    /**
     * Opt a household out of estate bulk billing.
     * Restores them to individual billing with a fresh subscription.
     */
    public function optOutHousehold(User $user, Channel $channel, bool $deactivating = false): void
    {
        DB::transaction(function () use ($user, $channel, $deactivating) {
            $channelSubscription = ChannelSubscription::where('channel_id', $channel->id)
                ->where('status', 'active')
                ->where('current_period_end', '>=', now())
                ->first();

            if ($channelSubscription && now()->diffInDays($channelSubscription->current_period_end, false) <= 7) {
                throw new \Exception(
                    'You cannot opt out within 7 days of the estate billing date. Please try again after ' .
                    $channelSubscription->current_period_end->addDay()->format('d M Y') . '.'
                );
            }

            $subscription = Subscription::where('user_id', $user->id)
                ->where('cancellation_reason', 'estate_optin')
                ->latest()
                ->first();

            if (!$subscription) {
                Log::warning('optOutHousehold: no estate_optin subscription found to restore', [
                    'user_id'    => $user->id,
                    'channel_id' => $channel->id,
                ]);
                return;
            }

            $periodEnd = $channelSubscription?->current_period_end;
            $newStatus = $deactivating ? 'cancelled' : 'past_due';

            $subscription->update([
                'status'                  => $newStatus,
                'cancelled_at'            => $deactivating ? now() : null,
                'ends_at'                 => $periodEnd ?? null,
                'cancellation_reason'     => $deactivating ? 'no_coverage_relocation' : null,
                'channel_subscription_id' => null,
                'current_period_end'      => $periodEnd ?? null,
            ]);

            $user->update([
                'subscription_status' => $newStatus,
            ]);
        });

        Log::info('Household opted out of estate billing', [
            'user_id'      => $user->id,
            'channel_id'   => $channel->id,
            'deactivating' => $deactivating,
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
        // $amountPerHousehold = BillingService::UNIT_PRICE / 100; // R80
        $amountPerHousehold = BillingService::unitPrice($channel->amount_per_household);
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

            // Bill to end of current month if created on or before the 20th,
            // otherwise extend to end of next month to align with SA month-end salary cycles.
            'current_period_end' => now()->day <= 20
                ? now()->endOfMonth()
                : now()->addMonthNoOverflow()->endOfMonth(),
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
        $this->refreshChannelSubscription($channelSubscription);
        $channelSubscription->refresh();

        $merchantReference = 'CEFT-' . strtoupper(uniqid());

        $payment = DB::transaction(function () use (
            $channelSubscription, $data, $proofPath, $ipAddress, $merchantReference
        ) {
            $payment = ChannelSubscriptionPayment::create([
                'channel_subscription_id' => $channelSubscription->id,
                'amount'                  => $channelSubscription->total_amount,
                'household_count'         => $channelSubscription->household_count,
                'amount_per_household'    => $channelSubscription->amount_per_household,
                'payment_method'          => 'eft',
                'status'                  => 'pending_review',
                'merchant_reference'      => $merchantReference,
                'proof_of_payment'        => $proofPath,
                'notes'                   => $data['note'] ?? null,
                'ip_address'              => $ipAddress,
                'paid_at'                 => null,
            ]);

            // Keep channel subscription as pending until admin approves
            $channelSubscription->update([
                'status' => 'pending',
            ]);

            return $payment;
        });

        Log::info('Estate EFT submitted — awaiting admin review', [
            'channel_subscription_id' => $channelSubscription->id,
            'payment_id'              => $payment->id,
            'amount'                  => $channelSubscription->total_amount,
        ]);

        return $payment;
    }


    public function approveEftPayment(
    ChannelSubscriptionPayment $payment,
    string $ipAddress
    ): void {
        $channelSubscription = $payment->channelSubscription;

        $this->refreshChannelSubscription($channelSubscription);
        $channelSubscription->refresh();

        $periodStart = $channelSubscription->current_period_start ?? now();
        $periodEnd   = $channelSubscription->current_period_end ?? now()->addDays(30);

        try {
            DB::transaction(function () use ($payment, $channelSubscription, $periodStart, $periodEnd, $ipAddress) {
                $paymentResult = $payment->update([
                    'status'  => 'paid',
                    'paid_at' => now(),
                ]);

                $subResult = $channelSubscription->update([
                    'status'               => 'active',
                    'paid_at'              => now(),
                    'current_period_start' => $periodStart,
                    'current_period_end'   => $periodEnd,
                ]);

                Log::info('Transaction update results', [
                    'payment_result' => $paymentResult,
                    'sub_result'     => $subResult,
                    'sub_id'         => $channelSubscription->id,
                ]);

                $this->activateOptedInHouseholds($channelSubscription, $periodStart, $periodEnd);
            });
        } catch (\Throwable $e) {
            Log::error('Transaction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
        

        $this->handlePaymentSideEffects($payment, $channelSubscription);


        // Email billing contact
        // $channelSubscription = $payment->channelSubscription;
        $channelSubscription->refresh();
        $billingContact = $channelSubscription->channel->billingContact?->user;
        if ($billingContact) {
            Mail::to($billingContact->email)->queue(new EstatePaymentApprovedMail($billingContact, $channelSubscription, $payment));
        }

        Log::info('Estate EFT approved', [
            'payment_id'              => $payment->id,
            'channel_subscription_id' => $channelSubscription->id,
        ]);
    }

    public function rejectEftPayment(
        ChannelSubscriptionPayment $payment,
        string $reason
    ): void {
        $payment->update([
            'status' => 'rejected',
            'notes'  => $payment->notes . ' | Rejected: ' . $reason,
        ]);


        // Email billing contact
        $channelSubscription = $payment->channelSubscription;
        $billingContact = $channelSubscription->channel->billingContact?->user;
        if ($billingContact) {
            Mail::to($billingContact->email)->queue(new EstatePaymentRejectedMail($billingContact, $channelSubscription, $payment, $reason));
        }

        Log::info('Estate EFT rejected', [
            'payment_id' => $payment->id,
            'reason'     => $reason,
        ]);
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

            // If the subscription was already active (e.g. from a previous period), just update the period end
            $subscription->update([
                'status' => 'active',
            ]);

            // Update the user subscription status if they are linked to this subscription
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

            // Earnings for gate guards
            Earning::createGateGuardEarnings($payment);

        } catch (\Throwable $e) {
            Log::warning('Channel payment: side effect failed', [
                'channel_subscription_id'         => $channelSubscription->id,
                'channel_subscription_payment_id' => $payment->id,
                'error'                           => $e->getMessage(),
            ]);
        }
    }
}
<?php

namespace App\Services;

use App\Mail\EstatePaymentApprovedMail;
use App\Mail\EstatePaymentRejectedMail;
use App\Models\AccountLink;
use App\Models\Channel;
use App\Models\ChannelSubscription;
use App\Models\ChannelSubscriptionPayment;
use App\Models\Earning;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

        $balance = Subscription::where('user_id', $user->id)
            ->where('status', 'past_due')
            ->first();

        if ($pastDue) {
            throw new \Exception('Your individual subscription has an outstanding balance of R' . number_format($balance->price, 0) .'. Please settle it before opting into estate billing.');
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

            // Fold any accounts linked under this household into the same
            // channel subscription, so they're covered by estate billing too
            // (picked up automatically by activateOptedInHouseholds()/suspendChannel()).
            $accountLinks = \App\Models\AccountLink::with('linkedAccount.subscription')
                ->where('primary_account_id', $user->id)
                ->where('status', 'active')
                ->get();

            foreach ($accountLinks as $link) {
                $linkedUser = $link->linkedAccount;
                $linkedSub  = $linkedUser?->subscription;

                if (!$linkedUser || !$linkedSub) {
                    Log::warning('optInHousehold: linked account has no subscription', [
                        'primary_user_id' => $user->id,
                        'account_link_id' => $link->id,
                        'linked_user_id'  => $link->linked_account_id,
                    ]);
                    continue;
                }

                if ($linkedSub->payfast_token) {
                    $this->cancelPayfastSubscription($linkedSub->payfast_token);
                }

                $linkedSub->update([
                    'status'                  => 'cancelled',
                    'cancelled_at'            => now(),
                    'ends_at'                 => $linkedSub->current_period_end,
                    'cancellation_reason'     => 'estate_optin',
                    'channel_subscription_id' => $channelSubscription?->id,
                ]);

                $linkedUser->update([
                    'subscription_status' => $channelSubscription?->status === 'active' ? 'active' : 'pending',
                ]);

                // Clear any independent payment-failure suspension this linked account
                // may have had on Node before being folded into estate billing.
                try {
                    Http::timeout(5)
                        ->withHeaders([
                            'Authorization' => 'Bearer ' . env('ASSIGN_SECRET'),
                            'Content-Type'  => 'application/json',
                        ])
                        ->post(rtrim(env('PTT_SERVER_URL'), '/') . '/payment-resolved', [
                            'userId' => $linkedUser->id,
                        ]);
                } catch (\Throwable $e) {
                    Log::warning('optInHousehold: failed to notify Node of linked account payment restoration', [
                        'linked_user_id' => $linkedUser->id,
                        'error'          => $e->getMessage(),
                    ]);
                }
            }
        });


        // Clear any payment failure suspension on Node
        try {
            Http::timeout(5)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . env('ASSIGN_SECRET'),
                    'Content-Type'  => 'application/json',
                ])
                ->post(rtrim(env('PTT_SERVER_URL'), '/') . '/payment-resolved', [
                    'userId' => $user->id,
                ]);
        } catch (\Throwable $e) {
            Log::warning('optInHousehold: failed to notify Node of payment restoration', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }

        Log::info('Household opted into estate billing', [
            'user_id'   => $user->id,
            'channel_id' => $channel->id,
        ]);

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

    public function calculateBillingAmount(Channel $channel): array
    {
        $householdCount = $this->getOptedInCount($channel);
        $amountPerHousehold = BillingService::unitPrice($channel->amount_per_household);
        $householdTotal = $householdCount * $amountPerHousehold;
    
        $linkedAccountCount = $this->getActiveLinkedAccountCount($channel);
        $amountPerLinkedAccount = BillingService::unitPrice($channel->amount_per_linked_account);
        $linkedAccountTotal = $linkedAccountCount * $amountPerLinkedAccount;
    
        return [
            'household_count'           => $householdCount,
            'amount_per_household'      => $amountPerHousehold,
            'linked_account_count'      => $linkedAccountCount,
            'amount_per_linked_account' => $amountPerLinkedAccount,
            'total_amount'              => $householdTotal + $linkedAccountTotal,
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


    // Counts active AccountLinks whose primary is opted into estate
    // billing for this channel — same base query as getOptedInCount(),
    // just joined through to their linked accounts.
    private function getActiveLinkedAccountCount(Channel $channel): int
    {
        $primaryIds = Subscription::where('cancellation_reason', 'estate_optin')
            ->whereHas('channelSubscription', fn ($q) => $q->where('channel_id', $channel->id))
            ->pluck('user_id');
    
        return AccountLink::where('status', 'active')
            ->whereIn('primary_account_id', $primaryIds)
            ->count();
    }

    
    /**
     * Flat length, in days, of a channel subscription's first billing
     * period — from the moment it's created to its first current_period_end.
     * Matches the `?? now()->addDays(30)` fallback already used in
     * approveEftPayment/handlePayfastPayment, so there's one consistent
     * "default period length" concept rather than two different
     * conventions (day-20 month-end alignment here vs. a flat 30 there).
     */
    private const FIRST_BILLING_PERIOD_DAYS = 30;

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

        $new = ChannelSubscription::create([
            'channel_id'           => $channel->id,
            'household_count'      => $billing['household_count'],
            'amount_per_household' => $billing['amount_per_household'],
            'linked_account_count'      => $billing['linked_account_count'],
            'amount_per_linked_account' => $billing['amount_per_linked_account'],
            'total_amount'         => $billing['total_amount'],
            'status'               => 'pending',
            'billing_model'        => $channel->billing_model,
            'current_period_start' => now(),
            'current_period_end'   => now()->addDays(self::FIRST_BILLING_PERIOD_DAYS),
        ]);

        // Carry forward households still legitimately opted in under a
        // superseded (e.g. overdue) cycle onto the new one — they never
        // opted out, so they shouldn't silently vanish from the households
        // list just because the channel rolled to a new billing cycle.
        Subscription::where('cancellation_reason', 'estate_optin')
            ->where('status', 'active')
            ->whereHas('channelSubscription', fn($q) => $q->where('channel_id', $channel->id)->where('id', '!=', $new->id))
            ->update(['channel_subscription_id' => $new->id]);

        return $new;
    }

    /**
     * Refresh household count and total on a channel subscription before billing.
     */
    public function refreshChannelSubscription(ChannelSubscription $channelSubscription): void
    {
        $billing = $this->calculateBillingAmount($channelSubscription->channel);

        $channelSubscription->update([
            'household_count' => $billing['household_count'],
            'linked_account_count'      => $billing['linked_account_count'], 
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
                'linked_account_count'       => $channelSubscription->linked_account_count,
                'amount_per_linked_account'  => $channelSubscription->amount_per_linked_account,
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

        if ($payment->status !== 'pending_review') {
            return; // or throw, depending on how you want the controller to respond
        }
        $channelSubscription = $payment->channelSubscription;

        $this->refreshChannelSubscription($channelSubscription);
        $channelSubscription->refresh();


        $periodStart = ($channelSubscription->current_period_end && $channelSubscription->current_period_end->isPast())
            ? now()
            : ($channelSubscription->current_period_start ?? now());

        $periodEnd = $periodStart->copy()->addDays(30);

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
                'linked_account_count'       => $channelSubscription->linked_account_count,
                'amount_per_linked_account'  => $channelSubscription->amount_per_linked_account,
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
            
            $subscription->update([
                'status'               => 'active',
                'payment_failed_at'    => null,
                'sos_suspended_at'     => null,
                'gateway'              => 'payfast',
                'current_period_start' => $periodStart,
                'current_period_end'   => $periodEnd,
            ]);

            $subscription->syncUserStatus();

            $this->notifyNode('POST', '/payment-resolved', ['userId' => $subscription->user_id]);
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


                $accountLinks = \App\Models\AccountLink::where('primary_account_id', $subscription->user_id)
                ->where('status', 'active')
                ->get();
 
                foreach ($accountLinks as $accountLink) {
                    Invoice::createFromChannelPayment(
                        $payment,
                        $channelSubscription,
                        'estate_linked_account',
                        null,
                        $accountLink
                    );
                }
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


   
    public function syncStandaloneSubscriptionAmount(User $primary): array
    {
        $subscription = $primary->subscription;
        if (
            ! $subscription
            || ! in_array($subscription->status, ['active', 'trialing', 'past_due'])
            || ! $subscription->payfast_token
        ) {
            return ['amount' => null, 'failed' => false];
        }

        $channel = $primary->employee?->channels->first();
        if (! $channel) {
            return ['amount' => null, 'failed' => false];
        }

        $basePrice  = BillingService::unitPrice($channel->amount_per_household);
        $linkedRate = BillingService::unitPrice($channel->amount_per_linked_account);

        $activeLinkedCount = AccountLink::where('primary_account_id', $primary->id)
            ->where('status', 'active')
            ->count();

        $newPrice = $basePrice + ($activeLinkedCount * $linkedRate);

        if ((float) $subscription->price === $newPrice) {
            return ['amount' => $newPrice, 'failed' => false];
        }

        try {
            $updated = app(PayFastService::class)->updateSubscriptionAmount($subscription->payfast_token, $newPrice);
        } catch (\Throwable $e) {
            Log::error('PayFast subscription amount update failed — local price NOT changed', [
                'user_id'         => $primary->id,
                'subscription_id' => $subscription->id,
                'attempted_price' => $newPrice,
                'error'           => $e->getMessage(),
            ]);
            return ['amount' => null, 'failed' => true];
        }

        if (! $updated) {
            Log::error('PayFast subscription amount update rejected — local price NOT changed', [
                'user_id'         => $primary->id,
                'subscription_id' => $subscription->id,
                'attempted_price' => $newPrice,
            ]);
            return ['amount' => null, 'failed' => true];
        }

        $subscription->update(['price' => $newPrice]);

        return ['amount' => $newPrice, 'failed' => false];
    }


     
    public function nextInvoiceFor(User $household): array
    {
        Log::info('[Billing] nextInvoiceFor start', ['user_id' => $household->id]);
    
        $subscription = $household->subscription;
        Log::info('[Billing] subscription loaded', ['found' => (bool) $subscription]);
    
        if (!$subscription) {
            return [
                'amount'       => 0,
                'due_date'     => null,
                'status'       => 'inactive',
                'billing_mode' => 'standalone',
                'breakdown'    => [],
            ];
        }
    
        $isEstateOptedIn = $subscription->cancellation_reason === 'estate_optin'
            && $subscription->channel_subscription_id;
        Log::info('[Billing] estate check', ['is_estate' => $isEstateOptedIn]);
    
        if ($isEstateOptedIn) {
            // NOTE: assumes Subscription::channelSubscription() belongsTo
            // ChannelSubscription exists — add it if it doesn't yet.
            $channelSubscription = $subscription->channelSubscription;
            Log::info('[Billing] estate branch resolved', ['channel_subscription_found' => (bool) $channelSubscription]);
    
            return [
                'amount'       => 0, // covered by the estate, not billed to the household
                'due_date'     => $channelSubscription?->current_period_end,
                'status'       => $household->subscription_status ?? 'active',
                'billing_mode' => 'estate',
                'breakdown'    => [], // nothing to break down — estate pays as a whole
            ];
        }
    
        // Standalone: pull the channel's per-household / per-linked-account
        // rates the same way syncStandaloneSubscriptionAmount() does.
        $channel = $household->employee?->channels->first();
        Log::info('[Billing] channel lookup', ['channel_found' => (bool) $channel]);
    
        $basePrice  = $channel ? BillingService::unitPrice($channel->amount_per_household) : (float) $subscription->price;
        $linkedRate = $channel ? BillingService::unitPrice($channel->amount_per_linked_account) : 0;
        Log::info('[Billing] rates resolved', ['base' => $basePrice, 'linked' => $linkedRate]);
    
        $linkedAccounts = AccountLink::where('primary_account_id', $household->id)
            ->where('status', 'active')
            ->with('linkedAccount')
            ->get();
        Log::info('[Billing] linked accounts loaded', ['count' => $linkedAccounts->count()]);
    
        $breakdown = collect([[
            'id'     => $household->id,
            'name'   => $household->name,
            'role'   => 'primary',
            'amount' => $basePrice,
        ]])->merge(
            $linkedAccounts->map(fn ($link) => [
                'id'     => $link->linkedAccount->id,
                'name'   => $link->linkedAccount->name,
                'role'   => 'linked',
                'amount' => $linkedRate,
            ])
        );
    
        Log::info('[Billing] nextInvoiceFor complete', ['amount' => $basePrice + ($linkedAccounts->count() * $linkedRate)]);
    
        // Use the sum of the breakdown, not the raw stored subscription price —
        // if syncStandaloneSubscriptionAmount() hasn't run/succeeded since the
        // last link change, subscription->price can be stale and would show a
        // total that contradicts its own breakdown.
        $computedAmount = (float) $breakdown->sum('amount');
    
        if ((float) $subscription->price !== $computedAmount) {
            Log::warning('[Billing] subscription price out of sync with breakdown', [
                'user_id'          => $household->id,
                'stored_price'     => $subscription->price,
                'computed_amount'  => $computedAmount,
            ]);
        }
    
        return [
            'amount'       => $computedAmount,
            'due_date'     => $subscription->current_period_end,
            'status'       => $subscription->status, // active | trialing | past_due | cancelled
            'billing_mode' => 'standalone',
            'breakdown'    => $breakdown->values(),
        ];
    }


    private function notifyNode(string $method, string $path, array $payload): void
    {
        try {
            Http::withHeaders(['Authorization' => 'Bearer ' . config('services.ptt.secret')])
                ->{strtolower($method)}(config('services.ptt.url') . $path, $payload);
        } catch (\Exception $e) {
            Log::warning('Failed to notify Node.js', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }
 
 
}
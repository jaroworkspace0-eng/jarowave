<?php

namespace App\Console\Commands;

use App\Models\AccountLink;
use App\Models\Subscription;
use App\Services\BillingService;
use App\Services\PayFastService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileLinkedAccountBilling extends Command
{
    protected $signature = 'account-links:reconcile-billing';

    protected $description = 'Compares standalone subscription prices against expected linked-account totals and PayFast\'s live token amount, self-healing drift.';

    public function handle(PayFastService $payfast): int
    {
        $primaryIds = AccountLink::where('status', 'active')
            ->pluck('primary_account_id')
            ->unique();

        $subscriptions = Subscription::whereIn('user_id', $primaryIds)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->whereNotNull('payfast_token')
            ->with('user.employee.channels')
            ->get();

        $driftCount = 0;

        foreach ($subscriptions as $subscription) {
            $primary = $subscription->user;
            $channel = $primary?->employee?->channels->first();

            if (! $channel) {
                continue;
            }

            $basePrice   = BillingService::unitPrice($channel->amount_per_household);
            $linkedRate  = BillingService::unitPrice($channel->amount_per_linked_account);
            $linkedCount = AccountLink::where('primary_account_id', $primary->id)
                ->where('status', 'active')
                ->count();

            $expectedPrice = $basePrice + ($linkedCount * $linkedRate);

            // 1. DB drift — our own record vs what it should be
            $dbDrift = abs((float) $subscription->price - $expectedPrice) > 0.01;

            // 2. Live drift — what PayFast actually has on the token vs expected
            $liveData        = $payfast->fetchSubscription($subscription->payfast_token);
            $liveAmountRands = $liveData ? ($liveData['amount'] ?? null) / 100 : null;
            $liveDrift       = $liveAmountRands !== null && abs($liveAmountRands - $expectedPrice) > 0.01;

            if (! $dbDrift && ! $liveDrift) {
                continue;
            }

            $driftCount++;

            Log::warning('Billing drift detected on standalone subscription', [
                'user_id'             => $primary->id,
                'subscription_id'     => $subscription->id,
                'payfast_token'       => $subscription->payfast_token,
                'db_price'            => $subscription->price,
                'expected_price'      => $expectedPrice,
                'payfast_live_amount' => $liveAmountRands,
                'linked_count'        => $linkedCount,
            ]);

            if ($dbDrift) {
                $subscription->update(['price' => $expectedPrice]);
            }

            if ($liveDrift) {
                $payfast->updateSubscriptionAmount($subscription->payfast_token, $expectedPrice);
            }
        }

        $this->info("Reconciliation complete. {$driftCount} subscription(s) had drift.");

        return self::SUCCESS;
    }
}
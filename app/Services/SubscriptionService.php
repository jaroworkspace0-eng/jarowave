<?php

namespace App\Services;

use App\Jobs\NotifyPttServerJob;
use App\Models\AccountLink;
use App\Models\EstateMidcycleOptout;
use App\Models\Subscription;
use App\Traits\NotifiesNode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionService
{
    use NotifiesNode;

    public function cancelForUser(int $userId): void
    {
        DB::transaction(function () use ($userId) {
            $subscription = Subscription::where('user_id', $userId)
                ->whereIn('status', ['active', 'trialing', 'past_due'])
                ->latest()
                ->first();

            if ($subscription) {
                if ($subscription->payfast_token) {
                    try {
                        app(\App\Services\PayFastService::class)
                            ->cancelSubscription($subscription->payfast_token);
                    } catch (\Exception $e) {
                        Log::warning('PayFast cancellation failed: ' . $e->getMessage());
                    }
                }

                $accessEnd = $subscription->current_period_end
                    ?? $subscription->trial_ends_at
                    ?? now();

                $subscription->update([
                    'status'       => 'cancelled',
                    'cancelled_at' => now(),
                    'ends_at'      => $accessEnd,
                ]);

                $this->notifyNode('POST', '/subscription-cancelled', [
                    'userId'    => $subscription->user_id,
                    'accessEnd' => $accessEnd->toIso8601String(),
                ]);
            }

            // If this user is a primary with active linked accounts, cut them off too
            $activeLinks = AccountLink::where('primary_account_id', $userId)
                ->where('status', 'active')
                ->with('linkedAccount.subscription')
                ->get();

            foreach ($activeLinks as $link) {
                $linkedUser = $link->linkedAccount;

                if ($linkedSub = $linkedUser->subscription) {
                    if ($linkedSub->channel_subscription_id && $linkedSub->cancellation_reason === 'estate_optin') {
                        $channelSubscription = $linkedSub->channelSubscription;

                        if ($channelSubscription) {
                            $channel = $channelSubscription->channel;

                            EstateMidcycleOptout::create([
                                'user_id'                 => $linkedUser->id,
                                'channel_id'              => $channel->id,
                                'channel_subscription_id' => $channelSubscription->id,
                                'amount_owed'             => BillingService::unitPrice($channel->amount_per_linked_account ?? null),
                                'opted_out_at'            => now(),
                                'billed'                  => false,
                            ]);
                        }
                    }

                    $linkedSub->update([
                        'channel_subscription_id' => null,
                        'cancellation_reason'     => null,
                        'status'                  => 'cancelled',
                        'ends_at'                 => now(),
                    ]);
                }
                $linkedUser->update(['subscription_status' => 'cancelled']);

                NotifyPttServerJob::dispatch('/payment-failed', [
                    'userId'       => $linkedUser->id,
                    'forceSuspend' => true,
                    'reason'       => 'account_unlinked',
                ]);

                $link->update(['status' => 'cancelled']);
            }
        });
    }
}
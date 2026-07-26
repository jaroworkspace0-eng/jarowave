<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Employee;
use App\Services\ChannelBillingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ResyncChannelStandaloneSubscribers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $channelId) {}

    public function handle(ChannelBillingService $billingService): void
    {
        $channel = Channel::find($this->channelId);
        if (! $channel) {
            return;
        }

        $employees = Employee::whereHas('channels', fn ($q) => $q->where('channels.id', $channel->id))
            ->with('user.subscription')
            ->get();

        $failures = [];

        foreach ($employees as $employee) {
            $subscriber   = $employee->user;
            $subscription = $subscriber?->subscription;

            if (
                ! $subscription
                || $subscription->cancellation_reason === 'estate_optin'
                || $subscription->channel_subscription_id
                || ! $subscription->payfast_token
                || ! in_array($subscription->status, ['active', 'trialing', 'past_due'])
            ) {
                continue;
            }

            $sync = $billingService->syncStandaloneSubscriptionAmount($subscriber);

            if ($sync['failed']) {
                $failures[] = $subscriber->id;
            }
        }

        if (! empty($failures)) {
            Log::error('Channel rate update: PayFast resync failed for some standalone subscribers', [
                'channel_id'      => $channel->id,
                'failed_user_ids' => $failures,
            ]);
        }
    }
}
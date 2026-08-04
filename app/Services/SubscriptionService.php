<?php

namespace App\Services;

use App\Models\Subscription;
use App\Traits\NotifiesNode;
use Illuminate\Support\Facades\Log;

class SubscriptionService
{
    use NotifiesNode;

    public function cancelForUser(int $userId): void
    {
        $subscription = Subscription::where('user_id', $userId)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->latest()
            ->first();

        if (!$subscription) {
            return;
        }

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
}
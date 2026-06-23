<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ChannelSubscription;
use App\Models\Subscription;
use App\Mail\EstateBillingOverdueReminderMail;
use App\Mail\EstateSuspendedMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class SuspendNonPayingEstates extends Command
{
    protected $signature   = 'echo:suspend-non-paying-estates';
    protected $description = 'Remind and suspend estate/channel bulk billing subscriptions whose payment has lapsed (7-day grace period)';

    private string $nodeUrl;

    public function __construct()
    {
        parent::__construct();
        $this->nodeUrl = rtrim(env('PTT_SERVER_URL', 'https://radio.server.jaroworkspace.com'), '/');
    }

    public function handle(): void
    {
        // Active channel subscriptions whose period has lapsed — these are the
        // ones in arrears, regardless of whether we've already marked them overdue.
        $lapsed = ChannelSubscription::with('channel.billingContact.user')
            ->whereIn('status', ['active', 'overdue'])
            ->whereNotNull('current_period_end')
            ->whereDate('current_period_end', '<', now()->toDateString())
            ->get();

        foreach ($lapsed as $channelSubscription) {
            $daysOverdue = now()->startOfDay()->diffInDays($channelSubscription->current_period_end->startOfDay());

            // Mark as overdue the first time we see it lapsed (no-op if already set).
            if ($channelSubscription->status !== 'overdue') {
                $channelSubscription->update(['status' => 'overdue']);
            }

            if ($daysOverdue >= 7) {
                $this->suspendChannel($channelSubscription);
            } elseif (in_array($daysOverdue, [3, 7], true)) {
                $this->sendReminder($channelSubscription, $daysOverdue);
            }
        }

        $this->info("Processed {$lapsed->count()} overdue estate(s).");
    }

    /**
     * Send a grace-period reminder to the billing contact, once per day-mark.
     */
    private function sendReminder(ChannelSubscription $channelSubscription, int $daysOverdue): void
    {
        $channel        = $channelSubscription->channel;
        $billingContact = $channel?->billingContact?->user;

        if (!$channel || !$billingContact || !$billingContact->email) {
            return;
        }

        if ($this->alreadySent($channelSubscription->id, "reminder_{$daysOverdue}d")) {
            return;
        }

        Mail::to($billingContact->email)->queue(
            new EstateBillingOverdueReminderMail($billingContact, $channel, $channelSubscription, $daysOverdue)
        );

        $this->info("Estate overdue reminder ({$daysOverdue}d) → {$channel->name} ({$billingContact->email})");
    }

    /**
     * Suspend every opted-in household on this channel, notify the real-time
     * server per household, and email the billing contact once.
     */
    private function suspendChannel(ChannelSubscription $channelSubscription): void
    {
        if ($this->alreadySent($channelSubscription->id, 'suspended')) {
            return;
        }

        $channel        = $channelSubscription->channel;
        $billingContact = $channel?->billingContact?->user;

        $channelSubscription->update(['status' => 'cancelled']);

        $subscriptions = Subscription::with('user')
            ->where('channel_subscription_id', $channelSubscription->id)
            ->where('cancellation_reason', 'estate_optin')
            ->get();

        // $subscriptions = Subscription::with('user')
        //     ->where('channel_subscription_id', $channelSubscription->id)
        //     ->where('cancellation_reason', 'estate_optin')
        //     ->whereNotIn('status', ['active'])
        //     ->get();

        foreach ($subscriptions as $subscription) {
            $user = $subscription->user;
            if (!$user) continue;

            // Align their individual past_due period to month-end
            // so they can self-recover by paying their own subscription.
            $periodEnd = now()->day <= 20
                ? now()->endOfMonth()
                : now()->addMonthNoOverflow()->endOfMonth();

            $subscription->update([
                'status'                  => 'past_due',
                'cancelled_at'            => null,
                'ends_at'                 => $periodEnd,
                'current_period_end'      => $periodEnd,
                'cancellation_reason'     => null,
                'channel_subscription_id' => null,
                'sos_suspended_at'        => now(), // still suspend access until they pay
            ]);

            $user->update([
                'sos_suspended_at'    => now(),
                'subscription_status' => 'past_due',
            ]);

            $this->notifyNode('POST', '/payment-failed', [
                'userId'       => $subscription->user_id,
                'forceSuspend' => true,
                'reason'       => 'estate_payment_failed',
            ]);
        }

        if ($billingContact && $billingContact->email) {
            Mail::to($billingContact->email)->queue(
                new EstateSuspendedMail($billingContact, $channel, $channelSubscription)
            );
        }

        $this->info("Suspended estate: {$channel?->name} ({$subscriptions->count()} households)");
    }

    private function notifyNode(string $method, string $path, array $payload): void
    {
        try {
            Http::timeout(5)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . env('ASSIGN_SECRET'),
                    'Content-Type'  => 'application/json',
                ])
                ->{strtolower($method)}($this->nodeUrl . $path, $payload);
        } catch (\Throwable $e) {
            Log::warning('SuspendNonPayingEstates: Node notify failed', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Dedupe guard. Suspension is a permanent mark (30-day TTL, far longer than
     * any realistic re-run gap) since it should only ever fire once per channel
     * subscription. Reminders use the same long TTL keyed by day-mark, since
     * each day-mark (3d, 7d) should also only ever fire once.
     */
    private function alreadySent(int $channelSubscriptionId, string $type): bool
    {
        $key = "estate_billing:{$channelSubscriptionId}:{$type}";

        if (Cache::has($key)) return true;

        Cache::put($key, true, now()->addDays(30));
        return false;
    }
}
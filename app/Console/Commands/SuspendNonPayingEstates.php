<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ChannelSubscription;
use App\Models\Subscription;
use App\Mail\EstateBillingDueReminderMail;
use App\Mail\EstateBillingOverdueReminderMail;
use App\Mail\EstateSuspendedMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class SuspendNonPayingEstates extends Command
{
    protected $signature   = 'echo:suspend-non-paying-estates';
    protected $description = 'Remind estates ahead of billing, remind and suspend those overdue — including estates that never completed their first payment (7-day grace period)';

    private string $nodeUrl;

    /**
     * Estate grace period, in days, before the channel is suspended.
     * Reminders fire at REMINDER_DAY_MARKS within this window.
     */
    private const GRACE_PERIOD_DAYS = 7;

    /**
     * Days-overdue marks at which an overdue reminder is sent. Must all
     * be strictly less than GRACE_PERIOD_DAYS — suspension itself (with
     * EstateSuspendedMail) is what communicates day GRACE_PERIOD_DAYS,
     * so a reminder mark equal to it would never fire.
     */
    private const REMINDER_DAY_MARKS = [3];

    /**
     * Reminder windows (days BEFORE current_period_end) for a heads-up
     * that billing is coming due, sent before the estate is ever
     * overdue. Mirrors the individual household reminder windows.
     */
    private const PRE_DUE_REMINDER_WINDOWS_DAYS = [7, 3];

    public function __construct()
    {
        parent::__construct();
        $this->nodeUrl = rtrim(env('PTT_SERVER_URL', 'https://radio.server.jaroworkspace.com'), '/');
    }

    public function handle(): void
    {
        foreach (self::PRE_DUE_REMINDER_WINDOWS_DAYS as $days) {
            $this->remindBillingDue($days);
        }

        // Channel subscriptions whose period has lapsed — these are the ones
        // in arrears, regardless of whether we've already marked them
        // overdue. Includes 'pending' (never had a first payment — an
        // estate stuck here with a lapsed current_period_end would
        // otherwise sit unnoticed forever, since nothing else in the
        // system revisits a pending subscription based on time) alongside
        // 'active'/'overdue' renewals.
        $lapsed = ChannelSubscription::with('channel.billingContact.user')
            ->whereIn('status', ['pending', 'active', 'overdue'])
            ->whereNotNull('current_period_end')
            ->whereDate('current_period_end', '<', now()->toDateString())
            ->get();

        foreach ($lapsed as $channelSubscription) {
            $daysOverdue = now()->startOfDay()->diffInDays($channelSubscription->current_period_end->startOfDay());

            // Mark as overdue the first time we see it lapsed (no-op if already set).
            if ($channelSubscription->status !== 'overdue') {
                $channelSubscription->update(['status' => 'overdue']);
            }

            if ($daysOverdue >= self::GRACE_PERIOD_DAYS) {
                $this->suspendChannel($channelSubscription);
            } elseif (in_array($daysOverdue, self::REMINDER_DAY_MARKS, true)) {
                $this->sendReminder($channelSubscription, $daysOverdue);
            }
        }

        $this->info("Processed {$lapsed->count()} overdue estate(s).");
    }

    /**
     * Send a heads-up reminder to the billing contact $days before the
     * channel's current_period_end — before any overdue status kicks in.
     * Includes 'pending' estates (never had a first payment yet) as well
     * as 'active' ones (renewal coming up) — both have a real
     * current_period_end and both deserve a heads-up before it lapses.
     */
    private function remindBillingDue(int $days): void
    {
        $targetDate    = now()->addDays($days)->toDateString();
        $targetDateEnd = now()->addDays($days + 1)->toDateString();

        $dueSoon = ChannelSubscription::with('channel.billingContact.user')
            ->whereIn('status', ['pending', 'active'])
            ->whereNotNull('current_period_end')
            ->whereDate('current_period_end', '>=', $targetDate)
            ->whereDate('current_period_end', '<',  $targetDateEnd)
            ->get();

        foreach ($dueSoon as $channelSubscription) {
            $channel        = $channelSubscription->channel;
            $billingContact = $channel?->billingContact?->user;

            if (!$channel || !$billingContact || !$billingContact->email) {
                continue;
            }

            if ($this->alreadySent($channelSubscription->id, "predue_{$days}d")) {
                continue;
            }

            Mail::to($billingContact->email)->queue(
                new EstateBillingDueReminderMail($billingContact, $channel, $channelSubscription, $days)
            );

            $this->info("Estate pre-due reminder ({$days}d) → {$channel->name} ({$billingContact->email})");
        }
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
     *
     * Also handles estates that never made it past 'pending' (never had a
     * first payment) — in that case $subscriptions is simply empty, since
     * households are only ever linked to a channel subscription once
     * activateOptedInHouseholds runs on first payment. The loop below is a
     * safe no-op for that case; the channel is still cancelled and the
     * billing contact still notified.
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

        foreach ($subscriptions as $subscription) {
            $user = $subscription->user;
            if (!$user) continue;

            // Households opted into the estate's bulk billing — they never
            // had an individual payment relationship, so there's no personal
            // grace period or self-recovery deadline to give them here. They
            // go down with the estate. Continued service is handled through
            // the existing opt-out flow (switching to individual billing),
            // not through this command.
            $subscription->update([
                'status'                  => 'cancelled',
                'cancelled_at'            => now(),
                'ends_at'                 => now(),
                'current_period_end'      => now(),
                'channel_subscription_id' => null,
                'sos_suspended_at'        => now(),
            ]);

            $user->update([
                'sos_suspended_at'    => now(),
                'subscription_status' => 'cancelled',
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
     * each day-mark (currently just 3d) should also only ever fire once.
     */
    private function alreadySent(int $channelSubscriptionId, string $type): bool
    {
        $key = "estate_billing:{$channelSubscriptionId}:{$type}";

        if (Cache::has($key)) return true;

        Cache::put($key, true, now()->addDays(30));
        return false;
    }
}
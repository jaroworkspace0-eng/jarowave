<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Subscription;
use App\Mail\TrialReminderMail;
use App\Mail\BillingReminderMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendPaymentReminders extends Command
{
    protected $signature   = 'echo:send-payment-reminders';
    protected $description = 'Send payment reminder emails and push notifications ahead of trial, billing, or suspension deadlines';

    private string $nodeUrl;

    /**
     * Reminder windows (days before deadline) for trial expiry, upcoming
     * billing, and trial-derived past-due suspension. These all measure
     * against a 7-day-or-longer window, so [7, 3] fits comfortably.
     */
    private const REMINDER_WINDOWS_DAYS = [7, 3];

    /**
     * Reminder windows (days before deadline) specifically for genuine
     * payment-failure past_due suspensions. Their grace window is only
     * PAYMENT_FAILED_GRACE_DAYS (3) long, so [7, 3] doesn't fit — [3, 1]
     * gives an immediate reminder the moment payment fails, plus a final
     * warning 1 day before suspension.
     */
    private const PAYMENT_FAILURE_REMINDER_WINDOWS_DAYS = [3, 1];

    /**
     * NOTE: these two values must match TRIAL_GRACE_DAYS and
     * PAYMENT_FAILED_GRACE_DAYS in SuspendNonPayingHouseholds. They are
     * duplicated here (rather than shared) because the two commands
     * currently don't share a config source. If you change the grace
     * period in one place, change it here too, or reminders will be
     * timed against a deadline that no longer matches actual suspension.
     */
    private const TRIAL_GRACE_DAYS          = 7;
    private const PAYMENT_FAILED_GRACE_DAYS = 3;

    public function __construct()
    {
        parent::__construct();
        $this->nodeUrl = rtrim(env('PTT_SERVER_URL', 'https://radio.server.jaroworkspace.com'), '/');
    }

    public function handle(): void
    {
        foreach (self::REMINDER_WINDOWS_DAYS as $days) {
            $this->remindTrialExpiring($days);
            $this->remindBillingDue($days);
            $this->remindTrialDerivedPastDue($days);
        }

        foreach (self::PAYMENT_FAILURE_REMINDER_WINDOWS_DAYS as $days) {
            $this->remindPaymentFailurePastDue($days);
        }

        $this->info('Payment reminders processed.');
    }

    /**
     * Trialing subscriptions whose trial_ends_at is $days away.
     * trial_ends_at IS the real suspension deadline reference point used
     * by SuspendNonPayingHouseholds (suspension = trial_ends_at + TRIAL_GRACE_DAYS),
     * but the reminder itself is about the TRIAL ending, not suspension,
     * so it correctly measures against trial_ends_at directly.
     */
    private function remindTrialExpiring(int $days): void
    {
        [$targetDate, $targetDateEnd] = $this->windowFor($days);

        $trialExpiring = Subscription::with('user')
            ->where('status', 'trialing')
            ->whereNotNull('trial_ends_at')
            ->whereNull('channel_subscription_id')
            ->whereDate('trial_ends_at', '>=', $targetDate)
            ->whereDate('trial_ends_at', '<',  $targetDateEnd)
            ->get();

        foreach ($trialExpiring as $subscription) {
            $user = $subscription->user;
            if (!$user) continue;
            if ($this->alreadySent($subscription->id, "trial_{$days}d")) continue;

            if ($user->email) {
                Mail::to($user->email)->queue(
                    new TrialReminderMail($user, $subscription, $days)
                );
            }

            $this->notifyNode('POST', '/send-notification', [
                'userId'  => $subscription->user_id,
                'title'   => 'Trial Ending Soon',
                'message' => "Your free trial ends in {$days} " . ($days === 1 ? 'day' : 'days') . '. Add a payment method to keep your access.',
            ]);

            $this->info("Trial reminder ({$days}d) → {$user->email}");
        }
    }

    /**
     * Active subscriptions whose next billing date (current_period_end)
     * is $days away. current_period_end IS the real deadline here, so no
     * adjustment is needed.
     */
    private function remindBillingDue(int $days): void
    {
        [$targetDate, $targetDateEnd] = $this->windowFor($days);

        $billingDue = Subscription::with('user')
            ->where('status', 'active')
            ->whereNotNull('current_period_end')
            ->whereNull('channel_subscription_id')
            ->whereDate('current_period_end', '>=', $targetDate)
            ->whereDate('current_period_end', '<',  $targetDateEnd)
            ->get();

        foreach ($billingDue as $subscription) {
            $user = $subscription->user;
            if (!$user) continue;
            if ($this->alreadySent($subscription->id, "billing_{$days}d")) continue;

            if ($user->email) {
                Mail::to($user->email)->queue(
                    new BillingReminderMail($user, $subscription, $days)
                );
            }

            $this->notifyNode('POST', '/send-notification', [
                'userId'  => $subscription->user_id,
                'title'   => 'Payment Due Soon',
                'message' => "Your payment is due in {$days} " . ($days === 1 ? 'day' : 'days') . ' on ' . $subscription->current_period_end->format('d M Y') . '. Pay now to avoid suspension.',
            ]);

            $this->info("Billing reminder ({$days}d) → {$user->email}");
        }
    }

    /**
     * Past-due subscriptions that got there via trial expiry
     * (trial_ends_at is set). For these, current_period_end IS the real
     * suspension deadline (set to trial_ends_at + TRIAL_GRACE_DAYS by
     * SuspendNonPayingHouseholds), so it can be filtered on directly.
     */
    private function remindTrialDerivedPastDue(int $days): void
    {
        [$targetDate, $targetDateEnd] = $this->windowFor($days);

        $pastDue = Subscription::with('user')
            ->where('status', 'past_due')
            ->whereNotNull('trial_ends_at')
            ->whereNotNull('current_period_end')
            ->whereNull('channel_subscription_id')
            ->whereDate('current_period_end', '>=', $targetDate)
            ->whereDate('current_period_end', '<',  $targetDateEnd)
            ->get();

        foreach ($pastDue as $subscription) {
            $user = $subscription->user;
            if (!$user) continue;
            if ($this->alreadySent($subscription->id, "pastdue_{$days}d")) continue;

            if ($user->email) {
                Mail::to($user->email)->queue(
                    new BillingReminderMail($user, $subscription, $days, failedPayment: true)
                );
            }

            $this->notifyNode('POST', '/send-notification', [
                'userId'  => $subscription->user_id,
                'title'   => 'Payment Overdue',
                'message' => "Your payment is overdue. You have {$days} " . ($days === 1 ? 'day' : 'days') . ' left before your account is suspended. Pay now to keep your access.',
            ]);

            $this->info("Past due reminder ({$days}d) → {$user->email}");
        }
    }

    /**
     * Past-due subscriptions from a genuine billing failure
     * (trial_ends_at is null). The real suspension deadline here is
     * current_period_end + PAYMENT_FAILED_GRACE_DAYS — NOT
     * current_period_end itself — so it's computed per row rather than
     * filtered directly in the query.
     */
    private function remindPaymentFailurePastDue(int $days): void
    {
        [$targetDate, $targetDateEnd] = $this->windowFor($days);

        $pastDue = Subscription::with('user')
            ->where('status', 'past_due')
            ->whereNull('trial_ends_at')
            ->whereNotNull('current_period_end')
            ->whereNull('channel_subscription_id')
            ->get();

        foreach ($pastDue as $subscription) {
            $user = $subscription->user;
            if (!$user) continue;

            $realDeadline = $subscription->current_period_end->copy()->addDays(self::PAYMENT_FAILED_GRACE_DAYS);
            $deadlineDate = $realDeadline->toDateString();

            if ($deadlineDate < $targetDate || $deadlineDate >= $targetDateEnd) continue;
            if ($this->alreadySent($subscription->id, "pastdue_pf_{$days}d")) continue;

            if ($user->email) {
                Mail::to($user->email)->queue(
                    new BillingReminderMail($user, $subscription, $days, failedPayment: true)
                );
            }

            $this->notifyNode('POST', '/send-notification', [
                'userId'  => $subscription->user_id,
                'title'   => 'Payment Overdue',
                'message' => "Your payment is overdue. You have {$days} " . ($days === 1 ? 'day' : 'days') . ' left before your account is suspended. Pay now to keep your access.',
            ]);

            $this->info("Past due (payment failure) reminder ({$days}d) → {$user->email}");
        }
    }

    /**
     * Returns [targetDate, targetDateEnd] — the one-day-wide date window
     * that is exactly $days from now.
     */
    private function windowFor(int $days): array
    {
        return [
            now()->addDays($days)->toDateString(),
            now()->addDays($days + 1)->toDateString(),
        ];
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
            Log::warning('SendPaymentReminders: Node notify failed', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function alreadySent(int $subscriptionId, string $type): bool
    {
        $key = "reminder:{$subscriptionId}:{$type}";

        if (Cache::has($key)) return true;

        // 2-day TTL ensures reminders don't fire twice within the same window
        // but resets cleanly for the next billing cycle.
        Cache::put($key, true, now()->addDays(2));
        return false;
    }
}
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Subscription;
use App\Mail\TrialReminderMail;
use App\Mail\BillingReminderMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;

class SendPaymentReminders extends Command
{
    protected $signature   = 'echo:send-payment-reminders';
    protected $description = 'Send payment reminder emails at 7 and 3 days before trial or billing expiry';

    public function handle(): void
    {
        $windows = [7, 3];

        foreach ($windows as $days) {
            $targetDate    = now()->addDays($days)->toDateString();
            $targetDateEnd = now()->addDays($days + 1)->toDateString();

            // ── Trialing — trial ending soon ─────────────────────────────
            $trialExpiring = Subscription::with('user')
                ->where('status', 'trialing')
                ->whereNotNull('trial_ends_at')
                ->whereDate('trial_ends_at', '>=', $targetDate)
                ->whereDate('trial_ends_at', '<',  $targetDateEnd)
                ->get();

            foreach ($trialExpiring as $subscription) {
                $user = $subscription->user;
                if (!$user || !$user->email) continue;
                if ($this->alreadySent($subscription->id, "trial_{$days}d")) continue;

                Mail::to($user->email)->queue(
                    new TrialReminderMail($user, $subscription, $days)
                );
                $this->info("Trial reminder ({$days}d) → {$user->email}");
            }

            // ── Active — next billing date coming up ─────────────────────
            $billingDue = Subscription::with('user')
                ->where('status', 'active')
                ->whereNotNull('current_period_end')
                ->whereDate('current_period_end', '>=', $targetDate)
                ->whereDate('current_period_end', '<',  $targetDateEnd)
                ->get();

            foreach ($billingDue as $subscription) {
                $user = $subscription->user;
                if (!$user || !$user->email) continue;
                if ($this->alreadySent($subscription->id, "billing_{$days}d")) continue;

                Mail::to($user->email)->queue(
                    new BillingReminderMail($user, $subscription, $days)
                );
                $this->info("Billing reminder ({$days}d) → {$user->email}");
            }

            // ── Past due — payment failed X days ago ─────────────────────
            $pastDue = Subscription::with('user')
                ->where('status', 'past_due')
                ->whereNotNull('current_period_end')
                ->whereDate('current_period_end', '>=', now()->subDays($days + 1)->toDateString())
                ->whereDate('current_period_end', '<',  now()->subDays($days)->toDateString())
                ->get();

            foreach ($pastDue as $subscription) {
                $user = $subscription->user;
                if (!$user || !$user->email) continue;
                if ($this->alreadySent($subscription->id, "pastdue_{$days}d")) continue;

                Mail::to($user->email)->queue(
                    new BillingReminderMail($user, $subscription, $days, failedPayment: true)
                );
                $this->info("Failed payment reminder ({$days}d) → {$user->email}");
            }
        }
    }

    private function alreadySent(int $subscriptionId, string $type): bool
    {
        $key = "reminder:{$subscriptionId}:{$type}";

        if (Cache::has($key)) return true;

        Cache::put($key, true, now()->addDay());
        return false;
    }
}
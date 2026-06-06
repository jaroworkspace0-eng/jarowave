<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Subscription;
use App\Mail\TrialReminderMail;
use App\Mail\BillingReminderMail;
use Illuminate\Support\Facades\Mail;

class SendPaymentReminders extends Command
{
    protected $signature   = 'echo:send-payment-reminders';
    protected $description = 'Send payment reminder emails at 7 and 3 days before trial or billing expiry';

    public function handle(): void
    {
        $windows = [7, 3];

        foreach ($windows as $days) {
            $targetDate = now()->addDays($days)->toDateString();

            // ── Trialing — trial ending soon ─────────────────────────────
            $trialExpiring = Subscription::with('user')
                ->where('status', 'trialing')
                ->whereNotNull('trial_ends_at')
                ->whereDate('trial_ends_at', $targetDate)
                ->get();

            foreach ($trialExpiring as $subscription) {
                $user = $subscription->user;
                if (!$user || !$user->email) continue;

                Mail::to($user->email)->queue(
                    new TrialReminderMail($user, $subscription, $days)
                );
                $this->info("Trial reminder ({$days}d) → {$user->email}");
            }

            // ── Active — next billing date coming up ──────────────────────
            $billingDue = Subscription::with('user')
                ->where('status', 'active')
                ->whereNotNull('current_period_end')
                ->whereDate('current_period_end', $targetDate)
                ->get();

            foreach ($billingDue as $subscription) {
                $user = $subscription->user;
                if (!$user || !$user->email) continue;

                Mail::to($user->email)->queue(
                    new BillingReminderMail($user, $subscription, $days)
                );
                $this->info("Billing reminder ({$days}d) → {$user->email}");
            }

            // ── Past due — payment failed, update details ─────────────────
            $pastDue = Subscription::with('user')
                ->where('status', 'past_due')
                ->whereNotNull('current_period_end')
                ->whereDate('current_period_end', $targetDate)
                ->get();

            foreach ($pastDue as $subscription) {
                $user = $subscription->user;
                if (!$user || !$user->email) continue;

                Mail::to($user->email)->queue(
                    new BillingReminderMail($user, $subscription, $days, failedPayment: true)
                );
                $this->info("Failed payment reminder ({$days}d) → {$user->email}");
            }
        }
    }
}
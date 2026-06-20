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
    protected $description = 'Send payment reminder emails and push notifications at 7 and 3 days before trial or billing expiry';

    private string $nodeUrl;

    public function __construct()
    {
        parent::__construct();
        $this->nodeUrl = rtrim(env('PTT_SERVER_URL', 'https://radio.server.jaroworkspace.com'), '/');
    }

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

            // ── Active — next billing date coming up ─────────────────────
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

            // ── Past due — payment overdue, remind before hard suspend ────
            $pastDue = Subscription::with('user')
                ->where('status', 'past_due')
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

        $this->info('Payment reminders processed.');
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
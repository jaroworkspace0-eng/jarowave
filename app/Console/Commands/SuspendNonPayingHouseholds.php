<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Subscription;
use App\Mail\HouseholdSuspendedMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SuspendNonPayingHouseholds extends Command
{
    protected $signature   = 'echo:suspend-non-paying';
    protected $description = 'Suspend households whose trial or billing period has lapsed';

    private string $nodeUrl;

    public function __construct()
    {
        parent::__construct();
        $this->nodeUrl = rtrim(env('PTT_SERVER_URL', 'https://radio.server.jaroworkspace.com'), '/');
    }

    public function handle(): void
    {
        $expiredTrials = Subscription::with('user')
            ->where('status', 'trialing')
            ->whereNull('sos_suspended_at')
            ->whereNotNull('trial_ends_at')
            ->whereNull('channel_subscription_id')
            ->whereDate('trial_ends_at', '<', now()->toDateString())
            ->get();

        $failedPayments = Subscription::with('user')
            ->where('status', 'past_due')
            ->whereNull('sos_suspended_at')
            ->whereNotNull('current_period_end')
            ->whereNull('channel_subscription_id')
            ->whereDate('current_period_end', '<', now()->subDays(3)->toDateString())
            ->get();

        $targets = $expiredTrials->merge($failedPayments);

        foreach ($targets as $subscription) {
            $user = $subscription->user;
            if (!$user) continue;

            $isExpiredTrial = $expiredTrials->contains($subscription);

            if ($isExpiredTrial) {
                // Transition to past_due with month-end aligned billing period
                // giving them until month end to pay before suspension kicks in.
                $trialEnd = $subscription->trial_ends_at;
                $periodEnd = $trialEnd->day <= 20
                    ? $trialEnd->copy()->endOfMonth()
                    : $trialEnd->copy()->addMonthNoOverflow()->endOfMonth();

                $subscription->update([
                    'status'             => 'past_due',
                    'current_period_end' => $periodEnd,
                ]);

                $user->update(['subscription_status' => 'past_due']);

                // Notify node of trial expiry (soft suspend — limited access)
                $this->notifyNode('POST', '/payment-failed', [
                    'userId'       => $subscription->user_id,
                    'forceSuspend' => false,
                    'reason'       => 'trial_expired',
                    'gracePeriodEndsAt' => $periodEnd->timestamp * 1000, // Laravel's real deadline
                ]);

                continue;
            }

            // past_due and current_period_end has lapsed — hard suspend
            $subscription->update([
                'status'           => 'cancelled',
                'cancelled_at'     => now(),
                'ends_at'          => now(),
                'sos_suspended_at' => now(),
            ]);

            $user->update([
            'sos_suspended_at'    => now(),
            'subscription_status' => 'cancelled',
        ]);

            $this->notifyNode('POST', '/payment-failed', [
                'userId'       => $subscription->user_id,
                'forceSuspend' => true,
                'reason'       => 'payment_failed',
            ]);

            if ($user->email) {
                Mail::to($user->email)->queue(new HouseholdSuspendedMail($user, $subscription));
                $this->info("Suspended (payment_failed) & emailed: {$user->email}");
            }
        }

        $this->info("Total suspended: {$targets->count()}");
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
            Log::warning('SuspendNonPayingHouseholds: Node notify failed', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
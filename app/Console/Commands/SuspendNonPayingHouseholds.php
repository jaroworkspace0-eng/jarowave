<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Subscription;
use App\Mail\HouseholdSuspendedMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;

class SuspendNonPayingHouseholds extends Command
{
    protected $signature   = 'echo:suspend-non-paying';
    protected $description = 'Suspend households whose trial or billing period has lapsed';

    public function handle(): void
    {
        // 1. Trial expired — never converted to active
        $expiredTrials = Subscription::with('user')
            ->where('status', 'trialing')
            ->whereNotNull('trial_ends_at')
            ->whereDate('trial_ends_at', '<', now()->toDateString())
            ->get();

        // 2. Active but payment failed — past_due with current_period_end lapsed
        $failedPayments = Subscription::with('user')
            ->where('status', 'past_due')
            ->whereNotNull('current_period_end')
            ->whereDate('current_period_end', '<', now()->subDays(3)->toDateString()) // 3-day grace
            ->get();

        $targets = $expiredTrials->merge($failedPayments);

        foreach ($targets as $subscription) {
            $user = $subscription->user;
            if (!$user) continue;

            // Suspend
            $subscription->update([
                'status'          => 'cancelled',
                'sos_suspended_at' => now(),
            ]);

            $user->update(['sos_suspended_at' => now()]);

            // Notify Node.js — takes effect immediately if user is online
            $this->notifyNode($subscription->user_id);

            // Email
            if ($user->email) {
                Mail::to($user->email)->queue(
                    new HouseholdSuspendedMail($user, $subscription)
                );
                $this->info("Suspended & emailed: {$user->email}");
            }
        }

        $this->info("Total suspended: {$targets->count()}");
    }

    private function notifyNode(int $userId): void
    {
        try {
            Http::post(config('services.node.url') . '/payment-failed', [
                'userId'       => $userId,
                'forceSuspend' => true,
                'reason'       => 'Trial or billing period lapsed — auto suspended',
            ]);
        } catch (\Throwable $e) {
            $this->warn("Node notify failed for user {$userId}: " . $e->getMessage());
        }
    }
}
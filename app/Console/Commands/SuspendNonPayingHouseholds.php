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

    /**
     * Flat grace period after a trial ends before hard suspension.
     * Independent of PAYMENT_FAILED_GRACE_DAYS — does not stack with it.
     */
    private const TRIAL_GRACE_DAYS = 7;

    /**
     * Flat grace period after a recurring billing payment fails before
     * hard suspension. Independent of TRIAL_GRACE_DAYS — does not stack
     * with it. Shorter, since these are already-paying households just
     * needing a quick card/payment fix, not a fresh buying decision.
     */
    private const PAYMENT_FAILED_GRACE_DAYS = 3;

    public function __construct()
    {
        parent::__construct();
        $this->nodeUrl = rtrim(env('PTT_SERVER_URL', 'https://radio.server.jaroworkspace.com'), '/');
    }

   
    public function handle(): void
    {
        // Group 1: trials that just expired — not yet past_due.
        $expiredTrials = Subscription::with('user')
            ->where('status', 'trialing')
            ->whereNull('sos_suspended_at')
            ->whereNotNull('trial_ends_at')
            ->whereNull('channel_subscription_id')
            ->whereDate('trial_ends_at', '<', now()->toDateString())
            ->get();

        // Group 2: already past_due BECAUSE a trial expired (trial_ends_at
        // is set), whose flat TRIAL_GRACE_DAYS window has now lapsed.
        $trialGraceLapsed = Subscription::with('user')
            ->where('status', 'past_due')
            ->whereNull('sos_suspended_at')
            ->whereNotNull('trial_ends_at')
            ->whereNull('channel_subscription_id')
            ->whereDate('trial_ends_at', '<', now()->subDays(self::TRIAL_GRACE_DAYS)->toDateString())
            ->get();

        // Group 3: already past_due from a genuine recurring billing
        // failure (no trial involved), whose flat PAYMENT_FAILED_GRACE_DAYS
        // window has now lapsed.
        $paymentFailureLapsed = Subscription::with('user')
            ->where('status', 'past_due')
            ->whereNull('sos_suspended_at')
            ->whereNull('trial_ends_at')
            ->whereNotNull('current_period_end')
            ->whereNull('channel_subscription_id')
            ->whereDate('current_period_end', '<', now()->subDays(self::PAYMENT_FAILED_GRACE_DAYS)->toDateString())
            ->get();

        $toTransition = $expiredTrials;
        $toSuspend    = $trialGraceLapsed->merge($paymentFailureLapsed);

        // Eager-load each user's active AccountLink so isLinkedAccount()
        // doesn't fire a query per row inside the loop below.
        $toSuspend->load('user.accountLinkAsLinked');

        $transitionedCount = 0;
        $suspendedCount    = 0;

        // --- Trials that just expired: soft transition to past_due ---
        foreach ($toTransition as $subscription) {
            $user = $subscription->user;
            if (!$user) continue;

            // Deadline is purely informational here — TRIAL_GRACE_DAYS is
            // enforced by the trialGraceLapsed query above, not by this date.
            $trialEnd = $subscription->trial_ends_at;
            $deadline = $trialEnd->copy()->addDays(self::TRIAL_GRACE_DAYS);

            $subscription->update([
                'status'             => 'past_due',
                'current_period_end' => $deadline,
            ]);

            $user->update(['subscription_status' => 'past_due']);

            $this->notifyNode('POST', '/payment-failed', [
                'userId'            => $subscription->user_id,
                'forceSuspend'      => false,
                'reason'            => 'trial_expired',
                'gracePeriodEndsAt' => $deadline->timestamp * 1000,
            ]);

            $transitionedCount++;
        }

        // --- Grace period lapsed (either reason): hard suspend ---
        foreach ($toSuspend as $subscription) {
            $user = $subscription->user;
            if (!$user) continue;

            $reason = $subscription->trial_ends_at ? 'trial_expired' : 'payment_failed';

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
                'reason'       => $reason,
            ]);

            // Linked accounts don't own billing — the primary account's
            // failed payment caused this, so only the primary gets emailed.
            if ($user->email && !$user->isLinkedAccount()) {
                Mail::to($user->email)->queue(new HouseholdSuspendedMail($user, $subscription));
                $this->info("Suspended ({$reason}) & emailed: {$user->email}");
            } else {
                $this->info("Suspended ({$reason}), no email (linked account): {$user->id}");
            }

            $suspendedCount++;
        }

        $this->info("Transitioned to past_due: {$transitionedCount}");
        $this->info("Hard suspended: {$suspendedCount}");
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
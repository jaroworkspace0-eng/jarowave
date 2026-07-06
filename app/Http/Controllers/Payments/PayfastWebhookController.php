<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Earning;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\PayFastService;
use App\Mail\PaymentFailedMail;
use App\Mail\PaymentSuccessMail;
use App\Mail\SubscriptionCancelledMail;
use App\Mail\TrialCardFailedMail;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PayfastWebhookController extends Controller
{
    /**
     * Handle a PayFast ITN (Instant Transaction Notification) webhook.
     *
     * Validation order: IP → signature → ITN → merchant ref → subscription lookup
     * COMPLETE flow:    idempotency guard → DB transaction (subscription + payment) → side effects
     * Side effects (earning, invoice, email, Node notify) run outside the transaction intentionally —
     * a billing side-effect failure should never roll back a confirmed payment record.
     */
    public function handle(Request $request, PayFastService $payfast)
    {
        $data = $request->all();
        Log::info('PayFast ITN received', $data);

        // --- 1. IP validation ---
        $ip = $request->ip();
        if (app()->environment('production') && !$payfast->isValidIp($ip)) {
            Log::warning('PayFast ITN from invalid IP', ['ip' => $ip]);
            return response('Invalid IP', 403);
        }

        Log::debug('PayFast ITN raw field order: ' . implode(', ', array_keys($data)));

        // --- 2. Signature verification ---
        if (!$payfast->verifySignature($data)) {
            Log::warning('PayFast ITN signature mismatch', $data);
            return response('Invalid signature', 400);
        }

        // --- 3. ITN validation (production only — requires outbound HTTP to PayFast) ---
        if (app()->environment('production') && !$payfast->verifyItn($data)) {
            Log::warning('PayFast ITN validation failed', $data);
            return response('ITN validation failed', 400);
        }

        // --- 4. Resolve subscription ---
        $merchantRef = $data['m_payment_id'] ?? null;
        if (!$merchantRef) {
            Log::warning('PayFast ITN missing m_payment_id');
            return response('Missing reference', 400);
        }

        $subscription = Subscription::where('merchant_reference', $merchantRef)->first();
        if (!$subscription) {
            Log::warning('PayFast ITN subscription not found', ['m_payment_id' => $merchantRef]);
            return response('Subscription not found', 404);
        }

        $paymentStatus = $data['payment_status'] ?? '';
        $tokenId       = $data['token'] ?? null;
        $user          = $subscription->user;

        switch ($paymentStatus) {

            // -------------------------------------------------------------------------
            case 'COMPLETE':
            // -------------------------------------------------------------------------

                // Always persist the token regardless of trial/real payment
                $subscription->update([
                    'payfast_token'  => $tokenId,
                    'gateway_status' => 'COMPLETE',
                ]);

                // Trial setup (R0.00 tokenisation ping) — save token, nothing else
                $isTrialSetup = $subscription->trial_ends_at
                    && $subscription->trial_ends_at->isFuture()
                    && (float)($data['amount_gross'] ?? 0) === 0.0;

                if ($isTrialSetup) {
                    Log::info('PayFast trial setup complete, token saved', [
                        'subscription_id' => $subscription->id,
                        'token'           => $tokenId,
                    ]);
                    break;
                }

                // --- Idempotency guard ---
                // PayFast retries ITN delivery on non-200 responses. Without this,
                // a retry creates duplicate payment records, earnings, and invoices.
                $alreadyProcessed = $subscription->payments()
                    ->where('gateway_transaction_id', $data['pf_payment_id'] ?? null)
                    ->where('status', 'complete')
                    ->exists();

                if ($alreadyProcessed) {
                    Log::info('PayFast ITN duplicate — already processed, skipping', [
                        'pf_payment_id'   => $data['pf_payment_id'] ?? null,
                        'subscription_id' => $subscription->id,
                    ]);
                    break; // Still returns 200 below — tell PayFast we received it
                }

                // Compute period end once so the DB record and the email always agree
                $periodStart = $subscription->current_period_end ?? now();
                $periodEnd   = $periodStart->copy()->addDays(30);

                // --- Core writes in a transaction ---
                // Subscription update + payment create are atomic. A crash between
                // them would otherwise leave an active subscription with no payment
                // record, or vice versa.
                $payment = DB::transaction(function () use (
                    $subscription, $data, $request, $periodStart, $periodEnd
                ) {
                    $subscription->update([
                        'status'               => 'active',
                        'payment_failed_at'    => null,
                        'current_period_start' => $periodStart,
                        'current_period_end'   => $periodEnd,
                    ]);

                    return $subscription->payments()->create(
                        $this->buildPaymentData($data, 'complete', $request) + [
                            'paid_at'              => now(),
                            'billing_period_start' => $periodStart,
                            'billing_period_end'   => $periodEnd,
                        ]
                    );
                });

                // --- Side effects (outside transaction) ---
                try {
                    if ($subscription->client) {
                        Earning::createFromPayment($payment, $subscription->client);
                    }

                    $invoice = Invoice::createFromPayment($payment);
                    $invoice->load('payment.subscription', 'client');

                    Mail::to($user->email)->queue(new PaymentSuccessMail(
                        userName:  $user->name,
                        amount:    $data['amount_gross'] ?? null,
                        // Use the same $periodEnd we stored — not a second now()->addDays(30)
                        periodEnd: $periodEnd->format('d M Y'),
                        invoice:   $invoice,
                    ));

                    // Mark activation fee paid on first successful payment
                    if (!$subscription->activation_fee_paid) {
                        $subscription->update([
                            'activation_fee_paid'    => true,
                            'activation_fee_paid_at' => now(),
                            // NOTE: Sets price to system default. Revisit if custom pricing is added.
                            // 'price'                  => BillingService::UNIT_PRICE / 100,
                            'price'                     => BillingService::unitPrice($subscription->user->employee?->channels->first()?->amount_per_household),
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('PayFast COMPLETE: side effect failed', [
                        'subscription_id' => $subscription->id,
                        'payment_id'      => $payment->id,
                        'error'           => $e->getMessage(),
                    ]);
                }

                Log::info('PayFast subscription activated', [
                    'subscription_id' => $subscription->id,
                    'token'           => $tokenId,
                ]);

                // --- Notify Node.js ---
                $this->notifyNode('POST', '/payment-resolved', ['userId' => $user->id]);

                break;

            case 'FAILED':

                $subscription->payments()->create(
                    $this->buildPaymentData($data, 'failed', $request) + [
                        'failure_reason' => 'Payment failed',
                    ]
                );

                $stillInTrial = $subscription->trial_ends_at && $subscription->trial_ends_at->isFuture();

                if ($stillInTrial) {
                    // Card/tokenisation failed while still in trial — don't touch status
                    // or threaten suspension; trial access continues regardless.
                    Mail::to($user->email)->queue(
                        new TrialCardFailedMail($user->name, $subscription->trial_ends_at)
                    );

                    Log::warning('PayFast payment failed during trial — card verification notice sent', [
                        'subscription_id' => $subscription->id,
                    ]);
                    break;
                }

                $subscription->update([
                    'status'            => 'past_due',
                    'payment_failed_at' => now(),
                    'gateway_status'    => 'FAILED',
                ]);

                $graceEndsAt = now()->addDays(3);

                Mail::to($user->email)->queue(new PaymentFailedMail(
                    userName:    $user->name,
                    amount:      $data['amount_gross'] ?? null,
                    reason:      'Payment failed',
                    graceEndsAt: $graceEndsAt,
                ));

                Log::warning('PayFast payment failed', ['subscription_id' => $subscription->id]);

                $this->notifyNode('POST', '/payment-failed', [
                    'userId'            => $user->id,
                    'userEmail'         => $user->email,
                    'userName'          => $user->name,
                    'amount'            => $data['amount_gross'] ?? null,
                    'reason'            => 'Payment failed',
                    'trialEndsAt'       => $subscription->trial_ends_at?->toISOString(),
                    'forceSuspend'      => false,
                    'gracePeriodEndsAt' => $graceEndsAt->timestamp * 1000,
                ]);

                break;

            // -------------------------------------------------------------------------
            case 'CANCELLED':
            // -------------------------------------------------------------------------

                $subscription->update([
                    'status'         => 'cancelled',
                    'cancelled_at'   => now(),
                    'ends_at'        => $subscription->current_period_end,
                    'gateway_status' => 'CANCELLED',
                ]);

                $subscription->payments()->create(
                    $this->buildPaymentData($data, 'cancelled', $request) + [
                        'failure_reason' => 'Subscription cancelled',
                    ]
                );

                Mail::to($user->email)->queue(new SubscriptionCancelledMail(
                    userName:  $user->name,
                    accessEnd: $subscription->current_period_end?->format('d M Y'),
                ));

                Log::info('PayFast subscription cancelled', ['subscription_id' => $subscription->id]);

                $this->notifyNode('POST', '/subscription-cancelled', [
                    'userId'    => $user->id,
                    'accessEnd' => $subscription->current_period_end?->toIso8601String(),
                ]);

                break;

            // -------------------------------------------------------------------------
            default:
            // -------------------------------------------------------------------------

                Log::info('PayFast ITN unhandled status', [
                    'status'          => $paymentStatus,
                    'subscription_id' => $subscription->id,
                ]);
        }

        return response('OK', 200);
    }

    /**
     * Build the shared payment record fields common to all three PayFast statuses.
     * Each case merges its own status-specific fields on top of this.
     */
    private function buildPaymentData(array $data, string $status, Request $request): array
    {
        return [
            'gateway'                   => 'payfast',
            'gateway_transaction_id'    => $data['pf_payment_id']  ?? null,
            'gateway_payment_reference' => $data['m_payment_id']   ?? null,
            'gateway_status'            => strtoupper($status),
            'merchant_reference'        => $data['m_payment_id']   ?? null,
            'amount'                    => $data['amount_gross']    ?? 0,
            'amount_gross'              => $data['amount_gross']    ?? null,
            'amount_fee'                => $data['amount_fee']      ?? null,
            'amount_net'                => $data['amount_net']      ?? null,
            'currency'                  => $data['currency_code']   ?? 'ZAR',
            'payment_method'            => $data['payment_method']  ?? null,
            'payer_name'                => trim(($data['name_first'] ?? '') . ' ' . ($data['name_last'] ?? '')) ?: null,
            'payer_email'               => $data['email_address']   ?? null,
            'status'                    => strtolower($status),
            'gateway_payload'           => json_encode($data),
            'signature'                 => $data['signature']       ?? null,
            'ip_address'                => $request->ip(),
        ];
    }

    /**
     * Notify the Node.js PTT service. Failures are logged but never thrown —
     * a Node notify failure should not affect the billing outcome.
     */
    private function notifyNode(string $method, string $path, array $payload): void
    {
        try {
            Http::withHeaders(['Authorization' => 'Bearer ' . config('services.ptt.secret')])
                ->{strtolower($method)}(config('services.ptt.url') . $path, $payload);
        } catch (\Exception $e) {
            Log::warning('Failed to notify Node.js', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
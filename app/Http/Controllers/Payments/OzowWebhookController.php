<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Earning;
use App\Models\Invoice;
use App\Models\SubscriptionPayment;
use App\Mail\PaymentFailedMail;
use App\Mail\PaymentSuccessMail;
use App\Mail\SubscriptionCancelledMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OzowWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->all();

        Log::info('Ozow webhook received', $payload);

        // ── 1. Verify hash ────────────────────────────────────────────────────
        if (!$this->verifyHash($payload)) {
            Log::warning('Ozow webhook: invalid hash', $payload);
            return response('Invalid hash', 400);
        }

        // ── 2. Find pending payment by gateway reference ──────────────────────
        $payment = SubscriptionPayment::where('gateway_payment_reference', $payload['TransactionReference'] ?? null)
            ->where('gateway', 'ozow')
            ->first();

        if (!$payment) {
            Log::warning('Ozow webhook: payment not found', $payload);
            return response('OK', 200);
        }

        // ── 3. Store full raw payload ─────────────────────────────────────────
        $payment->recordPayload($payload);

        // ── 4. Handle status ──────────────────────────────────────────────────
        $gatewayStatus = $payload['Status'] ?? null;
        $subscription  = $payment->subscription;
        $user          = $subscription->user;

        switch ($gatewayStatus) {

            case 'Complete':
                $payment->markPaid(
                    gatewayTransactionId: $payload['TransactionId'],
                    payload: $payload,
                );

                $payment->update([
                    'gateway_status' => $gatewayStatus,
                    'payer_name'     => $payload['CustomerName']  ?? null,
                    'payer_email'    => $payload['CustomerEmail'] ?? null,
                    'ip_address'     => $request->ip(),
                ]);

                $this->activateSubscription($payment);

                // Queue success email
                Mail::to($user->email)->queue(new PaymentSuccessMail(
                    userName:  $user->name,
                    amount:    $payload['Amount'] ?? null,
                    periodEnd: $payment->billing_period_end?->format('d M Y'),
                ));

                Log::info('Ozow payment complete', [
                    'payment_id'      => $payment->id,
                    'subscription_id' => $subscription->id,
                    'transaction_id'  => $payload['TransactionId'] ?? null,
                ]);

                // Notify Node.js — re-enable SOS instantly
                try {
                    Http::withHeaders(['Authorization' => 'Bearer ' . env('ASSIGN_SECRET')])
                        ->post(env('PTT_SERVER_URL') . '/payment-resolved', ['userId' => $user->id]);
                } catch (\Exception $e) {
                    Log::warning('Failed to notify Node.js of Ozow payment resolution: ' . $e->getMessage());
                }

                break;

            case 'Cancelled':
            case 'Error':
            case 'PendingInvestigation':
                $payment->markFailed(
                    reason: "Ozow status: {$gatewayStatus}",
                    payload: $payload,
                );

                $payment->update([
                    'gateway_status' => $gatewayStatus,
                    'payer_name'     => $payload['CustomerName']  ?? null,
                    'payer_email'    => $payload['CustomerEmail'] ?? null,
                    'ip_address'     => $request->ip(),
                ]);

                $subscription->update([
                    'status'         => $gatewayStatus === 'Cancelled' ? 'cancelled' : 'past_due',
                    'gateway_status' => strtoupper($gatewayStatus),
                ]);

                // Queue appropriate email based on status
                if ($gatewayStatus === 'Cancelled') {
                    Mail::to($user->email)->queue(new SubscriptionCancelledMail(
                        userName:  $user->name,
                        accessEnd: $subscription->current_period_end?->format('d M Y'),
                    ));
                } else {
                    Mail::to($user->email)->queue(new PaymentFailedMail(
                        userName: $user->name,
                        amount:   $payload['Amount'] ?? null,
                        reason:   "Ozow: {$gatewayStatus}",
                    ));
                }

                Log::warning('Ozow payment failed', [
                    'payment_id'      => $payment->id,
                    'subscription_id' => $subscription->id,
                    'status'          => $gatewayStatus,
                ]);

                // Notify Node.js — push + socket (trial guard applies)
                if (!$subscription->trial_ends_at || $subscription->trial_ends_at->isPast()) {
                    try {
                        Http::withHeaders(['Authorization' => 'Bearer ' . env('ASSIGN_SECRET')])
                            ->post(env('PTT_SERVER_URL') . '/payment-failed', [
                                'userId'      => $user->id,
                                'userEmail'   => $user->email,
                                'userName'    => $user->name,
                                'amount'      => $payload['Amount'] ?? null,
                                'reason'      => "Ozow: {$gatewayStatus}",
                                'trialEndsAt' => $subscription->trial_ends_at?->toISOString(),
                            ]);
                    } catch (\Exception $e) {
                        Log::warning('Failed to notify Node.js of Ozow payment failure: ' . $e->getMessage());
                    }
                }

                break;

            default:
                Log::info('Ozow webhook: unhandled status', [
                    'status'     => $gatewayStatus,
                    'payment_id' => $payment->id,
                ]);
        }

        return response('OK', 200);
    }

    private function activateSubscription(SubscriptionPayment $payment): void
    {
        $subscription = $payment->subscription;

        $subscription->update([
            'status'               => 'active',
            'current_period_start' => $payment->billing_period_start,
            'current_period_end'   => $payment->billing_period_end,
        ]);

        Earning::createFromPayment($payment, $subscription->client);
        Invoice::createFromPayment($payment);
    }

    private function verifyHash(array $payload): bool
    {
        $siteCode   = env('OZOW_SITE_CODE', '');
        $privateKey = env('OZOW_PRIVATE_KEY', '');

        $hashInput = strtolower(
            ($siteCode)                               .
            ($payload['CountryCode']          ?? '')  .
            ($payload['CurrencyCode']         ?? '')  .
            ($payload['Amount']               ?? '')  .
            ($payload['TransactionId']        ?? '')  .
            ($payload['TransactionReference'] ?? '')  .
            ($payload['BankRef']              ?? '')  .
            ($payload['Status']               ?? '')  .
            ($payload['Optional1']            ?? '')  .
            ($payload['Optional2']            ?? '')  .
            ($payload['Optional3']            ?? '')  .
            ($payload['Optional4']            ?? '')  .
            ($payload['Optional5']            ?? '')  .
            ($payload['CancelledUrl']         ?? '')  .
            ($payload['ErrorUrl']             ?? '')  .
            ($payload['SuccessUrl']           ?? '')  .
            ($payload['IsTest']               ?? '')  .
            strtolower($privateKey)
        );

        $computedHash = strtolower(hash('sha512', $hashInput));
        $receivedHash = strtolower($payload['Hash'] ?? '');

        return hash_equals($computedHash, $receivedHash);
    }
}

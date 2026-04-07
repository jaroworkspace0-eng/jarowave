<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Earning;
use App\Models\Invoice;
use App\Models\SubscriptionPayment;
use App\Mail\PaymentSuccessMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OzowRecoveryWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->all();
        Log::info('Ozow Recovery webhook received', $payload);

        // ── Verify hash ───────────────────────────────────────────────────────
        if (!$this->verifyHash($payload)) {
            Log::warning('Ozow Recovery webhook: invalid hash');
            return response('Invalid hash', 400);
        }

        // ── Confirm this is a recovery payment ────────────────────────────────
        if (($payload['Optional3'] ?? '') !== 'recovery') {
            Log::warning('Ozow Recovery webhook: not a recovery payment');
            return response('OK', 200);
        }

        // ── Find the pending recovery payment ─────────────────────────────────
        $paymentId = $payload['Optional2'] ?? null;
        $payment   = SubscriptionPayment::find($paymentId);

        if (!$payment) {
            Log::warning('Ozow Recovery webhook: payment not found', ['payment_id' => $paymentId]);
            return response('OK', 200);
        }

        $payment->recordPayload($payload);

        $subscription  = $payment->subscription;
        $user          = $subscription->user;
        $gatewayStatus = $payload['Status'] ?? null;

        if ($gatewayStatus === 'Complete') {

            $payment->markPaid(
                gatewayTransactionId: $payload['TransactionId'],
                payload: $payload,
            );

            $payment->update([
                'gateway'        => 'ozow',
                'gateway_status' => $gatewayStatus,
                'payer_name'     => $payload['CustomerName']  ?? null,
                'payer_email'    => $payload['CustomerEmail'] ?? null,
                'ip_address'     => $request->ip(),
            ]);

            // Reactivate subscription
            $subscription->update([
                'status'               => 'active',
                'gateway_status'       => 'COMPLETE',
                'current_period_start' => now(),
                'current_period_end'   => now()->addDays(30),
            ]);

            Earning::createFromPayment($payment, $subscription->client);
            Invoice::createFromPayment($payment);

            // Queue success email
            Mail::to($user->email)->queue(new PaymentSuccessMail(
                userName:  $user->name,
                amount:    $payload['Amount'] ?? null,
                periodEnd: now()->addDays(30)->format('d M Y'),
            ));

            Log::info('Ozow recovery payment complete', [
                'payment_id'      => $payment->id,
                'subscription_id' => $subscription->id,
                'user_id'         => $user->id,
            ]);

            // Notify Node.js — re-enable SOS instantly
            try {
                Http::withHeaders(['Authorization' => 'Bearer ' . env('ASSIGN_SECRET')])
                    ->post(env('PTT_SERVER_URL') . '/payment-resolved', ['userId' => $user->id]);
            } catch (\Exception $e) {
                Log::warning('Failed to notify Node.js of Ozow recovery resolution: ' . $e->getMessage());
            }

        } else {
            $payment->markFailed(
                reason: "Ozow recovery status: {$gatewayStatus}",
                payload: $payload,
            );

            $payment->update([
                'gateway'        => 'ozow',
                'gateway_status' => $gatewayStatus,
                'ip_address'     => $request->ip(),
            ]);

            Log::warning('Ozow recovery payment failed', [
                'payment_id' => $payment->id,
                'status'     => $gatewayStatus,
            ]);
        }

        return response('OK', 200);
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

        return hash_equals(
            strtolower(hash('sha512', $hashInput)),
            strtolower($payload['Hash'] ?? '')
        );
    }
}
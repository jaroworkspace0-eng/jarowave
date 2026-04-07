<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Mail\PaymentFailedMail;
use App\Models\Client;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    // POST /api/payments/initiate
    public function initiate(Request $request)
    {
        $validated = $request->validate([
            'gateway' => ['required', 'in:payfast,ozow'],
        ]);

        $user   = $request->user();
        $client = Client::where('user_id', $user->id)->firstOrFail();

        $subscription = Subscription::where('client_id', $client->id)
            ->whereIn('status', ['trialing', 'active', 'past_due'])
            ->latest()
            ->firstOrFail();

        // Create a pending payment row before redirecting
        $reference = 'ECL-' . strtoupper(Str::random(10));

        $payment = SubscriptionPayment::create([
            'subscription_id'           => $subscription->id,
            'gateway'                   => $validated['gateway'],
            'gateway_payment_reference' => $reference,
            'amount'                    => $subscription->price,
            'currency'                  => 'ZAR',
            'status'                    => 'pending',
            'billing_period_start'      => now(),
            'billing_period_end'        => $subscription->billing_cycle === 'annual'
                                            ? now()->addYear()
                                            : now()->addMonth(),
        ]);

        // Build redirect URL based on gateway
        $redirectUrl = $validated['gateway'] === 'payfast'
            ? $this->buildPayfastRedirect($payment, $subscription, $user)
            : $this->buildOzowRedirect($payment, $subscription, $user);

        return response()->json([
            'redirect_url' => $redirectUrl,
            'reference'    => $reference,
        ]);
    }

    // ── PayFast ──────────────────────────────────────────────

    private function buildPayfastRedirect(
        SubscriptionPayment $payment,
        Subscription $subscription,
        $user
    ): string {
        $amountInRands = number_format($payment->amount / 100, 2, '.', '');

        $data = [
            'merchant_id'   => env('PAYFAST_MERCHANT_ID'),
            'merchant_key'  => env('PAYFAST_MERCHANT_KEY'),
            'return_url'    => url('/payment/thank-you?status=success&ref=' . $payment->gateway_payment_reference),
            'cancel_url'    => url('/payment/thank-you?status=cancelled&ref=' . $payment->gateway_payment_reference),
            'notify_url'    => url('/api/webhooks/payfast'),
            'name_first'    => explode(' ', $user->name)[0],
            'name_last'     => explode(' ', $user->name)[1] ?? '',
            'email_address' => $user->email,
            'm_payment_id'  => $payment->gateway_payment_reference,
            'amount'        => $amountInRands,
            'item_name'     => 'Echo Link — ' . ucfirst($subscription->plan) . ' Plan',
            'item_description' => ucfirst($subscription->billing_cycle) . ' subscription',
        ];

        // Add passphrase if set
        if (env('PAYFAST_PASSPHRASE')) {
            $data['passphrase'] = env('PAYFAST_PASSPHRASE');
        }

        // Generate signature
        $data['signature'] = $this->generatePayfastSignature($data);

        return env('PAYFAST_URL') . '?' . http_build_query($data);
    }

    private function generatePayfastSignature(array $data): string
    {
        // Remove signature if already in array
        unset($data['signature']);

        $pfOutput = '';
        foreach ($data as $key => $val) {
            if ($val !== '') {
                $pfOutput .= $key . '=' . urlencode(trim($val)) . '&';
            }
        }

        return md5(rtrim($pfOutput, '&'));
    }

    // ── Ozow ─────────────────────────────────────────────────

    private function buildOzowRedirect(
        SubscriptionPayment $payment,
        Subscription $subscription,
        $user
    ): string {
        $amountInRands = number_format($payment->amount / 100, 2, '.', '');
        $siteCode      = env('OZOW_SITE_CODE');
        $privateKey    = env('OZOW_PRIVATE_KEY');

        $data = [
            'SiteCode'             => $siteCode,
            'CountryCode'          => 'ZA',
            'CurrencyCode'         => 'ZAR',
            'Amount'               => $amountInRands,
            'TransactionReference' => $payment->gateway_payment_reference,
            'BankReference'        => 'Echo Link',
            'Customer'             => $user->email,
            'CancelUrl'            => url('/payment/thank-you?status=cancelled&ref=' . $payment->gateway_payment_reference),
            'ErrorUrl'             => url('/payment/thank-you?status=error&ref=' . $payment->gateway_payment_reference),
            'SuccessUrl'           => url('/payment/thank-you?status=success&ref=' . $payment->gateway_payment_reference),
            'NotifyUrl'            => url('/api/webhooks/ozow'),
            'IsTest'               => env('OZOW_SANDBOX', true) ? 'true' : 'false',
        ];

        // Generate hash
        $data['HashCheck'] = $this->generateOzowHash($data, $privateKey);

        return env('OZOW_URL') . '?' . http_build_query($data);
    }

    private function generateOzowHash(array $data, string $privateKey): string
    {
        $hashString =
            strtolower($data['SiteCode']) .
            strtolower($data['CountryCode']) .
            strtolower($data['CurrencyCode']) .
            strtolower($data['Amount']) .
            strtolower($data['TransactionReference']) .
            strtolower($data['BankReference']) .
            strtolower($data['IsTest']) .
            strtolower($privateKey);

        return hash('sha512', $hashString);
    }


    // ── Payment Failure Notification (called by payment gateways) ──────────────────────────────
    public function notifyPaymentFailed(Request $request)
    {
        $request->validate([
            'user_id'    => 'required',
            'user_email' => 'required|email',
            'user_name'  => 'required|string',
        ]);

        try {
            Mail::to($request->user_email)->send(new PaymentFailedMail(
                $request->user_name,
                $request->amount,
                $request->reason,
            ));
            Log::info("Payment failure email sent to {$request->user_email}");
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error("Payment failure email failed: " . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }


    // ── PayFast Webhook Handler ──────────────────────────────────────────────
    public function handlePayfastWebhook(Request $request)
    {
        $data = $request->all();
        Log::info('PayFast webhook received', $data);

        // Verify PayFast signature (important for security)
        // See: https://developers.payfast.co.za/docs#step_4_confirm_payment
        $paymentStatus = $data['payment_status'] ?? '';
        $userId        = $data['custom_str1']     ?? null; // store userId in custom_str1 when creating payment

        if (!$userId) {
            Log::warning('PayFast webhook: no user_id in custom_str1');
            return response('OK', 200);
        }

        $user = User::find($userId);
        if (!$user) {
            Log::warning("PayFast webhook: user {$userId} not found");
            return response('OK', 200);
        }

        // Guard: skip if still within trial period
        $subscription = $user->subscription; // or however you access it
        if ($subscription && $subscription->trial_ends_at && $subscription->trial_ends_at->isFuture()) {
            Log::info("PayFast webhook: userId={$userId} still on trial until {$subscription->trial_ends_at} — skipping payment failure");
            return response('OK', 200);
        }

        if (in_array($paymentStatus, ['FAILED', 'CANCELLED'])) {
            // Update subscription status in DB
            $user->subscription_status    = 'payment_failed';
            $user->payment_failed_at      = now();
            $user->sos_suspended_at       = null; // will be set after grace period
            $user->save();

            // Tell Node.js server — it handles push + socket
            Http::withHeaders([
                'Authorization' => 'Bearer ' . env('ASSIGN_SECRET'),
            ])->post(env('PTT_SERVER_URL') . '/payment-failed', [
                'userId'    => $userId,
                'userEmail' => $user->email,
                'userName'  => $user->name,
                'amount'    => $data['amount_gross'] ?? null,
                'reason'    => $paymentStatus,
                'trialEndsAt'  => $subscription->trial_ends_at?->toISOString(),
            ]);

            Log::info("Payment failed for userId={$userId}");
        }

        if ($paymentStatus === 'COMPLETE') {
            // Payment resolved — clear the block
            $user->subscription_status = 'active';
            $user->payment_failed_at   = null;
            $user->sos_suspended_at    = null;
            $user->save();

            Http::withHeaders([
                'Authorization' => 'Bearer ' . env('ASSIGN_SECRET'),
            ])->post(env('PTT_SERVER_URL') . '/payment-resolved', [
                'userId' => $userId,
            ]);

            Log::info("Payment resolved for userId={$userId}");
        }

        return response('OK', 200);
    }


    // ── Ozow Webhook Handler ───────────────────────────────────────────────
    public function handleOzowWebhook(Request $request)
    {
        $data = $request->all();
        Log::info('Ozow webhook received', $data);

        // ── Verify Ozow hash ─────────────────────────────────────────
        // Ozow signs requests with a hash — verify before processing
        $hashCheck = strtolower(hash('sha512',
            env('OZOW_SITE_CODE') .
            ($data['CountryCode']      ?? '') .
            ($data['CurrencyCode']     ?? '') .
            ($data['Amount']           ?? '') .
            ($data['TransactionId']    ?? '') .
            ($data['Reference']        ?? '') .
            ($data['BankRef']          ?? '') .
            ($data['Status']           ?? '') .
            ($data['Optional1']        ?? '') .
            ($data['Optional2']        ?? '') .
            ($data['Optional3']        ?? '') .
            ($data['Optional4']        ?? '') .
            ($data['Optional5']        ?? '') .
            ($data['CancelledUrl']     ?? '') .
            ($data['ErrorUrl']         ?? '') .
            ($data['SuccessUrl']       ?? '') .
            ($data['IsTest']           ?? '') .
            strtolower(env('OZOW_PRIVATE_KEY'))
        ));

        if ($hashCheck !== strtolower($data['Hash'] ?? '')) {
            Log::warning('Ozow webhook: invalid hash — rejected');
            return response('Invalid hash', 400);
        }

        // ── Extract user ─────────────────────────────────────────────
        // Store userId in the Optional1 field when creating the Ozow payment
        $userId = $data['Optional1'] ?? null;
        $status = $data['Status']    ?? '';
        $amount = $data['Amount']    ?? null;

        if (!$userId) {
            Log::warning('Ozow webhook: no userId in Optional1');
            return response('OK', 200);
        }

        $user = User::find($userId);
        if (!$user) {
            Log::warning("Ozow webhook: user {$userId} not found");
            return response('OK', 200);
        }

        // ── Trial guard ───────────────────────────────────────────────
        $subscription = $user->subscription;
        if ($subscription && $subscription->trial_ends_at && $subscription->trial_ends_at->isFuture()) {
            Log::info("Ozow webhook: userId={$userId} still on trial — skipping");
            return response('OK', 200);
        }

        // ── Handle status ─────────────────────────────────────────────
        // Ozow statuses: Complete, Cancelled, Error, PendingInvestigation
        if (in_array($status, ['Cancelled', 'Error', 'PendingInvestigation'])) {
            $user->subscription_status = 'payment_failed';
            $user->payment_failed_at   = now();
            $user->sos_suspended_at    = null;
            $user->save();

            Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.ptt.secret'),
            ])->post(config('services.socket.url') . '/payment-failed', [
                'userId'      => $userId,
                'userEmail'   => $user->email,
                'userName'    => $user->name,
                'amount'      => $amount,
                'reason'      => $status,
                'trialEndsAt' => $subscription?->trial_ends_at?->toISOString(),
            ]);

            Log::info("Ozow payment failed for userId={$userId} status={$status}");
        }

        if ($status === 'Complete') {
            $user->subscription_status = 'active';
            $user->payment_failed_at   = null;
            $user->sos_suspended_at    = null;
            $user->save();

            Http::withHeaders([
                'Authorization' => 'Bearer ' . env('ASSIGN_SECRET'),
            ])->post(env('PTT_SERVER_URL') . '/payment-resolved', [
                'userId' => $userId,
            ]);

            Log::info("Ozow payment resolved for userId={$userId}");
        }

        return response('OK', 200);
    }
}
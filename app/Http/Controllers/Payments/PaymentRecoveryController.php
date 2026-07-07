<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Mail\SubscriptionCancelledMail;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PaymentRecoveryController extends Controller
{
    // ── Generate a once-off recovery payment link ─────────────────────────────
    // Called by the app when user taps "Pay Now"
    // Returns both a PayFast and Ozow link so the frontend can offer both
    public function generateLink(Request $request)
    {
        $request->validate(['user_id' => 'required']);

        $subscription = Subscription::where('user_id', $request->user_id)
            ->whereIn('status', ['past_due', 'cancelled'])
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json(['error' => 'No outstanding subscription found'], 404);
        }

        $user            = $subscription->user;
        $amount          = 80.00; // R80 fixed monthly fee
        $merchantRef     = 'RECOVERY-' . $subscription->id . '-' . now()->timestamp;
        $transactionRef  = Str::uuid()->toString();

        // Record a pending recovery payment
        $payment = $subscription->payments()->create([
            'gateway'                   => 'payfast', // updated when user picks gateway
            'gateway_payment_reference' => $transactionRef,
            'merchant_reference'        => $merchantRef,
            'amount'                    => $amount,
            'amount_gross'              => $amount,
            'currency'                  => 'ZAR',
            'status'                    => 'pending',
            'failure_reason'            => null,
            'billing_period_start'      => now(),
            'billing_period_end'        => now()->addDays(30),
        ]);

        return response()->json([
            'amount'          => $amount,
            'merchant_ref'    => $merchantRef,
            'transaction_ref' => $transactionRef,
            'payment_id'      => $payment->id,
            'payfast_url'     => $this->buildPayFastUrl($user, $amount, $merchantRef, $payment->id),
            'ozow_url'        => $this->buildOzowUrl($user, $amount, $transactionRef, $payment->id),
        ]);
    }

    // ── PayFast once-off payment URL ──────────────────────────────────────────
    private function buildPayFastUrl($user, float $amount, string $merchantRef, int $paymentId): string
    {
        $data = [
            'merchant_id'   => config('payfast.merchant_id'),
            'merchant_key'  => config('payfast.merchant_key'),
            'return_url' => config('payfast.return_url') . '?payment_id=' . $paymentId,
            'cancel_url' => config('payfast.cancel_url') . '?payment_id=' . $paymentId,
            'notify_url' => config('payfast.recovery_notify_url'),
            'name_first'    => explode(' ', $user->name)[0] ?? $user->name,
            'name_last'     => explode(' ', $user->name)[1] ?? '',
            'email_address' => $user->email,
            'm_payment_id'  => $merchantRef,
            'amount'        => number_format($amount, 2, '.', ''),
            'item_name'     => 'Echo Link Monthly Subscription Recovery',
            'custom_str1'   => (string) $user->id,
            'custom_str2'   => (string) $paymentId,
            'custom_str3'   => 'recovery',
        ];

        $signatureString = collect($data)
            ->map(fn($v, $k) => $k . '=' . urlencode(trim((string) $v)))
            ->implode('&');

        if ($passphrase = config('payfast.passphrase')) {
            $signatureString .= '&passphrase=' . urlencode(trim($passphrase));
        }

        $data['signature'] = md5($signatureString);

        $baseUrl = app()->environment('production')
            ? 'https://www.payfast.co.za/eng/process'
            : 'https://sandbox.payfast.co.za/eng/process';

        return $baseUrl . '?' . http_build_query($data);
    }

    // ── Ozow once-off payment URL ─────────────────────────────────────────────
    private function buildOzowUrl($user, float $amount, string $transactionRef, int $paymentId): string
    {
        $siteCode    = env('OZOW_SITE_CODE');
        $privateKey  = env('OZOW_PRIVATE_KEY');
        $countryCode = 'ZA';
        $currencyCode= 'ZAR';
        $isTest      = app()->environment('production') ? 'false' : 'true';

        $cancelUrl  = config('app.url') . '/billing/recovery/cancelled?payment_id=' . $paymentId;
        $errorUrl   = config('app.url') . '/billing/recovery/error?payment_id=' . $paymentId;
        $successUrl = config('app.url') . '/billing/recovery/success?payment_id=' . $paymentId;
        $notifyUrl  = config('app.url') . '/api/webhooks/ozow/recovery';

        $hashInput = strtolower(
            $siteCode . $countryCode . $currencyCode .
            number_format($amount, 2, '.', '') .
            $transactionRef . '' . '' .      // BankRef optional
            $cancelUrl . $errorUrl . $successUrl . $isTest .
            strtolower($privateKey)
        );

        $hash = hash('sha512', $hashInput);

        $params = [
            'SiteCode'            => $siteCode,
            'CountryCode'         => $countryCode,
            'CurrencyCode'        => $currencyCode,
            'Amount'              => number_format($amount, 2, '.', ''),
            'TransactionReference'=> $transactionRef,
            'BankRef'             => '',
            'CancelUrl'           => $cancelUrl,
            'ErrorUrl'            => $errorUrl,
            'SuccessUrl'          => $successUrl,
            'NotifyUrl'           => $notifyUrl,
            'IsTest'              => $isTest,
            'HashCheck'           => $hash,
            'Optional1'           => (string) $user->id,
            'Optional2'           => (string) $paymentId,
            'Optional3'           => 'recovery',
        ];

        return 'https://pay.ozow.com/?' . http_build_query($params);
    }

    public function getUpdateUrl(Request $request)
    {
        $user = User::find($request->user_id);
        if (!$user || !in_array(strtolower($user->role), ['household', 'resident'])) {
            return response()->json(['error' => 'Only household members have subscriptions'], 403);
        }

        $request->validate(['user_id' => 'required']);

        $subscription = Subscription::where('user_id', $request->user_id)
            ->whereNotNull('payfast_token')
            ->latest()
            ->first();

        if (!$subscription?->payfast_token) {
            return response()->json(['error' => 'No active debit mandate found'], 404);
        }

        $env = app()->environment('production') ? 'www' : 'sandbox';

        return response()->json([
            'url' => "https://{$env}.payfast.co.za/eng/recurring/update/{$subscription->payfast_token}",
        ]);
    }


    public function cancelSubscription(Request $request)
    {
        $request->validate(['user_id' => 'required']);

        $subscription = Subscription::where('user_id', $request->user_id)
            ->where('status', 'active')
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json(['error' => 'No active subscription found'], 404);
        }

        // Cancel with PayFast if debit order
        if ($subscription->payfast_token) {
            try {
                $payfastService = app(\App\Services\PayFastService::class);
                $payfastService->cancelSubscription($subscription->payfast_token);
            } catch (\Exception $e) {
                Log::warning('PayFast cancellation failed: ' . $e->getMessage());
            }
        }

        $subscription->update([
            'status'         => 'cancelled',
            'ends_at'        => $subscription->current_period_end,
            'gateway_status' => 'CANCELLED',
        ]);

        $user = $subscription->user;

        // Queue cancellation email
        Mail::to($user->email)->queue(new SubscriptionCancelledMail(
            userName:  $user->name,
            accessEnd: $subscription->current_period_end?->format('d M Y'),
        ));

        // Notify Node.js — start grace period countdown from period end
        try {
            Http::withHeaders(['Authorization' => 'Bearer ' . env('ASSIGN_SECRET')])
                ->post(env('PTT_SERVER_URL') . '/subscription-cancelled', [
                    'userId'    => $user->id,
                    'accessEnd' => $subscription->current_period_end?->toISOString(),
                ]);
        } catch (\Exception $e) {
            Log::warning('Failed to notify Node.js of cancellation: ' . $e->getMessage());
        }

        return response()->json([
            'success'    => true,
            'access_end' => $subscription->current_period_end?->format('d M Y'),
            'message'    => 'Subscription cancelled. You have access until ' . $subscription->current_period_end?->format('d M Y') . '.',
        ]);
    }


    // In PaymentRecoveryController

    public function activeFailures(Request $request)
    {
        if ($request->header('X-PTT-Secret') !== env('ASSIGN_SECRET')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $failures = Subscription::whereIn('status', ['past_due', 'cancelled'])
            ->whereHas('user', fn($q) => $q->whereIn('role', ['household', 'resident']))
            ->with('user')
            ->get()
            ->map(function ($sub) {
                if ($sub->status === 'cancelled') {
                    // Already suspended — deadline must read as past, not recomputed.
                    $graceEndsAt = $sub->sos_suspended_at
                        ? \Carbon\Carbon::parse($sub->sos_suspended_at)
                        : now()->subMinute();
                } else {
                    // past_due — still within (or approaching) grace.
                    $isTrialDerived = $sub->trial_ends_at !== null;

                    $graceEndsAt = $isTrialDerived
                        ? $sub->current_period_end
                        : ($sub->payment_failed_at
                            ? \Carbon\Carbon::parse($sub->payment_failed_at)->addDays(3)
                            : now()->addDays(3));
                }

                return [
                    'user_id'           => $sub->user_id,
                    'payment_failed_at' => $sub->payment_failed_at ?? $sub->updated_at,
                    'grace_period_ends' => $graceEndsAt->toISOString(),
                    'sos_suspended_at'  => $sub->sos_suspended_at,
                ];
            });

        return response()->json($failures);
    }
    // public function activeFailures(Request $request)
    // {
    //     // Internal only — verify PTT secret
    //     if ($request->header('X-PTT-Secret') !== env('ASSIGN_SECRET')) {
    //         return response()->json(['error' => 'Unauthorized'], 401);
    //     }

    //     $failures = Subscription::whereIn('status', ['past_due', 'cancelled'])
    //         ->whereHas('user', fn($q) => $q->whereIn('role', ['household', 'resident']))
    //         ->with('user')
    //         ->get()
    //         ->map(fn($sub) => [
    //             'user_id'          => $sub->user_id,
    //             'payment_failed_at'=> $sub->payment_failed_at ?? $sub->updated_at,
    //             'grace_period_ends'=> $sub->payment_failed_at
    //                 ? \Carbon\Carbon::parse($sub->payment_failed_at)->addHours(24)->toISOString()
    //                 : now()->toISOString(),
    //             'sos_suspended_at' => $sub->sos_suspended_at,
    //         ]);

    //     return response()->json($failures);
    // }
}
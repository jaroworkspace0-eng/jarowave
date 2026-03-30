<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Http\Request;
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
}
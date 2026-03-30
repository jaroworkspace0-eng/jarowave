<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Mail\InvoiceMail;
use App\Models\Earning;
use App\Models\Invoice;
use App\Models\SubscriptionPayment;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PayfastWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->all();

        Log::info('PayFast ITN received', $payload);

        // 1. Find the pending payment by your reference you sent to PayFast
        $payment = SubscriptionPayment::where('gateway_payment_reference', $payload['m_payment_id'])
            ->where('gateway', 'payfast')
            ->first();

        if (!$payment) {
            Log::warning('PayFast ITN: payment not found', $payload);
            return response('OK', 200); // always return 200 to PayFast
        }

        // 2. Store the full raw payload regardless of outcome
        $payment->recordPayload($payload);

        // 3. Handle based on payment_status from PayFast
        $gatewayStatus = $payload['payment_status'] ?? null;

        if ($gatewayStatus === 'COMPLETE') {
            $payment->markPaid(
                gatewayTransactionId: $payload['pf_payment_id'],
                payload: $payload
            );

            // Update payer info PayFast sends back
            $payment->update([
                'gateway_status'  => $gatewayStatus,
                'amount_gross'    => (int) round(($payload['amount_gross'] ?? 0) * 100),
                'amount_fee'      => (int) round(($payload['amount_fee'] ?? 0) * 100),
                'amount_net'      => (int) round(($payload['amount_net'] ?? 0) * 100),
                'payer_name'      => trim(($payload['name_first'] ?? '') . ' ' . ($payload['name_last'] ?? '')),
                'payer_email'     => $payload['email_address'] ?? null,
                'payment_method'  => $payload['payment_method'] ?? null,
            ]);

            // Activate the subscription
            $this->activateSubscription($payment);

        } elseif (in_array($gatewayStatus, ['FAILED', 'CANCELLED'])) {
            $payment->markFailed(
                reason: "PayFast status: {$gatewayStatus}",
                payload: $payload
            );
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

        // Create earning for watch group
        $client = $subscription->client;
        if ($client) {
            Earning::createFromPayment($payment, $client);
        }

        // Create invoice and email it
        $invoice = Invoice::createFromPayment($payment);
        $invoice->load(['client.user', 'payment.subscription']);

        Mail::to($invoice->client->user->email)
            ->send(new InvoiceMail($invoice));

        $invoice->update(['sent_at' => now()]);
    }
}
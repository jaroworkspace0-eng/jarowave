<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Earning;
use App\Models\Invoice;
use App\Models\SubscriptionPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OzowWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->all();

        Log::info('Ozow webhook received', $payload);

        // 1. Find the pending payment by your reference you sent to Ozow
        $payment = SubscriptionPayment::where('gateway_payment_reference', $payload['TransactionReference'] ?? null)
            ->where('gateway', 'ozow')
            ->first();

        if (!$payment) {
            Log::warning('Ozow webhook: payment not found', $payload);
            return response('OK', 200);
        }

        // 2. Store full raw payload
        $payment->recordPayload($payload);

        // 3. Handle based on Status from Ozow
        $gatewayStatus = $payload['Status'] ?? null;

        if ($gatewayStatus === 'Complete') {
            $payment->markPaid(
                gatewayTransactionId: $payload['TransactionId'],
                payload: $payload
            );

            $payment->update([
                'gateway_status' => $gatewayStatus,
                'payer_name'     => $payload['CustomerName'] ?? null,
                'payer_email'    => $payload['CustomerEmail'] ?? null,
            ]);

            $this->activateSubscription($payment);

        } elseif (in_array($gatewayStatus, ['Cancelled', 'Error', 'PendingInvestigation'])) {
            $payment->markFailed(
                reason: "Ozow status: {$gatewayStatus}",
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

        Earning::createFromPayment($payment, $subscription->client);
        Invoice::createFromPayment($payment);
    }
}
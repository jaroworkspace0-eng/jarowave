<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Earning;
use App\Models\Invoice;
use App\Models\SubscriptionPayment;
use App\Mail\PaymentSuccessMail;
use App\Services\PayFastService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PayfastRecoveryWebhookController extends Controller
{
    public function handle(Request $request, PayFastService $payfast)
    {
        $data = $request->all();
        Log::info('PayFast Recovery ITN received', $data);

        // ── Verify ────────────────────────────────────────────────────────────
        $ip = $request->ip();
        if (app()->environment('production') && !$payfast->isValidIp($ip)) {
            Log::warning('PayFast Recovery ITN from invalid IP', ['ip' => $ip]);
            return response('Invalid IP', 403);
        }

        if (!$payfast->verifySignature($data)) {
            Log::warning('PayFast Recovery ITN signature mismatch');
            return response('Invalid signature', 400);
        }

        if (app()->environment('production') && !$payfast->verifyItn($data)) {
            Log::warning('PayFast Recovery ITN validation failed');
            return response('ITN validation failed', 400);
        }

        // ── Confirm this is a recovery payment ────────────────────────────────
        if (($data['custom_str3'] ?? '') !== 'recovery') {
            Log::warning('PayFast Recovery ITN: not a recovery payment');
            return response('OK', 200);
        }

        // ── Find the pending recovery payment ─────────────────────────────────
        $paymentId = $data['custom_str2'] ?? null;
        $payment   = SubscriptionPayment::find($paymentId);

        if (!$payment) {
            Log::warning('PayFast Recovery ITN: payment not found', ['payment_id' => $paymentId]);
            return response('OK', 200);
        }

        $subscription = $payment->subscription;
        $user         = $subscription->user;
        $status       = $data['payment_status'] ?? '';

        if ($status === 'COMPLETE') {

            // Update payment record
            $payment->update([
                'gateway'                   => 'payfast',
                'gateway_transaction_id'    => $data['pf_payment_id']  ?? null,
                'gateway_status'            => 'COMPLETE',
                'amount_gross'              => $data['amount_gross']    ?? null,
                'amount_fee'                => $data['amount_fee']      ?? null,
                'amount_net'                => $data['amount_net']      ?? null,
                'currency'                  => $data['currency_code']   ?? 'ZAR',
                'payment_method'            => $data['payment_method']  ?? null,
                'payer_name'                => trim(($data['name_first'] ?? '') . ' ' . ($data['name_last'] ?? '')) ?: null,
                'payer_email'               => $data['email_address']   ?? null,
                'status'                    => 'complete',
                'gateway_payload'           => json_encode($data),
                'signature'                 => $data['signature']       ?? null,
                'ip_address'                => $request->ip(),
                'paid_at'                   => now(),
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
                amount:    $data['amount_gross'] ?? null,
                periodEnd: now()->addDays(30)->format('d M Y'),
            ));

            Log::info('PayFast recovery payment complete', [
                'payment_id'      => $payment->id,
                'subscription_id' => $subscription->id,
                'user_id'         => $user->id,
            ]);

            // Notify Node.js — re-enable SOS instantly
            try {
                Http::withHeaders(['Authorization' => 'Bearer ' . env('ASSIGN_SECRET')])
                    ->post(env('PTT_SERVER_URL') . '/payment-resolved', ['userId' => $user->id]);
            } catch (\Exception $e) {
                Log::warning('Failed to notify Node.js of recovery resolution: ' . $e->getMessage());
            }

        } else {
            $payment->update([
                'gateway_status'  => $status,
                'status'          => 'failed',
                'failure_reason'  => "Recovery payment {$status}",
                'gateway_payload' => json_encode($data),
                'ip_address'      => $request->ip(),
            ]);

            Log::warning('PayFast recovery payment failed', [
                'payment_id' => $payment->id,
                'status'     => $status,
            ]);
        }

        return response('OK', 200);
    }
}

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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PayfastWebhookController extends Controller
{
    public function handle(Request $request, PayFastService $payfast)
    {
        $data = $request->all();
        Log::info('PayFast ITN received', $data);

        $ip = $request->ip();
        if (app()->environment('production') && !$payfast->isValidIp($ip)) {
            Log::warning('PayFast ITN from invalid IP', ['ip' => $ip]);
            return response('Invalid IP', 403);
        }

        Log::debug('PayFast ITN raw field order: ' . implode(', ', array_keys($request->all())));
        
        if (!$payfast->verifySignature($data)) {
            Log::warning('PayFast ITN signature mismatch', $data);
            return response('Invalid signature', 400);
        }

        if (app()->environment('production') && !$payfast->verifyItn($data)) {
            Log::warning('PayFast ITN validation failed', $data);
            return response('ITN validation failed', 400);
        }

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
            case 'COMPLETE':
                // Always save token
                $subscription->update([
                    'payfast_token'  => $tokenId,
                    'gateway_status' => 'COMPLETE',
                ]);

                // Trial setup (R0.00) — just save token, don't activate or create payment record
                if ($subscription->trial_ends_at && $subscription->trial_ends_at->isFuture()
                    && (float)($data['amount_gross'] ?? 0) == 0) {
                    Log::info('PayFast trial setup complete, token saved', [
                        'subscription_id' => $subscription->id,
                        'token'           => $tokenId,
                    ]);
                    break;
                }

                // Real payment — activate subscription
                $subscription->update([
                    'status'               => 'active',
                    'current_period_start' => now(),
                    'current_period_end'   => now()->addDays(30),
                ]);

                $payment = $subscription->payments()->create([
                    'gateway'                   => 'payfast',
                    'gateway_transaction_id'    => $data['pf_payment_id']  ?? null,
                    'gateway_payment_reference' => $data['m_payment_id']   ?? null,
                    'gateway_status'            => 'COMPLETE',
                    'merchant_reference'        => $data['m_payment_id']   ?? null,
                    'amount'                    => $data['amount_gross']    ?? 0,
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
                    'billing_period_start'      => now(),
                    'billing_period_end'        => now()->addDays(30),
                    'paid_at'                   => now(),
                ]);

               Earning::createFromPayment($payment, $subscription->client);

                $invoice = Invoice::createFromPayment($payment);
                $invoice->load('payment.subscription', 'client');

                Mail::to($user->email)->queue(new PaymentSuccessMail(
                    userName:  $user->name,
                    amount:    $data['amount_gross'] ?? null,
                    periodEnd: now()->addDays(30)->format('d M Y'),
                    invoice:   $invoice,
                ));
                Log::info('PayFast subscription activated', [
                    'subscription_id' => $subscription->id,
                    'token'           => $tokenId,
                ]);

                try {
                    Http::withHeaders(['Authorization' => 'Bearer ' . env('ASSIGN_SECRET')])
                        ->post(env('PTT_SERVER_URL') . '/payment-resolved', ['userId' => $user->id]);
                } catch (\Exception $e) {
                    Log::warning('Failed to notify Node.js of payment resolution: ' . $e->getMessage());
                }

                break;

            case 'FAILED':
                $subscription->update(['status' => 'past_due', 'gateway_status' => 'FAILED']);

                $subscription->payments()->create([
                    'gateway'                   => 'payfast',
                    'gateway_transaction_id'    => $data['pf_payment_id']  ?? null,
                    'gateway_payment_reference' => $data['m_payment_id']   ?? null,
                    'gateway_status'            => 'FAILED',
                    'merchant_reference'        => $data['m_payment_id']   ?? null,
                    'amount'                    => $data['amount_gross']    ?? 0,
                    'amount_gross'              => $data['amount_gross']    ?? null,
                    'amount_fee'                => $data['amount_fee']      ?? null,
                    'amount_net'                => $data['amount_net']      ?? null,
                    'currency'                  => $data['currency_code']   ?? 'ZAR',
                    'payment_method'            => $data['payment_method']  ?? null,
                    'payer_name'                => trim(($data['name_first'] ?? '') . ' ' . ($data['name_last'] ?? '')) ?: null,
                    'payer_email'               => $data['email_address']   ?? null,
                    'status'                    => 'failed',
                    'failure_reason'            => 'Payment failed',
                    'gateway_payload'           => json_encode($data),
                    'signature'                 => $data['signature']       ?? null,
                    'ip_address'                => $request->ip(),
                ]);

                Mail::to($user->email)->queue(new PaymentFailedMail(
                    userName: $user->name,
                    amount:   $data['amount_gross'] ?? null,
                    reason:   'Payment failed',
                ));

                Log::warning('PayFast payment failed', ['subscription_id' => $subscription->id]);

                if (!$subscription->trial_ends_at || $subscription->trial_ends_at->isPast()) {
                    try {
                        Http::withHeaders(['Authorization' => 'Bearer ' . env('ASSIGN_SECRET')])
                            ->post(env('PTT_SERVER_URL') . '/payment-failed', [
                                'userId'      => $user->id,
                                'userEmail'   => $user->email,
                                'userName'    => $user->name,
                                'amount'      => $data['amount_gross'] ?? null,
                                'reason'      => 'Payment failed',
                                'trialEndsAt' => $subscription->trial_ends_at?->toISOString(),
                            ]);
                    } catch (\Exception $e) {
                        Log::warning('Failed to notify Node.js of payment failure: ' . $e->getMessage());
                    }
                }

                break;

            case 'CANCELLED':
                $subscription->update([
                    'status'         => 'cancelled',
                    'ends_at'        => $subscription->current_period_end,
                    'gateway_status' => 'CANCELLED',
                ]);

                $subscription->payments()->create([
                    'gateway'                   => 'payfast',
                    'gateway_transaction_id'    => $data['pf_payment_id']  ?? null,
                    'gateway_payment_reference' => $data['m_payment_id']   ?? null,
                    'gateway_status'            => 'CANCELLED',
                    'merchant_reference'        => $data['m_payment_id']   ?? null,
                    'amount'                    => $data['amount_gross']    ?? 0,
                    'amount_gross'              => $data['amount_gross']    ?? null,
                    'currency'                  => $data['currency_code']   ?? 'ZAR',
                    'payer_name'                => trim(($data['name_first'] ?? '') . ' ' . ($data['name_last'] ?? '')) ?: null,
                    'payer_email'               => $data['email_address']   ?? null,
                    'status'                    => 'cancelled',
                    'failure_reason'            => 'Subscription cancelled',
                    'gateway_payload'           => json_encode($data),
                    'signature'                 => $data['signature']       ?? null,
                    'ip_address'                => $request->ip(),
                ]);

                Mail::to($user->email)->queue(new SubscriptionCancelledMail(
                    userName:  $user->name,
                    accessEnd: $subscription->current_period_end?->format('d M Y'),
                ));

                Log::info('PayFast subscription cancelled', ['subscription_id' => $subscription->id]);

                if (!$subscription->trial_ends_at || $subscription->trial_ends_at->isPast()) {
                    try {
                        Http::withHeaders(['Authorization' => 'Bearer ' . env('ASSIGN_SECRET')])
                            ->post(env('PTT_SERVER_URL') . '/payment-failed', [
                                'userId'    => $user->id,
                                'userEmail' => $user->email,
                                'userName'  => $user->name,
                                'amount'    => null,
                                'reason'    => 'Subscription cancelled',
                            ]);
                    } catch (\Exception $e) {
                        Log::warning('Failed to notify Node.js of subscription cancellation: ' . $e->getMessage());
                    }
                }

                break;

            default:
                Log::info('PayFast ITN unhandled status', [
                    'status'          => $paymentStatus,
                    'subscription_id' => $subscription->id,
                ]);
        }

        return response('OK', 200);
    }
}
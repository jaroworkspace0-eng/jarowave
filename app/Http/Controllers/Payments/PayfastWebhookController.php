<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\PayFastService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PayfastWebhookController extends Controller
{
    public function handle(Request $request, PayFastService $payfast)
    {
        $data = $request->all();

        Log::info('PayFast ITN received', $data);

        // ── 1. Verify IP ──────────────────────────────────────────────────────
        $ip = $request->ip();
        if (app()->environment('production') && !$payfast->isValidIp($ip)) {
            Log::warning('PayFast ITN from invalid IP', ['ip' => $ip]);
            return response('Invalid IP', 403);
        }

        // ── 2. Verify signature ───────────────────────────────────────────────
        if (!$payfast->verifySignature($data)) {
            Log::warning('PayFast ITN signature mismatch', $data);
            return response('Invalid signature', 400);
        }

        // ── 3. Verify ITN with PayFast servers ────────────────────────────────
        if (app()->environment('production') && !$payfast->verifyItn($data)) {
            Log::warning('PayFast ITN validation failed', $data);
            return response('ITN validation failed', 400);
        }

        // ── 4. Find subscription by merchant reference ────────────────────────
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

        // ── 5. Handle payment status ──────────────────────────────────────────
        $paymentStatus = $data['payment_status'] ?? '';
        $tokenId       = $data['token'] ?? null; // PayFast subscription token

        switch ($paymentStatus) {
            case 'COMPLETE':
                $subscription->update([
                    'status'               => 'active',
                    'payfast_token'        => $tokenId,
                    'current_period_start' => now(),
                    'current_period_end'   => now()->addDays(30),
                    'gateway_status'       => 'COMPLETE',
                ]);

                Log::info('PayFast subscription activated', [
                    'subscription_id' => $subscription->id,
                    'token'           => $tokenId,
                ]);
                break;

            case 'FAILED':
                $subscription->update([
                    'status'         => 'past_due',
                    'gateway_status' => 'FAILED',
                ]);

                Log::warning('PayFast payment failed', [
                    'subscription_id' => $subscription->id,
                ]);
                break;

            case 'CANCELLED':
                $subscription->update([
                    'status'         => 'cancelled',
                    'ends_at'        => $subscription->current_period_end,
                    'gateway_status' => 'CANCELLED',
                ]);

                Log::info('PayFast subscription cancelled', [
                    'subscription_id' => $subscription->id,
                ]);
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

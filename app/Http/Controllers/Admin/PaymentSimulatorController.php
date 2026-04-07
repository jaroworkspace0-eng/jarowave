<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Earning;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Mail\PaymentFailedMail;
use App\Mail\PaymentSuccessMail;
use App\Mail\SubscriptionCancelledMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PaymentSimulatorController extends Controller
{
    public function index()
    {
        abort_if(app()->environment('production'), 403, 'Not available in production.');

        $users = User::whereIn('role', ['household', 'resident'])
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('admin.payment-simulator', compact('users'));
    }

    public function simulate(Request $request)
    {
        abort_if(app()->environment('production'), 403, 'Not available in production.');

        $request->validate([
            'type'    => 'required|in:complete,failed,suspended,cancelled,resolved',
            'user_id' => 'required|exists:users,id',
        ]);

        $user         = User::find($request->user_id);
        $subscription = Subscription::where('user_id', $user->id)->latest()->first();

        if (!$subscription) {
            return response()->json(['success' => false, 'error' => 'No subscription found for this user.']);
        }

        $type    = $request->type;
        $results = [];

        switch ($type) {

            case 'complete':
                $subscription->update([
                    'status'               => 'active',
                    'gateway_status'       => 'COMPLETE',
                    'current_period_start' => now(),
                    'current_period_end'   => now()->addDays(30),
                    'payment_failed_at'    => null,
                    'sos_suspended_at'     => null,
                ]);

                $payment = $subscription->payments()->create([
                    'gateway'               => 'payfast',
                    'gateway_status'        => 'COMPLETE',
                    'merchant_reference'    => 'SIM-' . now()->timestamp,
                    'amount'                => 80.00,
                    'amount_gross'          => 80.00,
                    'currency'              => 'ZAR',
                    'payer_name'            => $user->name,
                    'payer_email'           => $user->email,
                    'status'                => 'complete',
                    'billing_period_start'  => now(),
                    'billing_period_end'    => now()->addDays(30),
                    'paid_at'               => now(),
                    'gateway_payload'       => json_encode(['simulated' => true, 'type' => 'complete']),
                ]);

                Earning::createFromPayment($payment, $subscription->client);
                Invoice::createFromPayment($payment);

                Mail::to($user->email)->queue(new PaymentSuccessMail(
                    userName:  $user->name,
                    amount:    '80.00',
                    periodEnd: now()->addDays(30)->format('d M Y'),
                ));

                $results['payment_id'] = $payment->id;
                $results['email']      = 'PaymentSuccessMail queued';
                $results['node']       = $this->notifyNode('/payment-resolved', ['userId' => $user->id]);
                break;

            case 'failed':
                $subscription->update([
                    'status'            => 'past_due',
                    'gateway_status'    => 'FAILED',
                    'payment_failed_at' => now(),
                    'sos_suspended_at'  => null,
                ]);

                $subscription->payments()->create([
                    'gateway'            => 'payfast',
                    'gateway_status'     => 'FAILED',
                    'merchant_reference' => 'SIM-' . now()->timestamp,
                    'amount'             => 80.00,
                    'amount_gross'       => 80.00,
                    'currency'           => 'ZAR',
                    'payer_name'         => $user->name,
                    'payer_email'        => $user->email,
                    'status'             => 'failed',
                    'failure_reason'     => 'Simulated payment failure',
                    'gateway_payload'    => json_encode(['simulated' => true, 'type' => 'failed']),
                ]);

                Mail::to($user->email)->queue(new PaymentFailedMail(
                    userName: $user->name,
                    amount:   '80.00',
                    reason:   'Simulated payment failure',
                ));

                $results['email'] = 'PaymentFailedMail queued';
                $results['node']  = $this->notifyNode('/payment-failed', [
                    'userId'      => $user->id,
                    'userEmail'   => $user->email,
                    'userName'    => $user->name,
                    'amount'      => '80.00',
                    'reason'      => 'Simulated payment failure',
                    'trialEndsAt' => $subscription->trial_ends_at?->toISOString(),
                ]);
                break;

            case 'suspended':
                $subscription->update([
                    'status'           => 'past_due',
                    'gateway_status'   => 'FAILED',
                    'sos_suspended_at' => now(),
                ]);

                $results['node'] = $this->notifyNode('/payment-failed', [
                    'userId'       => $user->id,
                    'userEmail'    => $user->email,
                    'userName'     => $user->name,
                    'amount'       => '80.00',
                    'reason'       => 'Simulated suspension (grace period expired)',
                    'forceSuspend' => true,
                ]);
                break;

            case 'cancelled':
                $subscription->update([
                    'status'            => 'cancelled',
                    'gateway_status'    => 'CANCELLED',
                    'ends_at'           => $subscription->current_period_end,
                    'payment_failed_at' => now(),
                ]);

                $subscription->payments()->create([
                    'gateway'            => 'payfast',
                    'gateway_status'     => 'CANCELLED',
                    'merchant_reference' => 'SIM-' . now()->timestamp,
                    'amount'             => 80.00,
                    'amount_gross'       => 80.00,
                    'currency'           => 'ZAR',
                    'payer_name'         => $user->name,
                    'payer_email'        => $user->email,
                    'status'             => 'cancelled',
                    'failure_reason'     => 'Simulated cancellation',
                    'gateway_payload'    => json_encode(['simulated' => true, 'type' => 'cancelled']),
                ]);

                Mail::to($user->email)->queue(new SubscriptionCancelledMail(
                    userName:  $user->name,
                    accessEnd: $subscription->current_period_end?->format('d M Y'),
                ));

                $results['email'] = 'SubscriptionCancelledMail queued';
                $results['node']  = $this->notifyNode('/subscription-cancelled', [
                    'userId'    => $user->id,
                    'accessEnd' => $subscription->current_period_end?->toISOString(),
                ]);
                break;

            case 'resolved':
                $subscription->update([
                    'status'            => 'active',
                    'gateway_status'    => 'COMPLETE',
                    'payment_failed_at' => null,
                    'sos_suspended_at'  => null,
                ]);

                $payment = $subscription->payments()->create([
                    'gateway'              => 'payfast',
                    'gateway_status'       => 'COMPLETE',
                    'merchant_reference'   => 'SIM-RECOVERY-' . now()->timestamp,
                    'amount'               => 80.00,
                    'amount_gross'         => 80.00,
                    'currency'             => 'ZAR',
                    'payer_name'           => $user->name,
                    'payer_email'          => $user->email,
                    'status'               => 'complete',
                    'billing_period_start' => now(),
                    'billing_period_end'   => now()->addDays(30),
                    'paid_at'              => now(),
                    'gateway_payload'      => json_encode(['simulated' => true, 'type' => 'resolved']),
                ]);

                Earning::createFromPayment($payment, $subscription->client);
                Invoice::createFromPayment($payment);

                $results['payment_id'] = $payment->id;
                $results['node']       = $this->notifyNode('/payment-resolved', ['userId' => $user->id]);
                break;
        }

        Log::info("Payment simulated: type={$type} userId={$user->id}");

        return response()->json([
            'success'      => true,
            'type'         => $type,
            'user'         => $user->name,
            'subscription' => $subscription->fresh()->status,
            'results'      => $results,
        ]);
    }

    private function notifyNode(string $endpoint, array $payload): array
    {
        try {
            $res = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('ASSIGN_SECRET'),
            ])->post(env('PTT_SERVER_URL') . $endpoint, $payload);

            return ['status' => $res->status(), 'body' => $res->json()];
        } catch (\Exception $e) {
            return ['status' => 'error', 'body' => $e->getMessage()];
        }
    }
}
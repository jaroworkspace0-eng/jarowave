<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\ConductBlockMail;
use App\Mail\ConductUnblockMail;
use App\Mail\PaymentSuccessMail;
use App\Models\Earning;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AdminSubscriptionController extends Controller
{
    private string $nodeUrl;

    public function __construct()
    {
        $this->nodeUrl = rtrim(env('PTT_SERVER_URL', 'https://radio.server.jaroworkspace.com'), '/');
    }

    // ── GET /api/admin/subscriptions ─────────────────────────────────────────
    // Returns household subscriptions with user + payment history
    public function index(Request $request)
    {
        $query = Subscription::with([
            'user:id,name,email,phone',
            'payments' => fn($q) => $q->latest()->limit(5),
        ])
            ->whereNotNull('user_id')
            ->latest();

        if ($request->search) {
            $search = $request->search;
            $query->whereHas('user', fn($q) => $q->where('name', 'like', "%$search%")
                ->orWhere('email', 'like', "%$search%"));
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        return response()->json($query->paginate(20));
    }

    // ── GET /api/admin/subscriptions/{id}/payments ───────────────────────────
    public function payments(Subscription $subscription)
    {
        return response()->json(
            $subscription->payments()->latest()->get()
        );
    }

    // ── POST /api/admin/subscriptions/{id}/eft-payment ───────────────────────
    // Mark as manually paid via EFT — creates payment, invoice, earning, notifies Node
    /**
     * Mark a subscription as manually paid via EFT.
     *
     * - Wraps payment + subscription update in a DB transaction
     * - Handles earning/invoice/email failures gracefully without hiding them from the caller
     * - Uses actual period end from subscription for email, not a hardcoded +30 days
     * - Surfaces invoice/email success state in the response
     */
    public function markEftPaid(Request $request, Subscription $subscription)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'note'   => 'required|string|max:255',
            'proof'  => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        $proofPath    = $request->file('proof')->store('eft-proofs', 'public');
        $amountRands  = round($request->amount, 2);
        $eftReference = 'EFT-' . strtoupper(uniqid());
        $user         = $subscription->user;

        $payment = DB::transaction(function () use (
            $request, $subscription, $proofPath, $amountRands, $eftReference
        ) {
            $periodStart = $subscription->current_period_end ?? now();
            $periodEnd   = $periodStart->copy()->addDays(30);

            $payment = SubscriptionPayment::create([
                'subscription_id'           => $subscription->id,
                'user_id'                   => $subscription->user_id,
                'amount'                    => $amountRands,
                'amount_gross'              => $amountRands,
                'amount_fee'                => 0,
                'amount_net'                => $amountRands,
                'status'                    => 'complete',
                'gateway'                   => 'manual_eft',
                'gateway_transaction_id'    => null,
                'gateway_payment_reference' => $eftReference,
                'gateway_status'            => 'COMPLETE',
                'merchant_reference'        => $eftReference,
                'currency'                  => 'ZAR',
                'payment_method'            => 'eft',
                'payer_name'                => trim($subscription->user->name ?? '') ?: null,
                'payer_email'               => $subscription->user->email ?? null,
                'gateway_payload'           => null,
                'signature'                 => null,
                'ip_address'                => $request->ip(),
                'billing_period_start'      => $periodStart,
                'billing_period_end'        => $periodEnd,
                'paid_at'                   => now(),
                'notes'                     => $request->note,
                'proof_of_payment'          => $proofPath,
            ]);

            $subscription->update([
                'status'               => 'active',
                'payment_failed_at'    => null,
                'sos_suspended_at'     => null,
                'gateway'              => 'manual_eft',
                'current_period_start' => $periodStart,
                'current_period_end'   => $periodEnd,
            ]);

            return $payment;
        });

        $invoiceSent     = false;
        $sideEffectError = null;

        try {
            if ($subscription->client) {
                Earning::createFromPayment($payment, $subscription->client);
            }

            $invoice = Invoice::createFromPayment($payment);
            $invoice->load('payment.subscription', 'client');

            Mail::to($user->email)->queue(new PaymentSuccessMail(
                userName:  $user->name,
                amount:    $amountRands,
                periodEnd: $subscription->fresh()->current_period_end->format('d M Y'),
                invoice:   $invoice,
            ));

            $invoiceSent = true;

            if (!$subscription->activation_fee_paid) {
                $subscription->update([
                    'activation_fee_paid'    => true,
                    'activation_fee_paid_at' => now(),
                    'price'                  => BillingService::UNIT_PRICE / 100,
                ]);
            }
        } catch (\Throwable $e) {
            $sideEffectError = $e->getMessage();
            Log::warning('EFT payment: earning/invoice/email failed', [
                'subscription_id' => $subscription->id,
                'payment_id'      => $payment->id,
                'error'           => $sideEffectError,
            ]);
        }

        $this->notifyNode('POST', '/payment-resolved', [
            'userId' => $subscription->user_id,
            'note'   => 'EFT payment confirmed by admin',
        ]);

        return response()->json([
            'success'           => true,
            'message'           => 'EFT payment recorded. SOS re-enabled.',
            'invoice_sent'      => $invoiceSent,
            'side_effect_error' => $sideEffectError,
        ]);
    }

    // ── POST /api/admin/subscriptions/{id}/suspend ───────────────────────────
    public function suspend(Request $request, Subscription $subscription)
    {
        $subscription->update([
            'status'           => 'past_due',
            'sos_suspended_at' => now(),
        ]);

        $this->notifyNode('POST', '/payment-failed', [
            'userId'       => $subscription->user_id,
            'forceSuspend' => true,
            'reason'       => $request->reason ?? 'Manually suspended by admin',
        ]);

        return response()->json(['success' => true, 'message' => 'SOS suspended.']);
    }

    // ── POST /api/admin/subscriptions/{id}/unsuspend ─────────────────────────
    public function unsuspend(Subscription $subscription)
    {
        $subscription->update([
            'status'             => 'active',
            'payment_failed_at'  => null,
            'sos_suspended_at'   => null,
        ]);

        $this->notifyNode('POST', '/payment-resolved', [
            'userId' => $subscription->user_id,
        ]);

        return response()->json(['success' => true, 'message' => 'SOS reinstated.']);
    }

    // ── POST /api/admin/subscriptions/{id}/cancel ────────────────────────────
    public function cancel(Request $request, Subscription $subscription)
    {
        $accessEnd = $subscription->current_period_end ?? now()->addDays(30);

        $subscription->update([
            'status'       => 'cancelled',
            'cancelled_at' => now(),
            'ends_at'      => $accessEnd,
        ]);

        $this->notifyNode('POST', '/subscription-cancelled', [
            'userId'    => $subscription->user_id,
            'accessEnd' => $accessEnd,
        ]);

        return response()->json(['success' => true, 'message' => 'Subscription cancelled. Access until ' . $accessEnd->toDateString() . '.']);
    }


    public function conductBlock(Request $request, Subscription $subscription)
    {
        $request->validate(['reason' => 'required|string|max:500']);

        $subscription->update([
            'conduct_blocked_at'    => now(),
            'conduct_block_reason'  => $request->reason,
            'sos_suspended_at'      => now(),
        ]);


        Mail::to($subscription->user->email)
            ->queue(new ConductBlockMail(
                userName: $subscription->user->name,
                reason:   $request->reason,
            ));

        $this->notifyNode('POST', '/payment-failed', [
            'userId'       => $subscription->user_id,
            'forceSuspend' => true,
            'reason'       => 'Conduct block: ' . $request->reason,
        ]);

        return response()->json(['success' => true, 'message' => 'Household blocked for conduct abuse.']);
    }

    public function conductUnblock(Subscription $subscription)
    {
        $subscription->update([
            'conduct_blocked_at'   => null,
            'conduct_block_reason' => null,
            'sos_suspended_at'     => null,
            'status'               => 'active',
        ]);


        Mail::to($subscription->user->email)
            ->queue(new ConductUnblockMail(
                userName: $subscription->user->name,
            ));

        $this->notifyNode('POST', '/payment-resolved', [
            'userId' => $subscription->user_id,
        ]);

        return response()->json(['success' => true, 'message' => 'Conduct block lifted. SOS restored.']);
    }


    // ── POST /api/admin/subscriptions/{id}/activation-fee ────────────────────
    public function markActivationFeePaid(Request $request, Subscription $subscription)
    {
        $paid = $request->boolean('paid', true);
        $subscription->update([
            'activation_fee_paid'    => $paid,
            'activation_fee_paid_at' => $paid ? now() : null,
        ]);
        return response()->json([
            'success' => true,
            'message' => $paid ? 'Activation fee marked as paid.' : 'Activation fee marked as unpaid.',
        ]);
    }

    // ── Helper ────────────────────────────────────────────────────────────────
    private function notifyNode(string $method, string $path, array $payload): void
    {
        try {
            Http::timeout(5)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . env('ASSIGN_SECRET'),
                    'Content-Type'  => 'application/json',
                ])
                ->{strtolower($method)}($this->nodeUrl . $path, $payload);
        } catch (\Throwable $e) {
            Log::warning('AdminSubscriptionController: Node notify failed', ['path' => $path, 'error' => $e->getMessage()]);
        }
    }
}
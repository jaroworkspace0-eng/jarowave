<?php

namespace App\Jobs;

use App\Models\SubscriptionPayment;
use App\Models\Invoice;
use App\Models\Earning;
use App\Models\AccountLink;
use App\Mail\PaymentSuccessMail;
use App\Services\BillingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProcessPayfastPaymentSideEffects implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [60, 300, 900, 3600, 14400]; // 1m, 5m, 15m, 1h, 4h

    public function __construct(
        public SubscriptionPayment $payment,
        public string $periodEndFormatted,
        public ?string $amountGross,
    ) {}

    public function handle(): void
    {
        $subscription = $this->payment->subscription;
        $user         = $subscription->user;
        $channel      = $user->employee?->channels()->first();

        if ($subscription->client) {
            Earning::createFromPayment($this->payment, $subscription->client);
        }

        $invoice = Invoice::createFromPayment($this->payment);
        $invoice->load('payment.subscription', 'client');

        // Primary account's own recurring rate. $subscription->price is the
        // source of truth once activation has run once (set just below on
        // first payment); fall back to the live channel rate for the very
        // first email, before that write has happened.
        $primaryMonthlyPrice = $subscription->price
            ?? BillingService::unitPrice($channel?->amount_per_household);

        Mail::to($user->email)->send(new PaymentSuccessMail(
            userName:     $user->name,
            amount:       $this->amountGross,
            periodEnd:    $this->periodEndFormatted,
            invoice:      $invoice,
            monthlyPrice: $primaryMonthlyPrice,
        ));

        if (! $subscription->activation_fee_paid) {
            $subscription->update([
                'activation_fee_paid'    => true,
                'activation_fee_paid_at' => now(),
                'price'                  => BillingService::unitPrice($channel?->amount_per_household),
            ]);
        }

        // Consolidate and mark paid any accounts linked under this subscriber —
        // mirrors Subscription::markEftPaid()'s linked-account handling.
        // NOTE: subscription state (status/dates/sos_suspended_at) for linked
        // accounts is already updated synchronously in PayfastWebhookController's
        // transaction — this job only creates their payment/invoice/email and
        // notifies Node, it does not touch $linkedSub state.
        $linkedAccounts = AccountLink::with('linkedAccount.subscription')
            ->where('primary_account_id', $subscription->user_id)
            ->where('status', 'active')
            ->get();

        $linkedAmount = BillingService::unitPrice($channel?->amount_per_linked_account);

        foreach ($linkedAccounts as $link) {
            $linkedUser = $link->linkedAccount;
            $linkedSub  = $linkedUser?->subscription;

            if (! $linkedUser || ! $linkedSub) {
                Log::warning('PayFast payment: linked account has no subscription to activate', [
                    'primary_subscription_id' => $subscription->id,
                    'account_link_id'         => $link->id,
                    'linked_user_id'          => $link->linked_account_id,
                ]);
                continue;
            }


            if ($linkedSub->isEstateBilled()) {
                Log::info('PayFast payment: linked account is estate-billed, skipping standalone payment/invoice', [
                    'primary_subscription_id' => $subscription->id,
                    'account_link_id'         => $link->id,
                    'linked_user_id'          => $linkedUser->id,
                    'linked_subscription_id'  => $linkedSub->id,
                ]);
                continue;
            }

            $linkedPayment = SubscriptionPayment::create([
                'subscription_id'           => $linkedSub->id,
                'user_id'                   => $linkedSub->user_id,
                'amount'                    => $linkedAmount,
                'amount_gross'              => $linkedAmount,
                'amount_fee'                => 0,
                'amount_net'                => $linkedAmount,
                'status'                    => 'complete',
                'gateway'                   => 'payfast',
                'gateway_transaction_id'    => $this->payment->gateway_transaction_id,
                'gateway_payment_reference' => $this->payment->gateway_payment_reference . '-L' . $linkedSub->id,
                'gateway_status'            => 'COMPLETE',
                'merchant_reference'        => $this->payment->merchant_reference,
                'currency'                  => 'ZAR',
                'payment_method'            => 'payfast',
                'payer_name'                => trim($linkedUser->name ?? '') ?: null,
                'payer_email'               => $linkedUser->email ?? null,
                'gateway_payload'           => null,
                'signature'                 => null,
                'ip_address'                => $this->payment->ip_address,
                'billing_period_start'      => $linkedSub->current_period_start,
                'billing_period_end'        => $linkedSub->current_period_end,
                'paid_at'                   => now(),
                'notes'                     => 'Consolidated under linked account PayFast payment: ' . $this->payment->merchant_reference,
                'proof_of_payment'          => null,
            ]);

            try {
                $linkedInvoice = Invoice::createFromPayment($linkedPayment);
                $linkedInvoice->load('payment.subscription', 'client');

                if ($linkedUser->email) {
                    Mail::to($linkedUser->email)->send(new PaymentSuccessMail(
                        userName:         $linkedUser->name,
                        amount:           $linkedAmount,
                        periodEnd:        $linkedSub->current_period_end->format('d M Y'),
                        invoice:          $linkedInvoice,
                        monthlyPrice:     $linkedAmount,
                        primaryPayerName: $user->name,
                    ));
                }
            } catch (\Throwable $e) {
                Log::warning('PayFast payment: linked account invoice/email failed', [
                    'linked_subscription_id' => $linkedSub->id,
                    'linked_payment_id'      => $linkedPayment->id,
                    'error'                  => $e->getMessage(),
                ]);
            }

            $this->notifyNode('POST', '/payment-resolved', [
                'userId' => $linkedUser->id,
                'note'   => 'PayFast payment confirmed (consolidated under primary)',
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ProcessPayfastPaymentSideEffects: all retries exhausted', [
            'payment_id' => $this->payment->id,
            'error'      => $e->getMessage(),
        ]);
    }

    private function notifyNode(string $method, string $endpoint, array $payload): void
    {
        try {
            Http::timeout(5)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . env('ASSIGN_SECRET'),
                    'Content-Type'  => 'application/json',
                ])
                ->{strtolower($method)}(rtrim(env('PTT_SERVER_URL'), '/') . $endpoint, $payload);
        } catch (\Throwable $e) {
            Log::warning('ProcessPayfastPaymentSideEffects: Node notify failed', [
                'endpoint' => $endpoint,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
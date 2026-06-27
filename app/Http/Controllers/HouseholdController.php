<?php

namespace App\Http\Controllers;

use App\Mail\HouseholdWelcomeMail;
use App\Mail\InvoiceMail;
use App\Models\Channel;
use App\Models\Employee;
use App\Models\HouseholdInvite;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Services\PayFastService;
use App\Traits\NotifiesNode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class HouseholdController extends Controller
{
    use NotifiesNode;
    // ── POST /api/household/login ─────────────────────────────────────────────
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)
            ->whereIn('role', ['household', 'resident'])
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Your account has been deactivated. Please contact your watch group.'], 403);
        }

        $token = $user->createToken('household-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role'  => $user->role,
            ],
        ]);
    }


    // self-registration for households via invite token
    // ── POST /api/household/register ──────────────────────────────────────────
    public function register(Request $request)
    {
        $request->headers->set('Accept', 'application/json');

        $request->validate([
            'invite_token' => 'required|string',
            'name'         => 'required|string|max:255',
            'email'        => 'required|email|unique:users,email',
            'phone'        => 'nullable|string|max:20|unique:users,phone',
            'password'     => 'required|string|min:8|confirmed',
            'is_estate' => 'boolean',
            // 'gateway'      => 'required|in:payfast,ozow',
        ]);

        $invite = HouseholdInvite::where('token', $request->invite_token)
            ->with('client.user')
            ->first();

        if (!$invite) {
            return response()->json(['message' => 'Invalid invite link.'], 422);
        }

        if ($invite->expires_at && $invite->expires_at->isPast()) {
            return response()->json(['message' => 'This invite link has expired.'], 422);
        }

        if ($invite->max_uses && $invite->uses >= $invite->max_uses) {
            return response()->json(['message' => 'This invite link has reached its limit.'], 422);
        }


        $channel = $invite->channel_id ? Channel::find($invite->channel_id) : null;

        // Determine org type from the client's user record
        $orgType = $invite->client->user->organisation_type ?? 'watch';

        // Create user
        $user = User::create([
            'name'       => $request->name,
            'email'      => $request->email,
            'phone'      => $request->phone,
            'password'   => bcrypt($request->password),
            'role'       => 'household',
            'occupation' => 'household',
            'is_active'  => true,
            'status'     => 'offline',
            'is_estate'  => $request->boolean('is_estate', false),

        ]);

        // Create employee record
        $employee = Employee::create([
            'user_id'   => $user->id,
            'client_id' => $invite->client_id,
        ]);

        // Assign to channel from invite
        if ($invite->channel_id) {
            $employee->channels()->attach($invite->channel_id, [
                'is_online' => false,
                'last_seen' => now(),
            ]);
        }

        // Increment invite usage
        $invite->increment('uses');

        // Generate unique merchant reference
        $merchantReference = 'HH-' . $user->id . '-' . time();

        // Create subscription with correct split based on org type
        $subscription = Subscription::create([
            'user_id'            => $user->id,
            'client_id'          => $invite->client_id,
            'client_type'        => $orgType,
            'status'             => 'trialing',
            'plan'               => null,
            'billing_cycle'      => 'monthly',
            // 'price'              => BillingService::UNIT_PRICE, // 80.00
            'price' => BillingService::unitPrice($channel->amount_per_household),
            'trial_ends_at'      => now()->addDays(14), // 14-day trial
            'merchant_reference' => $merchantReference,
        ]);

        $channelName = $channel?->name ?? $invite->client->user->organisation_name;
        $amountPerHousehold = $channel->amount_per_household;
        $token = $user->createToken('household-token')->plainTextToken;


        Mail::to($user->email)->queue(new HouseholdWelcomeMail(
            user: $user,
            organisationName: $invite->client->user->organisation_name
                        ?? $invite->client->user->name
                        ?? 'Echo Link Community',
            gateway: $request->gateway ?? 'payfast',
            adminAdded: false,
            tempPassword: null,
            amountPerHousehold: $amountPerHousehold,
            channelName: $channelName
        ));

        // Build PayFast payment URL
        // $redirectUrl = $this->initiatePayment($user, $request->gateway, $merchantReference);



        return response()->json([
            'token'        => $token,
            'user'         => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role'  => $user->role,
            ],
            'redirect_url' => 'dashboard.html',
            // 'redirect_url' => $redirectUrl,
        ]);
    }
 
    // ── Private: initiate PayFast subscription ────────────────────────────────
    private function initiatePayment(User $user, string $gateway, string $merchantReference): string
    {
        if ($gateway === 'payfast') {
            $payfast = new PayFastService();
 
            return $payfast->buildSubscriptionUrl([
                'name_first'    => explode(' ', $user->name)[0],
                'name_last'     => explode(' ', $user->name, 2)[1] ?? '',
                'email_address' => $user->email,
                'cell_number'   => $user->phone ?? '',
                'm_payment_id'  => $merchantReference,
                'item_name'     => 'Echo Link Community Protection',
                'item_description' => '14-day free trial then R80 per month neighbourhood watch subscription',
            ]);
        }
 
        // Ozow — not recurring, redirect to dashboard for now
        // TODO: implement Ozow recurring when available
        return 'dashboard';
    }
 

    // ── GET /api/household/invite/{token} ─────────────────────────────────────
    public function validateInvite($token)
    {
        $invite = HouseholdInvite::where('token', $token)
            ->with('client.user', 'channel')
            ->first();

        if (!$invite) {
            return response()->json(['error' => 'Invalid invite link.'], 404);
        }

        if ($invite->expires_at && $invite->expires_at->isPast()) {
            return response()->json(['error' => 'This invite link has expired.'], 410);
        }

        if ($invite->max_uses && $invite->uses >= $invite->max_uses) {
            return response()->json(['error' => 'This invite link has reached its limit.'], 410);
        }

        return response()->json([
            'group' => [
                'organisation_name' => $invite->client->user->organisation_name,
                'area'              => $invite->client->user->address_line_1 ?? null,
                'channel_name'      => $invite->channel?->name ?? $invite->client->user->organisation_name,
                'amount_per_household' => number_format($invite->channel?->amount_per_household, 0) ?? 80,
            ],
        ]);
    }

    // ── GET /api/household/subscription ──────────────────────────────────────
    public function subscription(Request $request)
    {
        $subscription = Subscription::where('user_id', $request->user()->id)
            ->with('client.user')
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json(['subscription' => null]);
        }

        $orgType = $subscription->client_type ?? $subscription->client?->user?->organisation_type ?? 'watch';
        $amounts = BillingService::getDisplayAmounts($orgType);

        $subChannel = $subscription->channelSubscription?->channel;

        $employee = Employee::where('user_id', $request->user()->id)
        ->with('channels')
        ->first();

        $channel = $employee?->channels()->first();

        return response()->json([
            'subscription' => [
                'status'               => $subscription->status,
                'plan'                 => $subscription->plan,
                'gateway'              => $subscription->gateway,
                'payfast_token'        => $subscription->payfast_token,
                'client_type'          => $orgType,
                'amounts'              => $amounts, // { total: 80, client: 52|30, platform: 28|50 }
                'trial_ends_at'        => $subscription->trial_ends_at,
                'billing_cycle'        => $subscription->billing_cycle,
                'current_period_start' => $subscription->current_period_start,
                'current_period_end'   => $subscription->current_period_end,
                'ends_at'              => $subscription->ends_at,
                'days_left_in_trial'   => $subscription->daysLeftInTrial(),
                'watch_group'          => $subscription->client ? [
                    'organisation_name' => $subscription->client->user->organisation_name,
                    'organisation_type' => $orgType,
                ] : null,
                'channel_name' => $channel?->name,
                'amount_per_household'  => number_format($channel?->amount_per_household, 0),
                'billing_model'         => $channel?->billing_model,
                'is_active'             => $channel?->is_active
            ],
        ]);
    }

    // ── POST /api/household/subscription/cancel ───────────────────────────────
   public function cancelSubscription(Request $request)
    {
        $subscription = Subscription::where('user_id', $request->user()->id)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json(['message' => 'No active subscription found.'], 404);
        }

        if ($subscription->payfast_token) {
            try {
                $payfastService = app(\App\Services\PayFastService::class);
                $payfastService->cancelSubscription($subscription->payfast_token);
            } catch (\Exception $e) {
                Log::warning('PayFast cancellation failed: ' . $e->getMessage());
            }
        }

        $accessEnd = $subscription->current_period_end
            ?? $subscription->trial_ends_at
            ?? now();

        $subscription->update([
            'status'       => 'cancelled',
            'cancelled_at' => now(),
            'ends_at'      => $accessEnd,
        ]);

        $this->notifyNode('POST', '/subscription-cancelled', [
            'userId'    => $subscription->user_id,
            'accessEnd' => $accessEnd->toIso8601String(),
        ]);

        return response()->json(['message' => 'Subscription cancelled successfully.']);
    }

    // ── GET /api/household/invoices ───────────────────────────────────────────
    public function invoices(Request $request)
    {
        $userId = $request->user()->id;
        $subscription = Subscription::where('user_id', $userId)->latest()->first();

        $invoices = Invoice::where('client_id', $userId)
            ->with([
                'payment:id,billing_period_start,billing_period_end',
                'channelSubscription:id,current_period_start,current_period_end',
            ])
            ->latest()
            ->paginate(20);

        return response()->json(['invoices' => $invoices]);
    }

    // ── GET /api/household/invoices/{id}/pdf ──────────────────────────────────
    public function invoicePdf(Request $request, $id)
    {
        $subscription = Subscription::where('user_id', $request->user()->id)->latest()->first();
        $invoice = Invoice::where('id', $id)
            ->where('subscription_id', $subscription?->id)
            ->firstOrFail();

        $pdf = Pdf::loadView('invoices.pdf', compact('invoice'));
        return $pdf->download("invoice-{$invoice->invoice_number}.pdf");

    }

    // ── GET /api/household/invoices/{id}/print ────────────────────────────────
    public function invoicePrint(Request $request, $id)
    {
        $subscription = Subscription::where('user_id', $request->user()->id)->latest()->first();
        $invoice = Invoice::where('id', $id)
            ->where('subscription_id', $subscription?->id)
            ->firstOrFail();

        // TODO: $pdf = PDF::loadView('invoices.pdf', compact('invoice'));
        // return $pdf->stream("invoice-{$invoice->invoice_number}.pdf");

        return response()->json(['message' => 'Print not yet implemented.'], 501);
    }

    // ── POST /api/household/invoices/{id}/send ────────────────────────────────
   public function invoiceSend(Request $request, $id)
    {
        $subscription = Subscription::where('user_id', $request->user()->id)->latest()->first();

        $invoice = Invoice::where('id', $id)
            ->where(function ($q) use ($subscription) {
                $q->where('subscription_id', $subscription?->id)
                ->orWhere('client_id', request()->user()->id);
            })
            ->firstOrFail();

        $invoice->load(['client', 'payment.subscription', 'channelSubscription.channel', 'channelSubscriptionPayment']);
        try {
            Mail::to($request->user()->email)->send(new InvoiceMail($invoice));
            return response()->json(['message' => 'Invoice sent to ' . $request->user()->email]);
        } catch (\Exception $e) {
            Log::error('InvoiceMail failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to send invoice.'], 500);
        }
    }


    public function paymentUrl(Request $request)
    {
        $user         = $request->user();
        $subscription = Subscription::where('user_id', $user->id)->latest()->first();

        if (!$subscription) {
            return response()->json(['message' => 'No subscription found.'], 404);
        }

        if ($subscription->payfast_token) {
            return response()->json([
                'url'  => 'https://www.payfast.co.za/eng/recurring/update/' . $subscription->payfast_token,
                'type' => 'update',
            ]);
        }

        $merchantReference = $subscription->merchant_reference ?? ('HH-' . $user->id . '-' . time());

        if (!$subscription->merchant_reference) {
            $subscription->update(['merchant_reference' => $merchantReference]);
        }

        $payfast = new \App\Services\PayFastService();
        
        $channel = $user->employee?->channels()->first();
        $amountPerHousehold = BillingService::unitPrice($channel?->amount_per_household);

        $fields = $payfast->buildSubscriptionFields([
            'billing_date'         => $subscription->trial_ends_at->format('Y-m-d'),
            'name_first'           => explode(' ', $user->name)[0],
            'name_last'            => explode(' ', $user->name, 2)[1] ?? '',
            'email_address'        => $user->email,
            'cell_number'          => $this->formatPhone($user->phone ?? ''),
            'm_payment_id'         => $merchantReference,
            'item_name'            => 'Echo Link Community Protection',
            'item_description'     => "14-day free trial then R{$amountPerHousehold} per month neighbourhood watch subscription",
            'custom_str1'          => (string) $user->id,
            'amount_per_household' => $channel?->amount_per_household,
        ]);

        return response()->json(['type' => 'new', 'fields' => $fields, 'action' => 'https://www.payfast.co.za/eng/process']);
    } 

    // ── Private: format phone number for PayFast (10 digits, starting with 0) ─────
    private function formatPhone(string $phone): string
    {
        // Strip everything except digits
        $digits = preg_replace('/\D/', '', $phone);

        // Convert +27 or 27 prefix → 0
        if (str_starts_with($digits, '27') && strlen($digits) === 11) {
            $digits = '0' . substr($digits, 2);
        }

        // Must be exactly 10 digits starting with 0
        if (strlen($digits) !== 10 || !str_starts_with($digits, '0')) {
            return ''; // return empty rather than send invalid — PayFast ignores blank cell_number
        }

        return $digits;
    }

   public function reactivate(Request $request)
    {
        $user         = $request->user();
        $subscription = Subscription::where('user_id', $user->id)->latest()->first();

        if (!$subscription) {
            return response()->json(['message' => 'No subscription found.'], 404);
        }

        if ($subscription->status !== 'cancelled') {
            return response()->json(['message' => 'Subscription is not cancelled.'], 400);
        }

        $now                 = now();
        $trialStillValid     = $subscription->trial_ends_at && $subscription->trial_ends_at->isFuture();
        $originalTrialEndsAt = $subscription->trial_ends_at; // ← capture before update nulls it

        $merchantReference = 'HH-' . $user->id . '-' . time();


        $subscription->update([
            'status'               => $trialStillValid ? 'trialing' : 'active',
            'payfast_token'        => null,
            'merchant_reference'   => $merchantReference,
            'gateway_status'       => null,
            'cancelled_at'         => null,
            'ends_at'              => null,
            'sos_suspended_at'     => null,
            'trial_ends_at'        => $trialStillValid ? $originalTrialEndsAt : null,
            'current_period_start' => $trialStillValid ? null : $now,
            'current_period_end'   => $trialStillValid ? null : $now->copy()->addMonth(),
        ]);

        $payfast = new \App\Services\PayFastService();
        $channel = $user->employee?->channels()->first();
        $amountPerHousehold = BillingService::unitPrice($channel?->amount_per_household);
        $formattedAmount = number_format($amountPerHousehold, 2, '.', '');

        $fields = $payfast->buildSubscriptionFields([
            'billing_date'         => $trialStillValid
                    ? $originalTrialEndsAt->format('Y-m-d')
                    : $now->format('Y-m-d'),
            'name_first'           => explode(' ', $user->name)[0],
            'name_last'            => explode(' ', $user->name, 2)[1] ?? '',
            'email_address'        => $user->email,
            'cell_number'          => $this->formatPhone($user->phone ?? ''),
            'm_payment_id'         => $merchantReference,
            'item_name'            => 'Echo Link Community Protection',
            'item_description'     => "R{$amountPerHousehold} per month neighbourhood watch reactivation",
            'custom_str1'          => (string) $user->id,
            'amount_per_household' => $channel?->amount_per_household,
        ], $trialStillValid ? '0.00' : $formattedAmount);

        return response()->json([
            'type'   => 'new',
            'fields' => $fields,
            'action' => 'https://www.payfast.co.za/eng/process',
        ]);
    }

    public function payNow(Request $request)
    {
        $user         = $request->user();
        $subscription = Subscription::where('user_id', $user->id)
            ->where('status', 'past_due')
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json(['message' => 'No overdue subscription found.'], 404);
        }

        if (!$subscription->payfast_token) {
            return response()->json(['message' => 'No payment method on file.'], 400);
        }

        // Prevent double charge with DB lock
        $locked = Cache::lock('pay_now_' . $user->id, 30);
        if (!$locked->get()) {
            return response()->json(['message' => 'Payment already in progress.'], 429);
        }

        try {
            $payfast = new \App\Services\PayFastService();
            $success = $payfast->chargeAdhoc($subscription->payfast_token, 80.00);

            if (!$success) {
                return response()->json(['message' => 'Payment failed. Please update your card details.'], 400);
            }

            return response()->json(['message' => 'Payment successful.']);
        } finally {
            $locked->release();
        }
    }


    public function payNowOnetime(Request $request)
    {
        $user         = $request->user();
        $subscription = Subscription::where('user_id', $user->id)
            ->whereIn('status', ['past_due', 'trialing', 'cancelled'])
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json(['message' => 'No subscription found.'], 404);
        }

        $merchantReference = 'OT-' . $user->id . '-' . time();

        $payfast = new \App\Services\PayFastService();
        $channel = $user->employee?->channels()->first();
        $fields  = $payfast->buildOneTimeFields([
            'name_first'       => explode(' ', $user->name)[0],
            'name_last'        => explode(' ', $user->name, 2)[1] ?? '',
            'email_address'    => $user->email,
            'cell_number'      => $this->formatPhone($user->phone ?? ''),
            'm_payment_id'     => $merchantReference,
            'item_name'        => 'Echo Link Community Protection',
            'item_description' => 'Monthly neighbourhood watch subscription',
            'custom_str1'      => (string) $user->id,
            'amount_per_household' => $channel?->amount_per_household,
        ]);

        return response()->json([
            'type'   => 'onetime',
            'fields' => $fields,
            'action' => 'https://www.payfast.co.za/eng/process',
        ]);
    }

}
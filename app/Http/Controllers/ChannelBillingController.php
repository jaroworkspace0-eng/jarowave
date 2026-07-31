<?php

namespace App\Http\Controllers;

use App\Mail\EstateBillingInviteMail;
use App\Mail\EstatePaymentApprovedMail;
use App\Mail\EstatePaymentRejectedMail;
use App\Models\Channel;
use App\Models\ChannelBillingContact;
use App\Models\ChannelSubscription;
use App\Models\ChannelSubscriptionPayment;
use App\Models\Employee;
use App\Models\User;
use App\Services\ChannelBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ChannelBillingController extends Controller
{
    public function __construct(protected ChannelBillingService $billingService) {}

    // -------------------------------------------------------------------------
    // Billing Contact
    // -------------------------------------------------------------------------

    /**
     * Create billing contact user and link to channel.
     * Called when admin creates/updates a residential channel with bulk billing.
     */
    public function storeBillingContact(Request $request, Channel $channel)
    {
        $validated = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:15',
        ]);

        DB::transaction(function () use ($validated, $channel) {
            // Create user account for billing contact
            $user = User::create([
                'name'                => $validated['name'],
                'email'               => $validated['email'],
                'phone'               => $validated['phone'] ?? null,
                'password'            => Hash::make(Str::random(16)), // temp password — invite email will handle reset
                'role'                => 'estate_billing',
                'is_estate'           => true,
                'subscription_status' => 'active',
            ]);

            // Link user to client via employees table
            $employee = Employee::create([
                'user_id'   => $user->id,
                'client_id' => $channel->client_id,
            ]);

            // Deactivate any existing billing contact for this channel
            ChannelBillingContact::where('channel_id', $channel->id)
                ->update(['is_active' => false]);

            // Create billing contact record
            ChannelBillingContact::create([
                'channel_id' => $channel->id,
                'user_id'    => $user->id,
                'is_active'  => true,
            ]);

            // TODO: Send invite email with password reset link
            Mail::to($user->email)->queue(new EstateBillingInviteMail($user, $channel));
        });

        return response()->json([
            'success' => true,
            'message' => 'Billing contact created successfully.',
        ]);
    }

    /**
     * Update billing contact details.
     */
    public function updateBillingContact(Request $request, Channel $channel)
    {
        $validated = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email',
            'phone' => 'nullable|string|max:15',
        ]);

        $contact = ChannelBillingContact::where('channel_id', $channel->id)
            ->where('is_active', true)
            ->firstOrFail();

        $contact->user->update([
            'name'  => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Billing contact updated successfully.',
        ]);
    }

    // -------------------------------------------------------------------------
    // Channel Subscription
    // -------------------------------------------------------------------------

    /**
     * Get current billing summary for a channel.
     * Returns opted-in count, amount due, period, and status.
     */
    public function summary(Channel $channel)
    {
        $channelSubscription = $this->billingService->resolveActiveChannelSubscription($channel);
        $this->billingService->refreshChannelSubscription($channelSubscription);
        $channelSubscription->refresh();

        return response()->json([
            'success'              => true,
            'channel_subscription' => $channelSubscription,
            'household_count'      => $channelSubscription->household_count,
            'amount_per_household' => $channelSubscription->amount_per_household,
            'total_amount'         => $channelSubscription->total_amount,
            'status'               => $channelSubscription->status,
            'current_period_start' => $channelSubscription->current_period_start,
            'current_period_end'   => $channelSubscription->current_period_end,
        ]);
    }

    // -------------------------------------------------------------------------
    // Opt-In / Opt-Out
    // -------------------------------------------------------------------------

    /**
     * Household opts into estate bulk billing.
     */
    public function optIn(Request $request, Channel $channel)
    {
        $user = $request->user();

        // Confirm user belongs to this channel
        $inChannel = $user->employee?->channels()->where('channels.id', $channel->id)->exists();

        if (!$inChannel) {
            return response()->json(['success' => false, 'message' => 'You do not belong to this channel.'], 403);
        }

        try {
            $this->billingService->optInHousehold($user, $channel);
        } catch (\Exception $e) {
            // Intercepts the "outstanding balance" exception from the service
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400); // 400 Bad Request indicates a client-side validation/business rule failure
        }

        return response()->json([
            'success' => true,
            'message' => 'You have joined estate billing. Your individual subscription has been cancelled.',
        ]);
    }

    /**
     * Household opts out of estate bulk billing.
     */
    public function optOut(Request $request, Channel $channel)
    {
        $user = $request->user();

        try {
            $this->billingService->optOutHousehold($user, $channel);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'You have left estate billing. An individual subscription has been created for you.',
        ]);
    }


    public function removeHousehold(Request $request, Channel $channel)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::findOrFail($request->user_id);

        $this->billingService->optOutHousehold($user, $channel);

        return response()->json([
            'success' => true,
            'message' => "{$user->name} has been removed from estate billing.",
        ]);
    }

    // -------------------------------------------------------------------------
    // EFT Payment
    // -------------------------------------------------------------------------

    /**
     * Admin marks an estate EFT payment as paid.
     */
    public function markEftPaid(Request $request, Channel $channel)
    {

         if ($channel->billing_model !== 'bulk') {
            return response()->json([
                'success' => false,
                'message' => 'This channel is not on estate/bulk billing. Process payment via individual subscriber billing instead.',
            ], 422);
        }

        
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'note'   => 'required|string|max:255',
            'proof'  => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        $channelSubscription = $this->billingService->resolveActiveChannelSubscription($channel);

        $proofPath = $request->file('proof')->store('eft-proofs/channel', 'public');

        $payment = $this->billingService->markEftPaid(
            channelSubscription: $channelSubscription,
            data:                $request->only(['note']),
            proofPath:           $proofPath,
            ipAddress:           $request->ip(),
        );

        return response()->json([
            'success' => true,
            'message' => 'EFT proof submitted. An Echo Link admin will review and activate your households shortly.',
            'payment' => $payment,
        ]);
    }

    // -------------------------------------------------------------------------
    // Payment History
    // -------------------------------------------------------------------------

    /**
     * List payment history for a channel subscription.
     */
    public function paymentHistory(Channel $channel)
    {
        $payments = ChannelSubscriptionPayment::whereHas('channelSubscription', function ($q) use ($channel) {
                $q->where('channel_id', $channel->id);
            })
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json([
            'success'  => true,
            'payments' => $payments,
        ]);
    }

    /**
     * List opted-in households for a channel.
     */
    public function optedInHouseholds(Channel $channel)
    {
        $channelSubscription = ChannelSubscription::where('channel_id', $channel->id)
            ->whereIn('status', ['pending', 'active'])
            ->latest()
            ->first();

        if (!$channelSubscription) {
            return response()->json(['success' => true, 'households' => []]);
        }

        $households = $channelSubscription->subscriptions()
            ->where('cancellation_reason', 'estate_optin')
            ->with('user:id,name,email,phone,subscription_status,unit_number')
            ->get()
            ->map(fn($sub) => $sub->user);

        return response()->json([
            'success'    => true,
            'households' => $households,
        ]);
    }


    public function approveEftPayment(Request $request, ChannelSubscriptionPayment $payment)
    {
        $this->billingService->approveEftPayment($payment, $request->ip());

        

        return response()->json([
            'success' => true,
            'message' => 'Payment approved. All opted-in households have been activated.',
        ]);
    }

    public function rejectEftPayment(Request $request, ChannelSubscriptionPayment $payment)
    {
        $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        $this->billingService->rejectEftPayment($payment, $request->reason);

        return response()->json([
            'success' => true,
            'message' => 'Payment rejected.',
        ]);
    }

    public function pendingEftPayments()
    {
        $payments = ChannelSubscriptionPayment::with([
                'channelSubscription.channel.billingContact.user',
            ])
            ->whereIn('status', ['pending_review', 'paid', 'rejected'])
            ->orderByRaw("FIELD(status, 'pending_review', 'paid', 'rejected')")
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json(['success' => true, 'payments' => $payments]);
    }


    /**
     * Initiate a once-off PayFast payment for the channel's current
     * outstanding bulk billing amount.
     */
    public function payNow(Request $request, Channel $channel)
    {
        if ($channel->billing_model !== 'bulk') {
            return response()->json([
                'success' => false,
                'message' => 'This channel is not on estate/bulk billing. Process payment via individual subscriber billing instead.',
            ], 422);
        }

        $channelSubscription = $this->billingService->resolveActiveChannelSubscription($channel);

        if ($channelSubscription->isCancelled()) {
            return response()->json(['message' => 'This subscription has been cancelled.'], 400);
        }

        if ($channelSubscription->isActive()) {
            return response()->json(['message' => 'This billing period is already paid.'], 400);
        }

        $contact = ChannelBillingContact::where('channel_id', $channel->id)
            ->where('is_active', true)
            ->with('user')
            ->first();

        if (!$contact) {
            return response()->json(['message' => 'No billing contact on file for this channel.'], 400);
        }

        $user = $contact->user;

        // Prevent duplicate charge attempts while one is in flight
        $locked = Cache::lock('channel_pay_now_' . $channel->id, 30);
        if (!$locked->get()) {
            return response()->json(['message' => 'Payment already in progress.'], 429);
        }

        try {
            $merchantReference = 'EST-' . $channel->id . '-' . time();

            // Persist the pending payment row now — the ITN webhook resolves the
            // payment via ChannelSubscriptionPayment::where('merchant_reference', ...),
            // so without this the incoming ITN would find nothing to mark paid.
            $payment = ChannelSubscriptionPayment::create([
                'channel_subscription_id' => $channelSubscription->id,
                'amount'                  => $channelSubscription->total_amount,
                'household_count'         => $channelSubscription->household_count,
                'amount_per_household'    => $channelSubscription->amount_per_household,
                'payment_method'          => 'payfast',
                'status'                  => 'pending',
                'merchant_reference'      => $merchantReference,
                'ip_address'              => $request->ip(),
            ]);

            $formattedAmount = number_format((float) $channelSubscription->total_amount, 2, '.', '');

            $payfast = new \App\Services\PayFastService();
            $fields = $payfast->buildOneTimeFields([
                'name_first'            => explode(' ', $user->name)[0],
                'name_last'             => explode(' ', $user->name, 2)[1] ?? '',
                'email_address'         => $user->email,
                'cell_number'           => $this->formatPhone($user->phone ?? ''),
                'm_payment_id'          => $merchantReference,
                'item_name'             => 'Echo Link Estate Billing',
                'item_description'      => "R{$formattedAmount} estate billing for {$channelSubscription->household_count} households",
                'custom_str1'           => (string) $channel->id,
                'amount_per_household'  => $channelSubscription->total_amount,
            ]);

            return response()->json([
                'type'   => 'estate',
                'fields' => $fields,
                'action' => config('payfast.base_url', 'https://www.payfast.co.za/eng/process'),
            ]);
        } finally {
            $locked->release();
        }
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
}
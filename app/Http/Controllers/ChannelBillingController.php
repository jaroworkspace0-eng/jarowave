<?php

namespace App\Http\Controllers;

use App\Mail\EstateBillingInviteMail;
use App\Models\Channel;
use App\Models\ChannelBillingContact;
use App\Models\ChannelSubscription;
use App\Models\ChannelSubscriptionPayment;
use App\Models\Employee;
use App\Models\User;
use App\Services\ChannelBillingService;
use Illuminate\Http\Request;
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

        $this->billingService->optInHousehold($user, $channel);

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

        $this->billingService->optOutHousehold($user, $channel);

        return response()->json([
            'success' => true,
            'message' => 'You have left estate billing. An individual subscription has been created for you.',
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
            'success'   => true,
            'message'   => 'Estate EFT payment recorded. All opted-in households activated.',
            'payment'   => $payment,
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
}
<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\HouseholdInvite;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Services\PayFastService;

class HouseholdController extends Controller
{
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

    // ── POST /api/household/register ──────────────────────────────────────────
    public function register(Request $request)
    {
        $request->headers->set('Accept', 'application/json');
 
        $request->validate([
            'invite_token' => 'required|string',
            'name'         => 'required|string|max:255',
            'email'        => 'required|email|unique:users,email',
            'phone'        => 'nullable|string|max:20',
            'password'     => 'required|string|min:8|confirmed',
            'gateway'      => 'required|in:payfast,ozow',
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
 
        // Generate unique merchant reference for this subscription
        $merchantReference = 'HH-' . $user->id . '-' . time();
 
        // Create subscription record (trialing — PayFast will activate on first charge)
        $subscription = Subscription::create([
            'user_id'              => $user->id,
            'client_id'            => $invite->client_id,
            'status'               => 'trialing',
            'gateway'              => $request->gateway,
            'plan'                 => null,
            'billing_cycle'        => 'monthly',
            'price'                => 8000,
            'trial_ends_at'        => now()->addDays(30),
            'merchant_reference'   => $merchantReference,
        ]);
 
        $token = $user->createToken('household-token')->plainTextToken;
 
        // Build PayFast payment URL
        $redirectUrl = $this->initiatePayment($user, $request->gateway, $merchantReference);
 
        return response()->json([
            'token'        => $token,
            'user'         => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'redirect_url' => $redirectUrl,
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
                'item_description' => '30-day free trial then R80/month neighbourhood watch subscription',
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
            ->with('client.user')
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

        return response()->json([
            'subscription' => [
                'status'               => $subscription->status,
                'plan'                 => $subscription->plan,
                'gateway'              => $subscription->gateway,
                'billing_cycle'        => $subscription->billing_cycle,
                'current_period_start' => $subscription->current_period_start,
                'current_period_end'   => $subscription->current_period_end,
                'ends_at'              => $subscription->ends_at,
                'days_left_in_trial'   => $subscription->daysLeftInTrial(),
                'watch_group'          => $subscription->client ? [
                    'organisation_name' => $subscription->client->user->organisation_name,
                ] : null,
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

        $subscription->update([
            'status'  => 'cancelled',
            'ends_at' => $subscription->current_period_end,
        ]);

        // TODO: cancel recurring billing on PayFast/Ozow

        return response()->json(['message' => 'Subscription cancelled successfully.']);
    }

    // ── GET /api/household/invoices ───────────────────────────────────────────
    public function invoices(Request $request)
    {
        $subscription = Subscription::where('user_id', $request->user()->id)->latest()->first();

        if (!$subscription) {
            return response()->json(['invoices' => []]);
        }

        $invoices = Invoice::where('subscription_id', $subscription->id)
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

        // TODO: $pdf = PDF::loadView('invoices.pdf', compact('invoice'));
        // return $pdf->download("invoice-{$invoice->invoice_number}.pdf");

        return response()->json(['message' => 'PDF generation not yet implemented.'], 501);
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
            ->where('subscription_id', $subscription?->id)
            ->firstOrFail();
        // TODO: Mail::to($request->user()->email)->send(new InvoiceMail($invoice));

        return response()->json(['message' => 'Invoice sent to ' . $request->user()->email]);
    }

    
}
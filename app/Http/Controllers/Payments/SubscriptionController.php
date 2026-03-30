<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\Client;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    // GET /api/subscriptions — admin sees all, client sees their own
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            $subscriptions = Subscription::with(['client.user'])
                ->orderBy('created_at', 'desc')
                ->paginate(20);
        } else {
            $client = Client::where('user_id', $user->id)->firstOrFail();
            $subscriptions = Subscription::with(['client.user'])
                ->where('client_id', $client->id)
                ->orderBy('created_at', 'desc')
                ->paginate(20);
        }

        return response()->json(['subscriptions' => $subscriptions]);
    }

    // GET /api/subscriptions/{subscription}
    public function show(Subscription $subscription)
    {
        $this->authorise($subscription);

        return response()->json([
            'subscription' => $subscription->load(['client.user', 'payments']),
        ]);
    }

    // PATCH /api/subscriptions/{subscription}/cancel
    public function cancel(Subscription $subscription)
    {
        $this->authorise($subscription);

        $subscription->update([
            'status'       => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Subscription cancelled successfully.',
        ]);
    }

    // PATCH /api/subscriptions/{subscription}/upgrade
    public function upgrade(Request $request, Subscription $subscription)
    {
        $validated = $request->validate([
            'plan'          => ['required', 'in:basic,standard,premium'],
            'billing_cycle' => ['required', 'in:monthly,annual'],
        ]);

        $subscription->update([
            'plan'          => $validated['plan'],
            'billing_cycle' => $validated['billing_cycle'],
        ]);

        return response()->json([
            'success'      => true,
            'message'      => 'Subscription updated successfully.',
            'subscription' => $subscription->fresh(),
        ]);
    }

    // Private — ensure non-admins can only see their own subscription
    private function authorise(Subscription $subscription): void
    {
        $user = auth()->user();

        if ($user->role === 'admin') return;

        $client = Client::where('user_id', $user->id)->firstOrFail();

        abort_if($subscription->client_id !== $client->id, 403, 'Unauthorised');
    }
}
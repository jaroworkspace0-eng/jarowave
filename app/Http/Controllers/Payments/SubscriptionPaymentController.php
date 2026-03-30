<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPayment;
use App\Models\Client;
use App\Models\Subscription;
use Illuminate\Http\Request;

class SubscriptionPaymentController extends Controller
{
    // GET /api/payments — all payments for the authenticated client
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            $payments = SubscriptionPayment::with(['subscription.client.user'])
                ->orderBy('created_at', 'desc')
                ->paginate(20);
        } else {
            $client = Client::where('user_id', $user->id)->firstOrFail();

            $payments = SubscriptionPayment::whereHas('subscription', function ($q) use ($client) {
                    $q->where('client_id', $client->id);
                })
                ->orderBy('created_at', 'desc')
                ->paginate(20);
        }

        return response()->json(['payments' => $payments]);
    }

    // GET /api/payments/{payment}
    public function show(Request $request, SubscriptionPayment $payment)
    {
        $this->authorise($payment);

        return response()->json([
            'payment' => $payment->load(['subscription.client.user']),
        ]);
    }

    // Private
    private function authorise(SubscriptionPayment $payment): void
    {
        $user = auth()->user();

        if ($user->role === 'admin') return;

        $client = Client::where('user_id', $user->id)->firstOrFail();

        abort_if(
            $payment->subscription->client_id !== $client->id,
            403,
            'Unauthorised'
        );
    }
}
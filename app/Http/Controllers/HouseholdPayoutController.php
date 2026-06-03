<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\BankDetail;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;

class HouseholdPayoutController extends Controller
{
    // GET /api/households
    // Returns all households that belong to the logged-in client's watch group.
    // The Vue page uses this to show the household breakdown table and active/pending/failed counts.
    public function households(Request $request)
    {
        $user   = $request->user();
        $client = Client::where('user_id', $user->id)->firstOrFail();

        // Households are users with role='household' scoped to this client.
        // We join through subscriptions so we can surface payment status per household.
        $households = User::where('role', 'household')
            ->whereHas('subscription', function ($q) use ($client) {
                $q->where('client_id', $client->id);
            })
            ->with([
                'subscription' => fn($q) => $q->where('client_id', $client->id)->latest(),
            ])
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        // Shape the response to match what the Vue table expects:
        // { id, name, address, status, created_at }
        $mapped = $households->through(function (User $hh) {
            $sub    = $hh->subscription;
            $status = match (true) {
                $sub === null                        => 'pending',
                $sub->status === 'active'            => 'active',
                $sub->status === 'past_due'          => 'failed',
                $sub->status === 'cancelled'         => 'failed',
                default                              => 'pending',
            };

            return [
                'id'         => $hh->id,
                'name'       => $hh->name,
                'address'    => trim(implode(', ', array_filter([
                    $hh->address_line_1,
                    $hh->complex_name,
                    $hh->suburb,
                ]))),
                'status'     => $status,
                'created_at' => $hh->created_at,
            ];
        });

        return response()->json(['households' => $mapped]);
    }

    // GET /api/bank-details
    public function show(Request $request)
    {
        $user   = $request->user();
        $client = Client::where('user_id', $user->id)->firstOrFail();

        $bankDetails = BankDetail::where('client_id', $client->id)->first();

        return response()->json(['bank_details' => $bankDetails]);
    }

    // POST /api/bank-details
    // Creates or updates (upserts) bank details for the logged-in client.
    public function store(Request $request)
    {
        $user   = $request->user();
        $client = Client::where('user_id', $user->id)->firstOrFail();

        $validated = $request->validate([
            'bank_name'      => 'required|string|max:100',
            'account_holder' => 'required|string|max:150',
            'account_number' => 'required|string|max:30',
            'account_type'   => 'required|in:Cheque,Savings,Transmission',
            'branch_code'    => 'required|string|max:10',
        ]);

        $bankDetails = BankDetail::updateOrCreate(
            ['client_id' => $client->id],
            $validated,
        );

        return response()->json([
            'bank_details' => $bankDetails,
            'message'      => 'Bank details saved successfully.',
        ]);
    }
}
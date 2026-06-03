<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\BankDetail;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;

class HouseholdPayoutController extends Controller
{
    public function households(Request $request)
    {
        $user   = $request->user();
        $client = Client::where('user_id', $user->id)->firstOrFail();

        // subscriptions.client_id = clients.id (3), NOT users.id (61)
        // subscriptions.user_id   = household's users.id
        $households = User::where('role', 'household')
            ->whereHas('subscription', function ($q) use ($client) {
                $q->where('client_id', $client->id);
            })
            ->with([
                'subscription' => fn($q) => $q->where('client_id', $client->id)->latest(),
            ])
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        $mapped = $households->through(function (User $hh) {
            $sub    = $hh->subscription;
            $status = match (true) {
                $sub === null                => 'pending',
                $sub->status === 'active'    => 'active',
                $sub->status === 'trialing'  => 'pending',
                $sub->status === 'past_due'  => 'failed',
                $sub->status === 'cancelled' => 'failed',
                default                      => 'pending',
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

    public function show(Request $request)
    {
        $user        = $request->user();
        $bankDetails = BankDetail::where('client_id', $user->id)->first();
        return response()->json(['bank_details' => $bankDetails]);
    }

    public function store(Request $request)
    {
        $user      = $request->user();
        $validated = $request->validate([
            'bank_name'      => 'required|string|max:100',
            'account_holder' => 'required|string|max:150',
            'account_number' => 'required|string|max:30',
            'account_type'   => 'required|in:Cheque,Savings,Transmission',
            'branch_code'    => 'required|string|max:10',
        ]);

        $bankDetails = BankDetail::updateOrCreate(
            ['client_id' => $user->id],
            $validated,
        );

        return response()->json([
            'bank_details' => $bankDetails,
            'message'      => 'Bank details saved successfully.',
        ]);
    }
}

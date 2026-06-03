<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Payout;
use Illuminate\Http\Request;

class PayoutController extends Controller
{
    // GET /api/payouts
    public function index(Request $request)
    {
        $user   = $request->user();
        $client = Client::where('user_id', $user->id)->firstOrFail();

        $payouts = Payout::where('client_id', $client->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json(['payouts' => $payouts]);
    }
}
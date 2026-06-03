<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use Illuminate\Http\Request;

class PayoutController extends Controller
{
    // GET /api/payouts
    public function index(Request $request)
    {
        $user = $request->user();

        $payouts = Payout::where('client_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json(['payouts' => $payouts]);
    }
}
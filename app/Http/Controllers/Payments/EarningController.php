<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Earning;
use App\Models\Client;
use Illuminate\Http\Request;

class EarningController extends Controller
{
    // GET /api/earnings
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            $earnings = Earning::with(['client', 'resident', 'payment'])
                ->orderBy('created_at', 'desc')
                ->paginate(20);
        } else {
            // earnings.client_id = users.id (the watch group owner's user id directly)
            $earnings = Earning::with(['resident', 'payment'])
                ->where('client_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->paginate(20);
        }

        return response()->json(['earnings' => $earnings]);
    }

    // GET /api/earnings/summary
    public function summary(Request $request)
    {
        $user = $request->user();

        // earnings.client_id stores the user.id of the watch group owner directly
        $q = Earning::where('client_id', $user->id);

        // Amounts stored in cents (8000 = R80.00) — divide by 100 for rands
        return response()->json([
            'summary' => [
                'pending_amount'     => round($q->clone()->where('status', 'pending')->sum('earned_amount') / 100, 2),
                'paid_amount'        => round($q->clone()->where('status', 'paid')->sum('earned_amount') / 100, 2),
                'total_earned'       => round($q->clone()->sum('earned_amount') / 100, 2),
                'platform_collected' => round($q->clone()->sum('platform_amount') / 100, 2),
                'total_residents'    => $q->clone()->distinct('resident_id')->count('resident_id'),
                'pending_count'      => $q->clone()->where('status', 'pending')->count(),
                'paid_count'         => $q->clone()->where('status', 'paid')->count(),
                'commission_rate'    => (int) ($q->clone()->value('commission_percentage') ?? 60),
            ],
        ]);
    }

    // GET /api/earnings/{earning}
    public function show(Earning $earning)
    {
        $this->authorise($earning);
        return response()->json([
            'earning' => $earning->load(['resident', 'payment']),
        ]);
    }

    private function authorise(Earning $earning): void
    {
        $user = auth()->user();
        if ($user->role === 'admin') return;
        abort_if($earning->client_id !== $user->id, 403, 'Unauthorised');
    }
}
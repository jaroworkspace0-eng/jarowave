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
            $earnings = Earning::with(['client.user', 'resident', 'payment'])
                ->orderBy('created_at', 'desc')
                ->paginate(20);
        } else {
            $client = Client::where('user_id', $user->id)->firstOrFail();

            $earnings = Earning::with(['resident', 'payment'])
                ->where('client_id', $client->id)
                ->orderBy('created_at', 'desc')
                ->paginate(20);
        }

        return response()->json(['earnings' => $earnings]);
    }

    // GET /api/earnings/summary
    public function summary(Request $request)
    {
        $user   = $request->user();
        $client = Client::where('user_id', $user->id)->firstOrFail();

        $earnings = Earning::where('client_id', $client->id);

        return response()->json([
            'summary' => [
                // Raw numeric values — Vue's formatRands() adds the R prefix
                'pending_amount'  => (float) $earnings->clone()->where('status', 'pending')->sum('earned_amount'),
                'paid_amount'     => (float) $earnings->clone()->where('status', 'paid')->sum('earned_amount'),
                'total_earned'    => (float) $earnings->clone()->sum('earned_amount'),
                'total_residents' => $earnings->clone()->distinct('resident_id')->count('resident_id'),
                'pending_count'   => $earnings->clone()->where('status', 'pending')->count(),
            ],
        ]);
    }

    // GET /api/earnings/{earning}
    public function show(Earning $earning)
    {
        $this->authorise($earning);

        return response()->json([
            'earning' => $earning->load(['client.user', 'resident', 'payment']),
        ]);
    }

    private function authorise(Earning $earning): void
    {
        $user = auth()->user();

        if ($user->role === 'admin') return;

        $client = Client::where('user_id', $user->id)->firstOrFail();

        abort_if($earning->client_id !== $client->id, 403, 'Unauthorised');
    }
}
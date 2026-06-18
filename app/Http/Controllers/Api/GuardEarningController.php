<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankDetail;
use App\Models\Earning;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuardEarningController extends Controller
{
    /**
     * Summary + earnings list + bank details status for the authenticated guard.
     * GET /api/guard/earnings
     */
    public function index(Request $request): JsonResponse
    {
        $guard = $request->user();

        $earnings = Earning::where('resident_id', $guard->id)
            ->whereNull('client_id') // gate guard earnings only, never resident/client rows
            ->with(['channelSubscriptionPayment.channelSubscription.channel'])
            ->orderByDesc('period_end')
            ->get();

        $pendingTotal = $earnings->whereIn('status', ['pending', 'approved'])->sum('earned_amount');
        $paidTotal    = $earnings->where('status', 'paid')->sum('earned_amount');

        $hasBankDetails = BankDetail::where('user_id', $guard->id)->exists();

        $entries = $earnings->map(function (Earning $earning) {
            $channel = $earning->channelSubscriptionPayment?->channelSubscription?->channel;

            return [
                'id'                => $earning->id,
                'channel_name'      => $channel?->name,
                'amount'            => $earning->earned_amount,
                'amount_rands'      => $earning->earned_amount_in_rands,
                'status'            => $earning->status,
                'period_start'      => $earning->period_start?->toDateString(),
                'period_end'        => $earning->period_end?->toDateString(),
                'payout_at'         => $earning->payout_at?->toDateString(),
                'payout_reference'  => $earning->payout_reference,
            ];
        });

        return response()->json([
            'summary' => [
                'pending_total'       => $pendingTotal,
                'pending_total_rands' => 'R' . number_format($pendingTotal, 2),
                'paid_total'          => $paidTotal,
                'paid_total_rands'    => 'R' . number_format($paidTotal, 2),
                'has_bank_details'    => $hasBankDetails,
            ],
            'earnings' => $entries->values(),
        ]);
    }
}
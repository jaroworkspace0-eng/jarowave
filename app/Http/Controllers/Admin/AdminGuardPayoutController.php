<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankDetail;
use App\Models\Earning;
use App\Models\Payout;
use App\Models\User;
use App\Mail\PayoutProcessedMail;
use App\Mail\NoBankDetailsMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class AdminGuardPayoutController extends Controller
{
    // ── GET /api/admin/payouts/guards ─────────────────────────────────────────
    // Returns all guards that have earnings, with their pending totals
    // Supports: ?month=6&year=2026&status=pending
    public function guards(Request $request)
    {
        $this->requireAdmin();

        $q = Earning::query()
            ->whereNotNull('user_id')
            ->select(
                'user_id',
                DB::raw('SUM(CASE WHEN status = "pending"  THEN earned_amount ELSE 0 END) as pending_amount'),
                DB::raw('SUM(CASE WHEN status = "paid"     THEN earned_amount ELSE 0 END) as paid_amount'),
                DB::raw('SUM(CASE WHEN status = "withheld" THEN earned_amount ELSE 0 END) as withheld_amount'),
                DB::raw('SUM(earned_amount) as total_amount'),
                DB::raw('COUNT(*) as earning_count'),
                DB::raw('SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending_count'),
                DB::raw('MIN(period_start) as earliest_period'),
                DB::raw('MAX(period_end) as latest_period')
            )
            ->groupBy('user_id');

        if ($request->filled('month') && $request->filled('year')) {
            $q->where(function ($query) use ($request) {
                $query->whereMonth('period_start', $request->integer('month'))
                    ->whereYear('period_start',  $request->integer('year'))
                    ->orWhereNull('period_start');
            });
        } elseif ($request->filled('year')) {
            $q->where(function ($query) use ($request) {
                $query->whereYear('period_start', $request->integer('year'))
                    ->orWhereNull('period_start');
            });
        }

        if ($request->input('status', 'pending') === 'pending') {
            $q->having(DB::raw('SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END)'), '>', 0);
        }

        $rows = $q->get();

        $guards = $rows->map(function ($row) {
            $guard       = User::find($row->user_id);
            $bankDetails = BankDetail::where('user_id', $row->user_id)->first();

            return [
                'user_id'          => $row->user_id,
                'name'             => $guard?->name  ?? '—',
                'email'            => $guard?->email ?? '—',
                'pending_amount'   => round($row->pending_amount,  2),
                'paid_amount'      => round($row->paid_amount,     2),
                'withheld_amount'  => round($row->withheld_amount, 2),
                'total_amount'     => round($row->total_amount,    2),
                'earning_count'    => (int) $row->earning_count,
                'pending_count'    => (int) $row->pending_count,
                'earliest_period'  => $row->earliest_period,
                'latest_period'    => $row->latest_period,
                'has_bank_details' => $bankDetails !== null,
                'bank_details'     => $bankDetails ? [
                    'bank_name'      => $bankDetails->bank_name,
                    'account_holder' => $bankDetails->account_holder,
                    'account_number' => $bankDetails->account_number,
                    'account_type'   => $bankDetails->account_type,
                    'branch_code'    => $bankDetails->branch_code,
                ] : null,
            ];
        });

        $totals = [
            'total_pending'   => round($rows->sum('pending_amount'), 2),
            'total_paid'      => round($rows->sum('paid_amount'),    2),
            'total_guards'    => $rows->count(),
            'guards_no_bank'  => $guards->where('has_bank_details', false)->count(),
        ];

        return response()->json([
            'guards' => $guards,
            'totals' => $totals,
        ]);
    }

    // ── GET /api/admin/payouts/guards/{userId}/earnings ───────────────────────
    public function guardEarnings(Request $request, int $userId)
    {
        $this->requireAdmin();

        $q = Earning::where('user_id', $userId);

        if ($request->filled('month') && $request->filled('year')) {
            $q->where(function ($query) use ($request) {
                $query->whereMonth('period_start', $request->integer('month'))
                    ->whereYear('period_start',  $request->integer('year'))
                    ->orWhereNull('period_start');
            });
        } elseif ($request->filled('year')) {
            $q->where(function ($query) use ($request) {
                $query->whereYear('period_start', $request->integer('year'))
                    ->orWhereNull('period_start');
            });
        }

        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }

        $earnings = $q->orderBy('period_start', 'desc')->get()->map(fn($e) => [
            'id'                    => $e->id,
            'earned_amount'         => round($e->earned_amount, 2),
            'status'                => $e->status,
            'period_start'          => $e->period_start?->toDateTimeString(),
            'period_end'            => $e->period_end?->toDateTimeString(),
            'payout_at'             => $e->payout_at?->toDateTimeString(),
            'payout_reference'      => $e->payout_reference,
        ]);

        return response()->json(['earnings' => $earnings]);
    }

    // ── POST /api/admin/payouts/guards/process ────────────────────────────────
    // Body: { user_id, earning_ids: [...], eft_reference }
    public function process(Request $request)
    {
        $this->requireAdmin();

        $request->validate([
            'user_id'       => 'required|integer|exists:users,id',
            'earning_ids'   => 'required|array|min:1',
            'earning_ids.*' => 'integer|exists:earnings,id',
            'eft_reference' => 'required|string|max:100',
        ]);

        $userId     = $request->integer('user_id');
        $earningIds = $request->input('earning_ids');
        $eftRef     = $request->input('eft_reference');

        $bankDetails = BankDetail::where('user_id', $userId)->first();
        if (!$bankDetails) {
            $guard = User::findOrFail($userId);
            Mail::to($guard->email)->queue(new NoBankDetailsMail($guard));

            return response()->json([
                'message' => 'Guard has no bank details on file. A notification has been sent to them.',
            ], 422);
        }

        $earnings = Earning::whereIn('id', $earningIds)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->get();

        if ($earnings->isEmpty()) {
            return response()->json(['message' => 'No pending earnings found for the selected IDs.'], 422);
        }

        DB::transaction(function () use ($earnings, $userId, $eftRef) {
            $netAmount   = $earnings->sum('earned_amount');
            $periodStart = $earnings->min('period_start');
            $periodEnd   = $earnings->max('period_end');

            $reference = 'GPAY-' . now()->format('Y-m') . '-' . str_pad(
                Payout::whereYear('created_at', now()->year)->count() + 1,
                3, '0', STR_PAD_LEFT
            );

            $payout = Payout::create([
                'user_id'            => $userId,
                'reference'          => $reference,
                'period_start'       => $periodStart,
                'period_end'         => $periodEnd,
                'net_amount'         => round($netAmount, 2),
                'status'             => 'paid',
                'paid_at'            => now(),
                'transfer_reference' => $eftRef,
            ]);

            Earning::whereIn('id', $earnings->pluck('id'))
                ->update([
                    'status'           => 'paid',
                    'payout_at'        => now(),
                    'payout_reference' => $eftRef,
                ]);

            $guard = User::find($userId);
            Mail::to($guard->email)->queue(new PayoutProcessedMail($guard, $payout, $earnings->count()));
        });

        return response()->json(['message' => 'Payout processed successfully.']);
    }

    // ── POST /api/admin/payouts/guards/notify-bank-details ────────────────────
    public function notifyBankDetails(Request $request)
    {
        $this->requireAdmin();

        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        $guard = User::findOrFail($request->integer('user_id'));
        Mail::to($guard->email)->queue(new NoBankDetailsMail($guard));

        return response()->json(['message' => 'Notification sent to ' . $guard->email]);
    }

    private function requireAdmin(): void
    {
        abort_if(auth()->user()->role !== 'admin', 403, 'Admin access required.');
    }
}
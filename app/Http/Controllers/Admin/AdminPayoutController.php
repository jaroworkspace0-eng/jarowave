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

class AdminPayoutController extends Controller
{
    // ── GET /api/admin/payouts/clients ────────────────────────────────────────
    // Returns all clients that have earnings, with their pending totals
    // Supports: ?month=6&year=2026&status=pending
    public function clients(Request $request)
    {
        $this->requireAdmin();

        $q = Earning::query()
            ->select(
                'client_id',
                DB::raw('SUM(CASE WHEN status = "pending"  THEN earned_amount ELSE 0 END) as pending_amount'),
                DB::raw('SUM(CASE WHEN status = "paid"     THEN earned_amount ELSE 0 END) as paid_amount'),
                DB::raw('SUM(CASE WHEN status = "withheld" THEN earned_amount ELSE 0 END) as withheld_amount'),
                DB::raw('SUM(earned_amount) as total_amount'),
                DB::raw('COUNT(*) as earning_count'),
                DB::raw('SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending_count'),
                DB::raw('MIN(period_start) as earliest_period'),
                DB::raw('MAX(period_end) as latest_period')
            )
            ->groupBy('client_id');

        // Period filter
        if ($request->filled('month') && $request->filled('year')) {
            $q->whereMonth('period_start', $request->integer('month'))
              ->whereYear('period_start',  $request->integer('year'));
        } elseif ($request->filled('year')) {
            $q->whereYear('period_start', $request->integer('year'));
        }

        // Only show clients with pending earnings by default
        if ($request->input('status', 'pending') === 'pending') {
            $q->having(DB::raw('SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END)'), '>', 0);
        }

        $rows = $q->get();

        // Enrich with client user + bank details
        $clients = $rows->map(function ($row) {
            $user        = User::find($row->client_id);
            $bankDetails = BankDetail::where('client_id', $row->client_id)->first();

            return [
                'client_id'       => $row->client_id,
                'name'            => $user?->name            ?? '—',
                'email'           => $user?->email           ?? '—',
                'organisation'    => $user?->organisation_name ?? $user?->name ?? '—',
                'pending_amount'  => round($row->pending_amount  / 100, 2),
                'paid_amount'     => round($row->paid_amount     / 100, 2),
                'withheld_amount' => round($row->withheld_amount / 100, 2),
                'total_amount'    => round($row->total_amount    / 100, 2),
                'earning_count'   => (int) $row->earning_count,
                'pending_count'   => (int) $row->pending_count,
                'earliest_period' => $row->earliest_period,
                'latest_period'   => $row->latest_period,
                'has_bank_details' => $bankDetails !== null,
                'bank_details'    => $bankDetails ? [
                    'bank_name'      => $bankDetails->bank_name,
                    'account_holder' => $bankDetails->account_holder,
                    'account_number' => $bankDetails->account_number,
                    'account_type'   => $bankDetails->account_type,
                    'branch_code'    => $bankDetails->branch_code,
                ] : null,
            ];
        });

        // Platform-wide totals
        $totals = [
            'total_pending'  => round($rows->sum('pending_amount')  / 100, 2),
            'total_paid'     => round($rows->sum('paid_amount')     / 100, 2),
            'total_clients'  => $rows->count(),
            'clients_no_bank'=> $clients->where('has_bank_details', false)->count(),
        ];

        return response()->json([
            'clients' => $clients,
            'totals'  => $totals,
        ]);
    }

    // ── GET /api/admin/payouts/clients/{clientId}/earnings ────────────────────
    // Returns individual earning rows for a specific client (for the detail drawer)
    public function clientEarnings(Request $request, int $clientId)
    {
        $this->requireAdmin();

        $q = Earning::where('client_id', $clientId)
            ->with(['resident']);

        if ($request->filled('month') && $request->filled('year')) {
            $q->whereMonth('period_start', $request->integer('month'))
              ->whereYear('period_start',  $request->integer('year'));
        }

        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }

        $earnings = $q->orderBy('period_start', 'desc')->get()->map(fn($e) => [
            'id'               => $e->id,
            'household_name'   => $e->resident?->name ?? '—',
            'resident_amount'  => round($e->resident_amount  / 100, 2),
            'earned_amount'    => round($e->earned_amount    / 100, 2),
            'platform_amount'  => round($e->platform_amount  / 100, 2),
            'commission_percentage' => $e->commission_percentage,
            'status'           => $e->status,
            'period_start'     => $e->period_start?->toDateTimeString(),
            'period_end'       => $e->period_end?->toDateTimeString(),
            'payout_at'        => $e->payout_at?->toDateTimeString(),
            'payout_reference' => $e->payout_reference,
        ]);

        return response()->json(['earnings' => $earnings]);
    }

    // ── POST /api/admin/payouts/process ───────────────────────────────────────
    // Body: { client_id, earning_ids: [...], eft_reference }
    public function process(Request $request)
    {
        $this->requireAdmin();

        $request->validate([
            'client_id'     => 'required|integer|exists:users,id',
            'earning_ids'   => 'required|array|min:1',
            'earning_ids.*' => 'integer|exists:earnings,id',
            'eft_reference' => 'required|string|max:100',
        ]);

        $clientId    = $request->integer('client_id');
        $earningIds  = $request->input('earning_ids');
        $eftRef      = $request->input('eft_reference');

        // Check bank details
        $bankDetails = BankDetail::where('client_id', $clientId)->first();
        if (!$bankDetails) {
            // Notify client to add bank details
            $client = User::findOrFail($clientId);
            Mail::to($client->email)->queue(new NoBankDetailsMail($client));

            return response()->json([
                'message' => 'Client has no bank details on file. A notification has been sent to them.',
            ], 422);
        }

        $earnings = Earning::whereIn('id', $earningIds)
            ->where('client_id', $clientId)
            ->where('status', 'pending')
            ->get();

        if ($earnings->isEmpty()) {
            return response()->json(['message' => 'No pending earnings found for the selected IDs.'], 422);
        }

        DB::transaction(function () use ($earnings, $clientId, $eftRef, $bankDetails) {
            $grossAmount    = $earnings->sum('resident_amount');
            $platformFee    = $earnings->sum('platform_amount');
            $netAmount      = $earnings->sum('earned_amount');
            $periodStart    = $earnings->min('period_start');
            $periodEnd      = $earnings->max('period_end');
            $householdCount = $earnings->pluck('resident_id')->unique()->count();

            // Generate a readable reference
            $reference = 'PAY-' . now()->format('Y-m') . '-' . str_pad(
                Payout::whereYear('created_at', now()->year)->count() + 1,
                3, '0', STR_PAD_LEFT
            );

            // Create payout record
            $payout = Payout::create([
                'client_id'          => $clientId,
                'reference'          => $reference,
                'period_start'       => $periodStart,
                'period_end'         => $periodEnd,
                'household_count'    => $householdCount,
                'gross_amount'       => round($grossAmount  / 100, 2),
                'platform_fee'       => round($platformFee  / 100, 2),
                'net_amount'         => round($netAmount     / 100, 2),
                'status'             => 'paid',
                'paid_at'            => now(),
                'transfer_reference' => $eftRef,
            ]);

            // Mark all earnings as paid
            Earning::whereIn('id', $earnings->pluck('id'))
                ->update([
                    'status'           => 'paid',
                    'payout_at'        => now(),
                    'payout_reference' => $eftRef,
                ]);

            // Email the client
            $client = User::find($clientId);
            Mail::to($client->email)->queue(new PayoutProcessedMail($client, $payout, $earnings->count()));
        });

        return response()->json([
            'message' => 'Payout processed successfully.',
        ]);
    }

    // ── POST /api/admin/payouts/notify-bank-details ───────────────────────────
    // Manually trigger the "add bank details" email for a client
    public function notifyBankDetails(Request $request)
    {
        $this->requireAdmin();

        $request->validate(['client_id' => 'required|integer|exists:users,id']);

        $client = User::findOrFail($request->integer('client_id'));
        Mail::to($client->email)->queue(new NoBankDetailsMail($client));

        return response()->json(['message' => 'Notification sent to ' . $client->email]);
    }

    private function requireAdmin(): void
    {
        abort_if(auth()->user()->role !== 'admin', 403, 'Admin access required.');
    }
}
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Http\Request;

// Assumes "client" = User (role client), matching Payments\PayoutController's
// `Payout::where('client_id', $user->id)`. Swap User for your Client model
// if you actually have a separate one.
class PayoutController extends Controller
{
    // GET /api/admin/payouts/clients
    // month/year optional — omit both to get every client, all-time.
    public function clients(Request $request)
    {
        $periodFilter = function ($q) use ($request) {
            if ($request->filled('month') && $request->filled('year')) {
                $q->whereMonth('period_start', $request->integer('month'))
                  ->whereYear('period_start', $request->integer('year'));
            }
        };

        $users = User::where('role', 'client')
            ->with(['bankDetails', 'payouts' => $periodFilter])
            ->get();

        $clients = $users->map(function ($user) {
            $payouts = $user->payouts;
            $pending = $payouts->where('status', 'pending');
            $paid = $payouts->where('status', 'paid');

            return [
                'client_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'organisation' => $user->organisation,
                'pending_amount' => (float) $pending->sum('net_amount'),
                'paid_amount' => (float) $paid->sum('net_amount'),
                'total_amount' => (float) $payouts->sum('net_amount'),
                'earning_count' => $payouts->count(),
                'pending_count' => $pending->count(),
                'earliest_period' => optional($payouts->min('period_start'))->format('Y-m-d'),
                'latest_period' => optional($payouts->max('period_end'))->format('Y-m-d'),
                'has_bank_details' => $user->bankDetails !== null,
                'bank_details' => $user->bankDetails ? [
                    'bank_name' => $user->bankDetails->bank_name,
                    'account_holder' => $user->bankDetails->account_holder,
                    'account_number' => $user->bankDetails->account_number,
                    'account_type' => $user->bankDetails->account_type,
                    'branch_code' => $user->bankDetails->branch_code,
                ] : null,
            ];
        })->values();

        $totals = [
            'total_pending' => (float) $clients->sum('pending_amount'),
            'total_paid' => (float) $clients->sum('paid_amount'),
            'total_clients' => $clients->count(),
            'clients_no_bank' => $clients->where('has_bank_details', false)->count(),
        ];

        return response()->json(['clients' => $clients, 'totals' => $totals]);
    }

    // GET /api/admin/payouts/clients/{client}/earnings
    // Supports: ?status=pending|paid|all  &month=  &year=
    public function earnings(Request $request, User $client)
    {
        $q = Payout::where('client_id', $client->id);

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $q->where('status', $request->input('status'));
        }

        if ($request->filled('month') && $request->filled('year')) {
            $q->whereMonth('period_start', $request->integer('month'))
              ->whereYear('period_start', $request->integer('year'));
        }

        $earnings = $q->orderBy('period_start', 'desc')->get()->map(fn ($p) => [
            'id' => $p->id,
            'period_start' => optional($p->period_start)->format('Y-m-d'),
            'period_end' => optional($p->period_end)->format('Y-m-d'),
            'amount' => (float) $p->net_amount,
            'status' => $p->status,
        ]);

        return response()->json(['earnings' => $earnings]);
    }

    // POST /api/admin/payouts/process
    public function process(Request $request)
    {
        $data = $request->validate([
            'client_id' => 'required|integer|exists:users,id',
            'earning_ids' => 'required|array|min:1',
            'earning_ids.*' => 'integer|exists:payouts,id',
            'eft_reference' => 'required|string|max:100',
        ]);

        $payouts = Payout::where('client_id', $data['client_id'])
            ->whereIn('id', $data['earning_ids'])
            ->where('status', 'pending')
            ->get();

        if ($payouts->isEmpty()) {
            return response()->json(['message' => 'No pending earnings found for this client.'], 422);
        }

        foreach ($payouts as $payout) {
            $payout->update([
                'status' => 'paid',
                'transfer_reference' => $data['eft_reference'],
                'paid_at' => now(),
            ]);
        }

        // TODO: confirmation email
        // Mail::to(User::find($data['client_id'])->email)->send(new PayoutProcessed($payouts));

        return response()->json(['message' => 'Payout processed.']);
    }

    // POST /api/admin/payouts/notify-bank-details
    public function notifyBankDetails(Request $request)
    {
        $data = $request->validate([
            'client_id' => 'required|integer|exists:users,id',
        ]);

        $client = User::findOrFail($data['client_id']);
        // TODO: reminder email
        // Mail::to($client->email)->send(new BankDetailsReminder($client));

        return response()->json(['message' => 'Notification sent.']);
    }

    // GET /api/admin/payouts/history
    public function history()
    {
        $history = Payout::where('status', 'paid')
            ->whereNotNull('transfer_reference')
            ->with('client')
            ->get()
            ->groupBy('transfer_reference')
            ->map(function ($payouts, $ref) {
                $clients = $payouts->groupBy('client_id')->map(function ($clientPayouts) {
                    $client = $clientPayouts->first()->client;
                    return [
                        'organisation' => $client?->organisation ?? 'Unknown',
                        'amount' => (float) $clientPayouts->sum('net_amount'),
                    ];
                })->values();

                return [
                    'eft_reference' => $ref,
                    'processed_at' => optional($payouts->max('paid_at'))->toIso8601String(),
                    'client_count' => $clients->count(),
                    'total_amount' => (float) $payouts->sum('net_amount'),
                    'clients' => $clients,
                ];
            })
            ->sortByDesc('processed_at')
            ->values();

        return response()->json(['history' => $history]);
    }

    // GET /api/admin/payouts/export  — month/year optional
    public function export(Request $request)
    {
        $hasPeriod = $request->filled('month') && $request->filled('year');
        $month = $request->integer('month');
        $year = $request->integer('year');

        $q = Payout::with(['client.bankDetails'])->where('status', 'pending');

        if ($hasPeriod) {
            $q->whereMonth('period_start', $month)->whereYear('period_start', $year);
        }

        $payouts = $q->get()->filter(fn ($p) => $p->client && $p->client->bankDetails);

        $filename = $hasPeriod
            ? sprintf('payouts-%d-%02d.csv', $year, $month)
            : 'payouts-all.csv';

        $callback = function () use ($payouts) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Organisation', 'Email', 'Bank Name', 'Account Holder',
                'Account Number', 'Account Type', 'Branch Code',
                'Reference', 'Period Start', 'Period End', 'Net Amount',
            ]);

            foreach ($payouts as $p) {
                $bd = $p->client->bankDetails;
                fputcsv($handle, [
                    $p->client->organisation,
                    $p->client->email,
                    $bd->bank_name,
                    $bd->account_holder,
                    $bd->account_number,
                    $bd->account_type,
                    $bd->branch_code,
                    $p->reference,
                    optional($p->period_start)->format('Y-m-d'),
                    optional($p->period_end)->format('Y-m-d'),
                    number_format($p->net_amount, 2),
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
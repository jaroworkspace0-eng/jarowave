<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use Illuminate\Http\Request;

class PayoutController extends Controller
{
    // GET /api/payouts
    // Supports: ?month=6&year=2026&status=pending
    public function index(Request $request)
    {
        $user = $request->user();

        $q = Payout::where('client_id', $user->id);

        if ($request->filled('month') && $request->filled('year')) {
            $q->whereMonth('created_at', $request->integer('month'))
              ->whereYear('created_at',  $request->integer('year'));
        } elseif ($request->filled('year')) {
            $q->whereYear('created_at', $request->integer('year'));
        }

        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }

        $payouts = $q->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20))
            ->withQueryString();

        return response()->json(['payouts' => $payouts]);
    }

    // GET /api/payouts/export  — CSV
    public function export(Request $request)
    {
        $user = $request->user();

        $q = Payout::where('client_id', $user->id);

        if ($request->filled('month') && $request->filled('year')) {
            $q->whereMonth('created_at', $request->integer('month'))
              ->whereYear('created_at',  $request->integer('year'));
        }

        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }

        $payouts = $q->orderBy('created_at', 'desc')->get();

        $rows   = [];
        $rows[] = ['Reference', 'Period Start', 'Period End', 'Households', 'Gross (R)', 'Platform Fee (R)', 'Your Payout (R)', 'Status', 'Paid At', 'Transfer Reference'];

        foreach ($payouts as $p) {
            $rows[] = [
                $p->reference ?? '—',
                optional($p->period_start)->format('Y-m-d') ?? '—',
                optional($p->period_end)->format('Y-m-d')   ?? '—',
                $p->household_count,
                number_format($p->gross_amount, 2),
                number_format($p->platform_fee, 2),
                number_format($p->net_amount,   2),
                $p->status,
                optional($p->paid_at)->format('Y-m-d') ?? '—',
                $p->transfer_reference ?? '—',
            ];
        }

        $filename = 'payouts-' . now()->format('Y-m-d') . '.csv';

        $callback = function () use ($rows) {
            $handle = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        };

        return response()->stream($callback, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
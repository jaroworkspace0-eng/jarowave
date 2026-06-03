<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Earning;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EarningController extends Controller
{
    // ── GET /api/earnings ─────────────────────────────────────────────────────
    // Supports: ?month=6&year=2026&status=pending&per_page=20
    public function index(Request $request)
    {
        $user = $request->user();
        $q    = $this->baseQuery($user);

        $this->applyFilters($q, $request);

        $earnings = $q->with(['resident'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20))
            ->withQueryString();

        // Shape each row for the frontend
        $earnings->through(function ($e) {
            return $this->formatEarning($e);
        });

        return response()->json(['earnings' => $earnings]);
    }

    // ── GET /api/earnings/summary ─────────────────────────────────────────────
    // Supports same filters for scoped totals
    public function summary(Request $request)
    {
        $user = $request->user();
        $q    = $this->baseQuery($user);

        $this->applyFilters($q, $request);

        // All-time totals (unfiltered) for the hero banner
        $all = $this->baseQuery($user);

        return response()->json([
            'summary' => [
                // Filtered totals
                'pending_amount'     => round($q->clone()->where('status', 'pending')->sum('earned_amount')  / 100, 2),
                'paid_amount'        => round($q->clone()->where('status', 'paid')->sum('earned_amount')     / 100, 2),
                'withheld_amount'    => round($q->clone()->where('status', 'withheld')->sum('earned_amount') / 100, 2),
                'total_earned'       => round($q->clone()->sum('earned_amount')                              / 100, 2),
                'platform_collected' => round($q->clone()->sum('platform_amount')                            / 100, 2),
                'pending_count'      => $q->clone()->where('status', 'pending')->count(),
                'paid_count'         => $q->clone()->where('status', 'paid')->count(),
                'total_count'        => $q->clone()->count(),
                'total_residents'    => $q->clone()->distinct('resident_id')->count('resident_id'),
                'commission_rate'    => (int) ($q->clone()->value('commission_percentage') ?? 60),

                // All-time totals (always unfiltered — for hero banner)
                'alltime_earned'     => round($all->clone()->sum('earned_amount') / 100, 2),
                'alltime_paid'       => round($all->clone()->where('status', 'paid')->sum('earned_amount') / 100, 2),
                'alltime_pending'    => round($all->clone()->where('status', 'pending')->sum('earned_amount') / 100, 2),
            ],
        ]);
    }

    // ── GET /api/earnings/export ──────────────────────────────────────────────
    // CSV export — supports same filters
    public function export(Request $request)
    {
        $user = $request->user();
        $q    = $this->baseQuery($user);

        $this->applyFilters($q, $request);

        $earnings = $q->with(['resident'])->orderBy('period_start', 'desc')->get();

        $rows   = [];
        $rows[] = ['Reference', 'Household', 'Period Start', 'Period End', 'Resident Paid (R)', 'Your Share (R)', 'Platform Fee (R)', 'Commission %', 'Status', 'Paid At', 'Payout Reference'];

        foreach ($earnings as $e) {
            $rows[] = [
                $e->id,
                $e->resident?->name ?? '—',
                $e->period_start?->format('Y-m-d') ?? '—',
                $e->period_end?->format('Y-m-d')   ?? '—',
                number_format($e->resident_amount / 100, 2),
                number_format($e->earned_amount   / 100, 2),
                number_format($e->platform_amount / 100, 2),
                $e->commission_percentage . '%',
                $e->status,
                $e->payout_at?->format('Y-m-d') ?? '—',
                $e->payout_reference ?? '—',
            ];
        }

        $filename = 'earnings-' . now()->format('Y-m-d') . '.csv';

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

    // ── GET /api/earnings/{earning} ───────────────────────────────────────────
    public function show(Earning $earning)
    {
        $this->authorise($earning);
        return response()->json([
            'earning' => $this->formatEarning($earning->load(['resident', 'payment'])),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function baseQuery($user)
    {
        // earnings.client_id stores users.id of the watch group owner directly
        return Earning::where('client_id', $user->id);
    }

    private function applyFilters($query, Request $request): void
    {
        if ($request->filled('month') && $request->filled('year')) {
            $query->whereMonth('period_start', $request->integer('month'))
                  ->whereYear('period_start',  $request->integer('year'));
        } elseif ($request->filled('year')) {
            $query->whereYear('period_start', $request->integer('year'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('resident_id')) {
            $query->where('resident_id', $request->integer('resident_id'));
        }
    }

    private function formatEarning(Earning $e): array
    {
        return [
            'id'                  => $e->id,
            'household_name'      => $e->resident?->name ?? '—',
            'household_address'   => trim(implode(', ', array_filter([
                $e->resident?->address_line_1,
                $e->resident?->suburb,
            ]))),
            'resident_amount'     => round($e->resident_amount  / 100, 2),
            'earned_amount'       => round($e->earned_amount    / 100, 2),
            'platform_amount'     => round($e->platform_amount  / 100, 2),
            'commission_percentage' => $e->commission_percentage,
            'status'              => $e->status,
            'period_start'        => $e->period_start?->toDateTimeString(),
            'period_end'          => $e->period_end?->toDateTimeString(),
            'payout_at'           => $e->payout_at?->toDateTimeString(),
            'payout_reference'    => $e->payout_reference,
            'created_at'          => $e->created_at?->toDateTimeString(),
        ];
    }

    private function authorise(Earning $earning): void
    {
        $user = auth()->user();
        if ($user->role === 'admin') return;
        abort_if($earning->client_id !== $user->id, 403, 'Unauthorised');
    }
}
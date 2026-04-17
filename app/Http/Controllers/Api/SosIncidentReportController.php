<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ConductBlockMail;
use App\Models\SosIncidentReport;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SosIncidentReportController extends Controller
{
    // ── POST /api/incident-reports ────────────────────────────────────────────
    // Patroller submits a report after responding to an alert
    public function store(Request $request)
    {
        $request->validate([
            'emergency_alert_id' => 'nullable|exists:emergency_alerts,id',
            'household_user_id'  => 'required|exists:users,id',
            'outcome'            => 'required|in:legitimate,misuse',
            'misuse_category'    => 'required_if:outcome,misuse|nullable|in:accidental,prank,domestic_dispute,unfounded_fear,repeated_false_alarm,other',
            'narrative'          => 'required|string|min:20',
            'arrived_at'         => 'nullable|date',
            'departed_at'        => 'nullable|date|after_or_equal:arrived_at',
            'injuries_reported'  => 'boolean',
            'property_damage'    => 'boolean',
            'additional_notes'   => 'nullable|string',
        ]);

        $report = SosIncidentReport::create([
            ...$request->only([
                'emergency_alert_id', 'household_user_id', 'outcome',
                'misuse_category', 'narrative', 'arrived_at', 'departed_at',
                'injuries_reported', 'property_damage', 'additional_notes',
            ]),
            'reporter_user_id' => Auth::id(),
            'status'           => 'pending',
        ]);

        $report->load(['household:id,name,email', 'reporter:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Incident report submitted successfully.',
            'report'  => $report,
        ], 201);
    }

    // ── GET /api/incident-reports ─────────────────────────────────────────────
    // Patroller views their own submitted reports
    public function index(Request $request)
    {
        $reports = SosIncidentReport::with([
            'household:id,name,email,phone',
            'emergencyAlert:id,created_at,latitude,longitude',
        ])
            ->where('reporter_user_id', Auth::id())
            ->latest()
            ->paginate(20);

        return response()->json($reports);
    }

    // ── GET /api/admin/incident-reports ───────────────────────────────────────
    // Admin views all reports with filters
    public function adminIndex(Request $request)
    {
        $query = SosIncidentReport::with([
            'household:id,name,email,phone',
            'reporter:id,name,email',
            'emergencyAlert:id,created_at,latitude,longitude',
            'actionedBy:id,name',
        ])->latest();

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->outcome) {
            $query->where('outcome', $request->outcome);
        }

        if ($request->search) {
            $search = $request->search;
            $query->whereHas('household', fn($q) =>
                $q->where('name', 'like', "%$search%")
                  ->orWhere('email', 'like', "%$search%")
            );
        }


        // Add conditional date filter
        $query->when($request->date_from, function ($q) use ($request) {
            $q->whereBetween('created_at', [
                $request->date_from . ' 00:00:00',
                ($request->date_to ?? $request->date_from) . ' 23:59:59',
            ]);
        });


        return response()->json($query->paginate(20));
    }

    // ── GET /api/admin/incident-reports/{report} ──────────────────────────────
    public function show(SosIncidentReport $report)
    {
        $report->load([
            'household:id,name,email,phone',
            'reporter:id,name,email,phone',
            'emergencyAlert',
            'actionedBy:id,name',
        ]);

        return response()->json($report);
    }

    // ── POST /api/admin/incident-reports/{report}/action ─────────────────────
    // Admin actions a report: warn, block, dismiss, add notes
    public function action(Request $request, SosIncidentReport $report)
    {
        $request->validate([
            'action'      => 'required|in:warn,block,dismiss,review',
            'admin_notes' => 'nullable|string',
        ]);

        $household = User::find($report->household_user_id);
        $action    = $request->action;

        match ($action) {
            'warn' => $this->sendWarning($report, $household, $request->admin_notes),
            'block' => $this->applyBlock($report, $household, $request->admin_notes),
            'dismiss' => $this->dismiss($report, $request->admin_notes),
            'review' => $this->markReviewed($report, $request->admin_notes),
        };

        return response()->json([
            'success' => true,
            'message' => match ($action) {
                'warn'    => 'Warning email sent to household.',
                'block'   => 'Conduct block applied and household notified.',
                'dismiss' => 'Report dismissed.',
                'review'  => 'Report marked as reviewed.',
            },
            'report' => $report->fresh(['household', 'reporter', 'actionedBy']),
        ]);
    }

    // ── GET /api/household/incident-reports ───────────────────────────────────
    // Household views reports filed on their own alerts
    public function householdReports(Request $request)
    {
        $reports = SosIncidentReport::with([
            'reporter:id,name',
            'emergencyAlert:id,created_at',
        ])
            ->where('household_user_id', Auth::id())
            ->latest()
            ->paginate(20);

        return response()->json($reports);
    }

    // ── Private helpers ───────────────────────────────────────────────────────
    private function sendWarning(SosIncidentReport $report, User $household, ?string $notes): void
    {
        Mail::to($household->email)->queue(
            new \App\Mail\SosMisuseWarningMail(
                userName:    $household->name,
                reportCount: SosIncidentReport::where('household_user_id', $household->id)
                                ->where('outcome', 'misuse')->count(),
                narrative:   $report->narrative,
            )
        );

        $report->update([
            'status'      => 'warned',
            'admin_notes' => $notes,
            'actioned_by' => Auth::id(),
            'actioned_at' => now(),
        ]);
    }

    private function applyBlock(SosIncidentReport $report, User $household, ?string $notes): void
    {
        $subscription = Subscription::where('user_id', $household->id)->latest()->first();

        if ($subscription) {
            $reason = 'Conduct block: Misuse of SOS panic alert. Report #' . $report->id;
            $subscription->update([
                'conduct_blocked_at'   => now(),
                'conduct_block_reason' => $reason,
                'sos_suspended_at'     => now(),
            ]);

            // Notify Node.js
            try {
                Http::timeout(5)
                    ->withHeaders(['Authorization' => 'Bearer ' . env('ASSIGN_SECRET')])
                    ->post(env('PTT_SERVER_URL') . '/payment-failed', [
                        'userId'       => $household->id,
                        'forceSuspend' => true,
                        'reason'       => $reason,
                    ]);
            } catch (\Throwable $e) {
                Log::warning('SosIncidentReport: Node notify failed', ['error' => $e->getMessage()]);
            }

            Mail::to($household->email)->queue(new ConductBlockMail(
                userName: $household->name,
                reason:   'Repeated misuse of the SOS panic alert system. Report #' . $report->id,
            ));
        }

        $report->update([
            'status'      => 'blocked',
            'admin_notes' => $notes,
            'actioned_by' => Auth::id(),
            'actioned_at' => now(),
        ]);
    }

    private function dismiss(SosIncidentReport $report, ?string $notes): void
    {
        $report->update([
            'status'      => 'dismissed',
            'admin_notes' => $notes,
            'actioned_by' => Auth::id(),
            'actioned_at' => now(),
        ]);
    }

    private function markReviewed(SosIncidentReport $report, ?string $notes): void
    {
        $report->update([
            'status'      => 'reviewed',
            'admin_notes' => $notes,
            'actioned_by' => Auth::id(),
            'actioned_at' => now(),
        ]);
    }
}
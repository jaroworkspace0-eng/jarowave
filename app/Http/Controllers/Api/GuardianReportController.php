<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GuardianReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuardianReportController extends Controller
{
    // GET /api/guardian-reports?alert_id=X&review_status=pending
    public function index(Request $request): JsonResponse
    {
        $query = GuardianReport::with(['reportingHousehold', 'reviewedBy'])
            ->where('reporting_household_id', $request->user()->id)
            ->orderByDesc('submitted_at');

        if ($request->filled('review_status')) {
            $query->where('review_status', $request->input('review_status'));
        }
        if ($request->filled('alert_type')) {
            $query->where('alert_type', $request->input('alert_type'));
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        return response()->json($query->paginate(20));
    }

    // GET /api/guardian-reports/{report}
    public function show(GuardianReport $report): JsonResponse
    {
        return response()->json(
            $report->load(['reportingHousehold', 'reviewedBy', 'incidentReport'])
        );
    }

    // POST /api/guardian-reports
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'alert_type'        => 'required|in:dv,sos',
            'description'       => 'required|string|min:10',
            'seen_perpetrator'  => 'boolean',
            'heard_disturbance' => 'boolean',
            'severity'          => 'required|in:low,medium,high',
        ]);

        $report = GuardianReport::create([
            'alert_id'               => null, // standalone report, not tied to an alert
            'alert_type'             => $request->input('alert_type'),
            'reporting_household_id' => $request->user()->id, // ← fixed
            'description'            => $request->input('description'),
            'seen_perpetrator'       => $request->boolean('seen_perpetrator'),
            'heard_disturbance'      => $request->boolean('heard_disturbance'),
            'severity'               => $request->input('severity'),
            'submitted_at'           => now(),
            'review_status'          => 'pending',
        ]);

        return response()->json($report->load('reportingHousehold'), 201);
    }

    // PUT /api/guardian-reports/{report}/review
    public function review(Request $request, GuardianReport $report): JsonResponse
    {
        $request->validate([
            'review_notes' => 'nullable|string',
        ]);

        $report->update([
            'review_status' => 'reviewed',
            'reviewed_by'   => $request->user()->id,
            'reviewed_at'   => now(),
            'review_notes'  => $request->input('review_notes'),
        ]);

        return response()->json($report->load(['reportingHousehold', 'reviewedBy']));
    }

    // PUT /api/guardian-reports/{report}/escalate
    public function escalate(Request $request, GuardianReport $report): JsonResponse
    {
        $request->validate([
            'incident_report_id' => 'nullable|exists:incident_reports,id',
            'review_notes'       => 'nullable|string',
        ]);

        $report->update([
            'review_status'      => 'escalated',
            'reviewed_by'        => $request->user()->id,
            'reviewed_at'        => now(),
            'review_notes'       => $request->input('review_notes'),
            'incident_report_id' => $request->input('incident_report_id'),
        ]);

        return response()->json($report->load(['reportingHousehold', 'reviewedBy', 'incidentReport']));
    }
}
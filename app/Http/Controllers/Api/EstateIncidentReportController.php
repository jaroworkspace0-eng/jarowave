<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmergencyResolution;
use App\Models\SosIncidentReport;
use Illuminate\Http\Request;

class EstateIncidentReportController extends Controller
{
    public function index(Request $request)
    {
        $channelIds = $request->user()->accessibleChannelIds();

        $query = SosIncidentReport::with(['household', 'reporter', 'alert'])
            ->whereIn('channel_id', $channelIds);


        if ($request->search) {
            $query->whereHas('household', function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                ->orWhere('unit_number', 'like', "%{$request->search}%");
            });
        }

        if ($request->status) $query->where('status', $request->status);
        if ($request->outcome) $query->where('outcome', $request->outcome);
        if ($request->date_from) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->date_to) $query->whereDate('created_at', '<=', $request->date_to);

        return $query->latest()->paginate(15);
    }

    public function show(Request $request, SosIncidentReport $report)
    {
        abort_unless($request->user()->accessibleChannelIds()->contains($report->channel_id), 403);

        $report->load(['household', 'reporter', 'actionedBy', 'alert']);

        $report->resolution = EmergencyResolution::where('emergency_alert_id', $report->emergency_alert_id)
            ->where('responder_user_id', $report->reporter_user_id)
            ->first();

        return $report;
    }
}
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

        $query = SosIncidentReport::with([
            'household:id,name,email',
            'reporter',
            'alert:id,created_at,latitude,longitude,alert_type,name,phone,unit_number,alert_location_source,address_line_1,complex_name,suburb,is_estate',
        ])->whereIn('channel_id', $channelIds);

        if ($request->search) {
            $search = $request->search;
            $query->whereHas('alert', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                ->orWhere('unit_number', 'like', "%{$search}%");
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
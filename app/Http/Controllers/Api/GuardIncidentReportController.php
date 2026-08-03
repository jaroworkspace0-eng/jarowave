<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmergencyAlert;
use App\Models\EmergencyResolution;
use App\Models\SosIncidentReport;
use Illuminate\Http\Request;

class GuardIncidentReportController extends Controller
{
    private function channelIds(Request $request): array
    {
        return $request->user()->employee->channels()->pluck('channels.id')->toArray();
    }


    public function index(Request $request)
{
    $channelIds = $this->channelIds($request);

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

    $reports = $query->latest()->paginate(15);

    $alertIds = $reports->getCollection()->pluck('emergency_alert_id')->filter()->values();
    $resolutions = EmergencyResolution::whereIn('emergency_alert_id', $alertIds)->get();

    $reports->getCollection()->transform(function ($report) use ($resolutions) {
        $report->resolution = $resolutions->first(fn($r) =>
            $r->emergency_alert_id === $report->emergency_alert_id
            && $r->responder_user_id === $report->reporter_user_id
        );
        return $report;
    });

    return $reports;
}

    public function guardsInChannel(Request $request)
    {
        $channelIds = $this->channelIds($request);

        return Employee::whereHas('channels', fn($q) => $q->whereIn('channels.id', $channelIds))
            ->whereHas('user', fn($q) => $q->where('role', 'employee')->where('is_gate_guard', true))
            ->with('user:id,name,email')
            ->get(['id', 'user_id']);
    }

    public function pendingIncidents(Request $request, $guardUserId)
    {
        $channelIds = $this->channelIds($request);

        return EmergencyResolution::with('alert.user')
            ->where('responder_user_id', $guardUserId)
            ->whereHas('alert', fn($q) => $q->whereIn('channel_id', $channelIds))
            ->whereDoesntHave('alert.incidentReport')
            ->latest('created_at')
            ->get();
    }

    public function store(Request $request)
    {
        $channelIds = $this->channelIds($request);

        $data = $request->validate([
            'emergency_alert_id' => 'nullable|exists:emergency_alerts,id',
            'household_user_id' => 'required|exists:users,id',
            'reporter_user_id' => 'required|exists:users,id',
            'outcome' => 'required|in:legitimate,misuse',
            'misuse_category' => 'nullable|in:accidental,prank,domestic_dispute,unfounded_fear,repeated_false_alarm,other',
            'narrative' => 'required|string',
            'arrived_at' => 'nullable|date',
            'departed_at' => 'nullable|date',
            'injuries_reported' => 'boolean',
            'property_damage' => 'boolean',
            'additional_notes' => 'nullable|string',
        ]);

        if ($data['emergency_alert_id'] ?? null) {
            $alert = EmergencyAlert::findOrFail($data['emergency_alert_id']);
            abort_unless(in_array($alert->channel_id, $channelIds), 403);
            $data['channel_id'] = $alert->channel_id;
        } else {
            $data['channel_id'] = $request->validate([
                'channel_id' => 'required|in:' . implode(',', $channelIds),
            ])['channel_id'];
        }

        $data['status'] = 'pending';

        return SosIncidentReport::create($data);
    }
}
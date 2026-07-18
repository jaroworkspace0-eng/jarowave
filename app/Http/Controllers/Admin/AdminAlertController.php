<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\EmergencyAlert;
use Illuminate\Http\Request;

class AdminAlertController extends Controller
{
    /**
     * Called by the dashboard on socket connect/reconnect to backfill
     * anything the live feed couldn't have delivered while disconnected.
     */
    public function open(Request $request)
{
    $user = $request->user();

    $alerts = EmergencyAlert::query()
        ->whereNull('resolved_at')
        ->when($user->role !== 'admin', function ($q) use ($user) {
            $q->whereIn('channel_id', $user->accessibleChannelIds());
        })
        ->with([
            'user:id,name,phone,address_line_1,complex_name,suburb',
            'channel:id,name',
            'events' => fn ($q) => $q->orderBy('created_at'),
            'guardianNotifications:id,emergency_alert_id,guardian_id,notified_at,responded_at,response_type',
        ])
        ->orderByDesc('created_at')
        ->get();

    return response()->json($alerts->map(fn ($alert) => [
        'id' => $alert->id,
        'type' => $alert->alert_type,
        'household_name' => $alert->user->name,
        'household_phone' => $alert->user->phone,
        'home_address' => collect([
            $alert->user->complex_name,
            $alert->user->address_line_1,
            $alert->user->suburb,
        ])->filter()->implode(', '),
        'channel_name' => $alert->channel->name,
        'created_at' => $alert->created_at,
        'first_ack_at' => $alert->first_ack_at,
        'last_lat' => $alert->last_lat,
        'last_lng' => $alert->last_lng,
        'muted' => $alert->muted,
        'guardian_count' => $alert->guardianNotifications->count(),
        'guardian_ids' => $alert->guardianNotifications->pluck('guardian_id'),
        'events' => $alert->events,
    ]));
}

    public function mute(Request $request, EmergencyAlert $alert)
    {
        $request->validate(['muted' => 'required|boolean']);
        app(\App\Services\AlertEventService::class)
            ->toggleMute($alert, $request->user()->id, $request->boolean('muted'));

        return response()->noContent();
    }

    public function callLog(Request $request, EmergencyAlert $alert)
    {
        $request->validate(['outcome' => 'required|string|in:answered,no_answer,voicemail,attempted']);
        app(\App\Services\AlertEventService::class)
            ->logAdminCallAttempt($alert, $request->user()->id, $request->input('outcome'));

        return response()->noContent();
    }

    public function resolve(Request $request, EmergencyAlert $alert)
    {
        $request->validate([
            'resolution' => 'required|string|in:household_safe,guard_handled,false_alarm,escalated_external',
            'notes' => 'nullable|string',
        ]);

        app(\App\Services\AlertEventService::class)->resolve(
            $alert,
            'admin',
            $request->user()->id,
            $request->input('resolution'),
            $request->input('notes'),
        );

        return response()->noContent();
    }

    public function reassign(Request $request, EmergencyAlert $alert)
    {
        $request->validate(['guard_id' => 'required|exists:users,id']);

        app(\App\Services\AlertEventService::class)
            ->reassign($alert, $request->user()->id, $request->input('guard_id'));

        return response()->noContent();
    }
}

// routes/api.php additions:
// Route::middleware('auth:sanctum')->prefix('admin/alerts')->group(function () {
//     Route::get('open', [AdminAlertController::class, 'open']);
//     Route::post('{alert}/mute', [AdminAlertController::class, 'mute']);
//     Route::post('{alert}/call-log', [AdminAlertController::class, 'callLog']);
//     Route::post('{alert}/resolve', [AdminAlertController::class, 'resolve']);
//     Route::post('{alert}/reassign', [AdminAlertController::class, 'reassign']);
// });
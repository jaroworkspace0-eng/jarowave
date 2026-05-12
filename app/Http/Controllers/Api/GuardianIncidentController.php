<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmergencyAlert;
use App\Models\GuardianIncidentClaim;
use App\Models\GuardianIncidentResponse;
use App\Models\HouseholdPairing;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GuardianIncidentController extends Controller
{
    public function __construct(protected NotificationService $notifications) {}

    // POST /api/guardian-incidents/{alertId}/claim
    public function claim(Request $request, string $alertId): JsonResponse
    {
        $alertId    = (int) $alertId;
        $guardianId = $request->user()->id;

        try {
            $claim = DB::transaction(function () use ($alertId, $guardianId) {
                $existing = GuardianIncidentClaim::where('emergency_alert_id', $alertId)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    throw new \Exception('Already claimed by another guardian.');
                }

                return GuardianIncidentClaim::create([
                    'emergency_alert_id' => $alertId,
                    'claimed_by_user_id' => $guardianId,
                    'status'             => 'claimed',
                    'claimed_at'         => now(),
                ]);
            });
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        GuardianIncidentResponse::updateOrCreate(
            ['emergency_alert_id' => $alertId, 'user_id' => $guardianId],
            ['action' => 'on_my_way', 'responded_at' => now()]
        );

        $alert    = EmergencyAlert::findOrFail($alertId);
        $guardian = $request->user();

        $this->notifications->send(
            recipient: User::findOrFail($alert->user_id),
            type:      'guardian_responding',
            title:     'Guardian On The Way',
            body:      "{$guardian->name} is responding to your alert.",
            data:      ['alert_id' => $alertId, 'guardian_id' => $guardianId],
        );


        $this->notifyOtherGuardians(
            $alert,
            $guardianId,
            'incident_claimed',
            'Guardian Responding',
            "{$guardian->name} is responding to the alert.",
            [
                'alertId'         => $alertId,
                'claimedByName'   => $guardian->name,
                'claimedByUserId' => $guardianId,
            ],
        );

        $this->notifyVictimSocket($alertId, $guardian->name, 'on_my_way', $guardian->id);

        return response()->json(['message' => 'Claimed successfully.', 'claim' => $claim], 201);
    }

    // POST /api/guardian-incidents/{alertId}/respond
    public function respond(Request $request, string $alertId): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:acknowledged,called_police,safe_confirmed,on_scene',
            'note'   => 'nullable|string',
        ]);

        $alertId    = (int) $alertId;

        $response = GuardianIncidentResponse::updateOrCreate(
            [
                'emergency_alert_id' => $alertId,
                'user_id'            => $request->user()->id,
            ],
            [
                'action'       => $request->action,
                'note'         => $request->note,
                'responded_at' => now(),
            ]
        );

        $this->notifyVictimSocket($alertId, $request->user()->name, $request->action);

        if ($request->action === 'called_police') {
            $alert    = EmergencyAlert::findOrFail($alertId);
            $guardian = $request->user();

            $this->notifications->send(
                recipient: User::findOrFail($alert->user_id),
                type:      'guardian_called_police',
                title:     'Police Called',
                body:      "{$guardian->name} has called the police for your alert.",
                data:      ['alert_id' => $alertId],
            );
        }

        return response()->json(['message' => 'Response recorded.', 'response' => $response]);
    }

    // POST /api/guardian-incidents/{alertId}/resolve
    public function resolve(Request $request, string $alertId): JsonResponse
    {
        $request->validate([
            'resolution_note' => 'nullable|string',
        ]);

        $alertId    = (int) $alertId;

        $claim = GuardianIncidentClaim::where('emergency_alert_id', $alertId)
            ->where('claimed_by_user_id', $request->user()->id)
            ->firstOrFail();

        $claim->update([
            'status'          => 'resolved',
            'resolved_at'     => now(),
            'resolution_note' => $request->resolution_note,
        ]);

        // ── update response row to safe_confirmed ──
        GuardianIncidentResponse::updateOrCreate(
            [
                'emergency_alert_id' => $alertId,
                'user_id'            => $request->user()->id,
            ],
            [
                'action'       => 'safe_confirmed',
                'responded_at' => now(),
            ]
        );

        $alert    = EmergencyAlert::findOrFail($alertId);
        $guardian = $request->user();

        $this->notifyVictimSocket($alertId, $guardian->name, 'resolved', $guardian->id);

        $this->notifyOtherGuardians(
            $alert,
            $request->user()->id,
            'incident_resolved',
            'Incident Resolved',
            "{$guardian->name} has marked the incident as resolved.",
        );

        $this->notifications->send(
            recipient: User::findOrFail($alert->user_id),
            type:      'incident_resolved',
            title:     'Guardian Resolved Incident',
            body:      "{$guardian->name} has marked your incident as resolved.",
            data:      ['alert_id' => $alertId],
        );

        return response()->json(['message' => 'Incident resolved.']);
    }

    // GET /api/guardian-incidents/{alertId}/status
    public function status(Request $request, string $alertId): JsonResponse
    {
        $claim = GuardianIncidentClaim::with('claimer:id,name')
            ->where('emergency_alert_id', $alertId)
            ->first();

        $alertId    = (int) $alertId;
        $responses = GuardianIncidentResponse::with('user:id,name')
            ->where('emergency_alert_id', $alertId)
            ->latest('responded_at')
            ->get();

        return response()->json([
            'claimed'   => !!$claim,
            'claim'     => $claim,
            'responses' => $responses,
        ]);
    }

    private function notifyOtherGuardians(
    EmergencyAlert $alert,
    int $excludeUserId,
    string $type,
    string $title,
    string $body,
    ?array $socketPayload = null,
): void {
    $guardianIds = HouseholdPairing::where(function ($q) use ($alert) {
        $q->where('requester_id', $alert->user_id)
          ->orWhere('receiver_id', $alert->user_id);
    })
    ->where('status', 'active')
    ->get()
    ->map(fn($p) => $p->requester_id === $alert->user_id
        ? $p->receiver_id
        : $p->requester_id)
    ->filter(fn($id) => $id !== $excludeUserId)
    ->values();

    foreach ($guardianIds as $id) {
        $this->notifications->send(
            recipient: User::findOrFail($id),
            type:      $type,
            title:     $title,
            body:      $body,
            data:      ['alert_id' => $alert->id],
        );
    }

    // ── socket notify if payload provided ──
    if ($socketPayload && $guardianIds->isNotEmpty()) {
        try {
            Http::withHeaders([
                'Authorization' => 'Bearer ' . env('ASSIGN_SECRET'),
                'Content-Type'  => 'application/json',
            ])
            ->timeout(5)
            ->post(env('PTT_SERVER_URL') . '/notify-guardian-claimed', array_merge($socketPayload, [
                'guardianUserIds' => $guardianIds->toArray(),
            ]));
        } catch (\Throwable $e) {
            Log::warning("notify-guardian-claimed socket failed: {$e->getMessage()}");
        }
    }
}


    private function notifyVictimSocket(int $alertId, string $guardianName, string $action, ?int $guardianUserId = null): void
    {
        // ── if alertId is 0 it means temp client ID was passed — skip ──
        if ($alertId === 0) {
            Log::warning("notifyVictimSocket: alertId is 0 — skipping");
            return;
        }

        try {
            $alert = EmergencyAlert::findOrFail($alertId);
            Http::withHeaders([
                'Authorization' => 'Bearer ' . env('ASSIGN_SECRET'),
                'Content-Type'  => 'application/json',
            ])
            ->timeout(5)
            ->post(env('PTT_SERVER_URL') . '/guardian-incident-update', [
                'victimUserId' => $alert->user_id,
                'guardianName' => $guardianName,
                'guardianUserId'   => $guardianUserId,
                'action'       => $action,
                'alertId'      => $alertId,
            ]);
        } catch (\Throwable $e) {
            Log::warning("notifyVictimSocket failed: {$e->getMessage()}");
        }
    }

    public function householdConfirm(Request $request, string $alertId): JsonResponse
    {
        $alertId  = (int) $alertId;
        $household = $request->user();

        Log::info("householdConfirm: alertId={$alertId} household={$household->id}");

        $claim = GuardianIncidentClaim::where('emergency_alert_id', $alertId)
            ->where('status', 'resolved')
            ->first();

        Log::info("householdConfirm: claim found=" . ($claim ? "YES guardianId={$claim->claimed_by_user_id}" : "NO"));

        EmergencyAlert::where('id', $alertId)->update([
            'is_resolved' => true,
            'resolved_at' => now(),
        ]);

        if ($claim) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('ASSIGN_SECRET'),
                    'Content-Type'  => 'application/json',
                ])
                ->timeout(5)
                ->post(env('PTT_SERVER_URL') . '/guardian-incident-confirmed-safe', [
                    'guardianUserId' => $claim->claimed_by_user_id,
                    'alertId'        => $alertId,
                    'victimName'     => $household->name,
                ]);

                Log::info("householdConfirm: Node response status={$response->status()} body={$response->body()}");
            } catch (\Throwable $e) {
                Log::warning("guardian-incident-confirmed-safe notify failed: {$e->getMessage()}");
            }
        }

        return response()->json(['success' => true, 'message' => 'Household confirmed safe.']);
    }
}
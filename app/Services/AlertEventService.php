<?php

namespace App\Services;

use App\Jobs\CheckAlertAcknowledgementSla;
use App\Models\Alert;
use App\Models\AlertEvent;
use App\Models\AlertGuardianNotification;
use App\Models\EmergencyAlert;
use Illuminate\Support\Facades\Http;

class AlertEventService
{
    /**
     * Record any state change in an alert's lifecycle.
     * This is the ONLY place that should write to alert_events.
     * Every mutation (guard app, guardian app, admin dashboard, system timers)
     * must go through here so the timeline is always complete.
     */
    public function record(EmergencyAlert $alert, string $actorType, ?int $actorId, string $eventType, array $payload = []): AlertEvent
    {
        $event = $alert->events()->create([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'event_type' => $eventType,
            'payload' => $payload,
        ]);

        // Track first acknowledgement for SLA / escalation logic
        if ($eventType === 'guard_acknowledged' && !$alert->first_ack_at) {
            $alert->update(['first_ack_at' => now()]);
        }

        $this->broadcastToAdmins($alert, $event);

        return $event;
    }

    /**
     * Called when an alert is first created — fans out to guards on the
     * household's channel AND all paired guardians, and logs each guardian
     * notification individually so the dashboard can show "notified 4 guardians".
     */
    public function notifyGuardiansAndGuards(EmergencyAlert $alert): void
    {
        $household = $alert->household;

        // Guards on the same channel — assumes existing channel->guards relation
        $guards = $household->channel->guards()->where('is_on_duty', true)->get();
        foreach ($guards as $guard) {
            // existing FCM/socket dispatch to guard stays as-is
            $this->record($alert, 'system', null, 'guard_notified', ['guard_id' => $guard->id]);
        }

        // Paired guardians — log each one explicitly for the count/list requirement
        $guardians = $household->guardians; // existing pairing relation
        foreach ($guardians as $guardian) {
            AlertGuardianNotification::create([
                'alert_id' => $alert->id,
                'guardian_id' => $guardian->id,
                'notified_at' => now(),
            ]);

            CheckAlertAcknowledgementSla::dispatch($alert->id)->delay(now()->addSeconds(90));
            // existing FCM/socket dispatch to guardian stays as-is
        }

        $this->record($alert, 'system', null, 'guardians_notified', [
            'guardian_count' => $guardians->count(),
            'guardian_ids' => $guardians->pluck('id'),
        ]);
    }

    public function recordGuardianResponse(EmergencyAlert $alert, int $guardianId, string $responseType): void
    {
        AlertGuardianNotification::where('alert_id', $alert->id)
            ->where('guardian_id', $guardianId)
            ->update(['responded_at' => now(), 'response_type' => $responseType]);

        $this->record($alert, 'guardian', $guardianId, 'guardian_responded', ['response_type' => $responseType]);
    }

    public function updateLocation(EmergencyAlert $alert, float $lat, float $lng): void
    {
        $alert->update([
            'last_lat' => $lat,
            'last_lng' => $lng,
            'location_updated_at' => now(),
        ]);

        $this->record($alert, 'household', $alert->user_id, 'location_updated', [
            'lat' => $lat,
            'lng' => $lng,
        ]);
    }

    public function logAdminCallAttempt(EmergencyAlert $alert, int $adminId, string $outcome): void
    {
        $this->record($alert, 'admin', $adminId, 'admin_call_logged', ['outcome' => $outcome]);
    }

    public function toggleMute(EmergencyAlert $alert, int $adminId, bool $muted): void
    {
        // Panic/SOS should never be fully suppressed — only mute sound, not the log.
        if ($alert->type === 'panic' || $alert->type === 'sos') {
            $muted = false;
        }

        $alert->update(['muted' => $muted]);
        $this->record($alert, 'admin', $adminId, $muted ? 'muted' : 'unmuted');
    }

    /**
     * Push this event straight to the admin dashboard's Socket.IO namespace.
     * Assumes your existing Node/Socket.IO server exposes an internal HTTP
     * endpoint for Laravel to emit through (common pattern — swap for
     * whatever bridge you already use for guard/guardian dispatch).
     */
 
    protected function broadcastToAdmins(EmergencyAlert $alert, AlertEvent $event): void
    {
        Http::withToken(env('ASSIGN_SECRET'))
            ->post(env('PTT_SERVER_URL') . '/emit', [
                'channelId' => $alert->client_id,
                'event' => 'alert:event',
                'data' => [
                    'alert_id' => $alert->id,
                    'event' => $event->only(['event_type', 'actor_type', 'actor_id', 'payload', 'created_at']),
                ],
            ]);
    }

    public function broadcastNewAlert(EmergencyAlert $alert): void
    {
        Http::withToken(env('ASSIGN_SECRET'))
            ->post(env('PTT_SERVER_URL') . '/emit', [
                'channelId' => $alert->channel_id,
                'householdId' => $alert->user_id, // needed so household-scoped claims match too
                'clientId' => $alert->client_id,
                'event' => 'alert:new',
                'data' => [
                    'id' => $alert->id,
                    'type' => $alert->alert_type,
                    'household_name' => $alert->user->name,
                    'household_phone' => $alert->user->phone,
                    // 'home_address' => $alert->user->home_address,
                    'home_address' => collect([
                            $alert->user->address_line_1,
                            $alert->user->complex_name,
                            $alert->user->suburb,
                        ])->filter()->implode(', '),
                    'channel_name' => $alert->channel->name,
                    'created_at' => $alert->created_at,
                    'first_ack_at' => $alert->first_ack_at,
                    'last_lat' => $alert->last_lat,
                    'last_lng' => $alert->last_lng,
                    'muted' => $alert->muted,
                    'guardian_count' => 0,
                    'guardian_ids' => [],
                    'events' => [],
                ],
            ]);
    }

    public function resolve(EmergencyAlert $alert, string $actorType, ?int $actorId, string $resolution, ?string $notes = null): void
    {
        $alert->update(['resolved_at' => now(), 'resolution' => $resolution, 'resolution_notes' => $notes]);

        $this->record($alert, $actorType, $actorId, 'resolved', ['resolution' => $resolution]);

        Http::withToken(env('ASSIGN_SECRET'))
            ->post(env('PTT_SERVER_URL') . '/emit', [
                'clientId' => $alert->household->client_id,
                'event' => 'alert:resolved',
                'data' => ['alert_id' => $alert->id],
            ]);
    }
}
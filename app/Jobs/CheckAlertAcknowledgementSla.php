<?php

namespace App\Jobs;

use App\Models\Alert;
use App\Models\EmergencyAlert;
use App\Services\AlertEventService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckAlertAcknowledgementSla implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected int $alertId)
    {
    }

    public function handle(AlertEventService $events): void
    {
        $alert = EmergencyAlert::find($this->alertId);

        // Alert may already be resolved or acknowledged — nothing to do
        if (!$alert || $alert->resolved_at || $alert->first_ack_at) {
            return;
        }

        $events->record($alert, 'system', null, 'escalated', [
            'reason' => 'no_guard_acknowledgement',
            'seconds_elapsed' => now()->diffInSeconds($alert->created_at),
        ]);

        // Optional: also push a direct FCM/push notification to admins here,
        // not just the socket event, in case the dashboard tab isn't focused.
    }
}

// Dispatch this from AlertEventService::notifyGuardiansAndGuards(), right after
// the alert is created, so it fires exactly once at the 90s mark per alert:
//
//   CheckAlertAcknowledgementSla::dispatch($alert->id)->delay(now()->addSeconds(90));
//
// This scales far better than a scheduled command polling all open alerts
// every minute — one delayed job per alert, and it's a no-op if already acked.
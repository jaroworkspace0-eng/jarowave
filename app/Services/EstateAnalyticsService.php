<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\EmergencyAlert;
use App\Models\GuardianIncidentClaim;
use App\Models\GuardianIncidentResponse;
use App\Models\ChannelSubscription;
use App\Models\Checkpoint;
use App\Models\CheckpointScan;
use App\Models\Ticket;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Aggregated, period-scoped analytics for a single channel (estate).
 * Consumed by both the live Estate/Analytics.vue dashboard and the
 * scheduled monthly PDF report job — same numbers, two renderers.
 *
 * ASSUMPTIONS FLAGGED (verify against actual schema before running):
 * - patrol_checkpoints / patrol_scans are scoped by channel_id. If they're
 *   actually scoped by client_id (multi-estate client), swap the where()
 *   clauses in patrolCompliance() accordingly.
 * - Ticket model/table name and its channel scope column — adjust
 *   ticketSummary() to match your real model.
 * - GuardianIncidentResponse has a timestamp column indicating when the
 *   guard arrived/responded (assumed `responded_at`); GuardianIncidentClaim
 *   assumed `claimed_at`. Swap for your real column names if different.
 * - EmergencyAlert "resolved" state assumed to be a `status` column with
 *   value 'resolved' (or a `resolved_at` timestamp — code checks for either,
 *   prefer trimming to whichever actually exists on your table).
 */
class EstateAnalyticsService
{
    public function summary(int $channelId, CarbonInterface $from, CarbonInterface $to): array
    {
        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'incidents' => $this->incidentSummary($channelId, $from, $to),
            'patrol' => $this->patrolCompliance($channelId, $from, $to),
            'households' => $this->householdGrowth($channelId, $from, $to),
            'tickets' => $this->ticketSummary($channelId, $from, $to),
        ];
    }

    public function incidentSummary(int $channelId, CarbonInterface $from, CarbonInterface $to): array
    {
        $alerts = EmergencyAlert::where('channel_id', $channelId)
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'trigger_source', 'resolved_at', 'cancel_pin_used', 'created_at']);

        $total = $alerts->count();

        $bySource = $alerts->groupBy(fn ($a) => $a->trigger_source ?? 'manual')
            ->map->count();

        // cancel_pin_used: 'safe_cancel' | 'duress' | 'none' (or null, treated as none)
        $byCancelPin = $alerts->groupBy(fn ($a) => $a->cancel_pin_used ?: 'none')
            ->map->count();

        $duressCount = $byCancelPin->get('duress', 0);
        $safeCancelCount = $byCancelPin->get('safe_cancel', 0);

        // "Resolved" now means genuinely handled — resolved_at set AND not a
        // self-cancel. A duress or safe-cancel alert is tracked separately
        // below, not folded into "resolved", so a duress cancellation never
        // gets buried under a generic resolved count.
        $resolved = $alerts->filter(fn ($a) => $a->resolved_at !== null && ($a->cancel_pin_used ?: 'none') === 'none')->count();

        $alertIds = $alerts->pluck('id');

        $responseTimes = DB::table('guardian_incident_claims')
            ->whereIn('emergency_alert_id', $alertIds)
            ->whereNotNull('arrived_at')
            ->select(DB::raw('TIMESTAMPDIFF(SECOND, claimed_at, arrived_at) as seconds'))
            ->pluck('seconds')
            ->filter(fn ($s) => $s !== null && $s >= 0);

        $avgResponseSeconds = $responseTimes->isNotEmpty()
            ? (int) round($responseTimes->avg())
            : null;

        $sorted = $responseTimes->sort()->values();
        $medianResponseSeconds = $sorted->isNotEmpty()
            ? (int) $sorted->get(intdiv($sorted->count(), 2))
            : null;

        return [
            'total' => $total,
            'by_trigger_source' => [
                'manual' => $bySource->get('manual', 0),
                'auto_detected' => $bySource->get('auto_detected', 0),
            ],
            'resolved' => $resolved,
            'unresolved' => $total - $resolved - $duressCount - $safeCancelCount,
            'cancellations' => [
                'duress' => $duressCount,
                'safe_cancel' => $safeCancelCount,
                'total' => $duressCount + $safeCancelCount,
            ],
            'avg_response_seconds' => $avgResponseSeconds,
            'median_response_seconds' => $medianResponseSeconds,
        ];
    }

    public function patrolCompliance(int $channelId, CarbonInterface $from, CarbonInterface $to): array
    {
        $checkpointCount = Checkpoint::where('channel_id', $channelId)->count();

        $scans = CheckpointScan::where('channel_id', $channelId)
            ->whereBetween('scanned_at', [$from, $to])
            ->get(['id', 'checkpoint_id', 'guard_id', 'scanned_at']);

        $scanCount = $scans->count();
        $distinctCheckpointsScanned = $scans->pluck('checkpoint_id')->unique()->count();

        $coveragePct = $checkpointCount > 0
            ? round(($distinctCheckpointsScanned / $checkpointCount) * 100, 1)
            : null;

        $byGuard = $scans->groupBy('guard_id')->map->count();

        return [
            'checkpoint_count' => $checkpointCount,
            'scan_count' => $scanCount,
            'distinct_checkpoints_scanned' => $distinctCheckpointsScanned,
            'coverage_pct' => $coveragePct,
            'scans_by_guard' => $byGuard,
        ];
    }

    public function householdGrowth(int $channelId, CarbonInterface $from, CarbonInterface $to): array
{
    $subscription = ChannelSubscription::where('channel_id', $channelId)
        ->latest()
        ->first();

    // Estate-opted-in Subscriptions carry status='cancelled' (PayFast token
    // cancelled, replaced by estate billing) — membership is identified by
    // cancellation_reason='estate_optin' + matching channel_subscription_id,
    // NOT status='active'.
    // NOTE: this counts linked accounts too, since they share the same
    // channel_subscription_id/cancellation_reason as their primary —
    // subtracting linked_account_count below to isolate primaries.
    $covered = \App\Models\Subscription::where('channel_subscription_id', $subscription->id ?? 0)
        ->where('cancellation_reason', 'estate_optin')
        ->count();

    $newThisPeriod = \App\Models\Subscription::where('channel_subscription_id', $subscription->id ?? 0)
        ->where('cancellation_reason', 'estate_optin')
        ->whereBetween('created_at', [$from, $to])
        ->count();

    // Computed live against account_links, same query as
    // ChannelBillingService::getActiveLinkedAccountCount() — the cached
    // linked_account_count column on ChannelSubscription is a billing-cycle
    // snapshot and goes stale between billing runs.
    $primaryIds = \App\Models\Subscription::where('cancellation_reason', 'estate_optin')
        ->whereHas('channelSubscription', fn ($q) => $q->where('channel_id', $channelId))
        ->pluck('user_id');

    $linkedAccounts = \App\Models\AccountLink::where('status', 'active')
        ->whereIn('primary_account_id', $primaryIds)
        ->count();

    return [
        'active_households' => max($covered - $linkedAccounts, 0),
        'linked_accounts' => $linkedAccounts,
        'new_this_period' => $newThisPeriod,
    ];
}

    public function ticketSummary(int $channelId, CarbonInterface $from, CarbonInterface $to): array
    {
        $tickets = Ticket::where('channel_id', $channelId)
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'status', 'created_at', 'resolved_at']);

        $resolved = $tickets->whereNotNull('resolved_at');

        $avgResolutionHours = $resolved->isNotEmpty()
            ? round($resolved->avg(fn ($t) => Carbon::parse($t->created_at)->diffInHours(Carbon::parse($t->resolved_at))), 1)
            : null;

        return [
            'total' => $tickets->count(),
            'open' => $tickets->count() - $resolved->count(),
            'resolved' => $resolved->count(),
            'avg_resolution_hours' => $avgResolutionHours,
        ];
    }
}
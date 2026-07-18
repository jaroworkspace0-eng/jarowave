import { io } from 'socket.io-client';
import { reactive, ref } from 'vue';

// Instantiate once at layout level (e.g. AdminLayout.vue's setup()),
// not per-page, so the connection survives Inertia navigation.
export function useAdminAlerts(clientId, adminUserId) {
    const alerts = reactive(new Map());
    const connectionStatus = ref('connecting');

    const socket = io(import.meta.env.VITE_SOCKET_URL, {
        withCredentials: true,
    });

    socket.on('connect', () => {
        socket.emit('join-admin-room', { clientId, adminUserId });
    });

    socket.on('admin-room-joined', async () => {
        connectionStatus.value = 'live';
        await hydrateOpenAlerts();
    });

    socket.on('disconnect', () => {
        connectionStatus.value = 'reconnecting';
    });

    socket.on('alert:new', (alert) => {
        alerts.set(alert.id, {
            ...alert,
            events: alert.events ?? [],
            muted: false,
        });
    });

    socket.on('alert:event', ({ alert_id, event }) => {
        const alert = alerts.get(alert_id);
        if (!alert) return; // shouldn't happen post-hydration, but guard anyway
        alert.events.push(event);

        if (event.event_type === 'guard_acknowledged' && !alert.first_ack_at) {
            alert.first_ack_at = event.created_at;
        }
        if (event.event_type === 'location_updated') {
            alert.last_lat = event.payload.lat;
            alert.last_lng = event.payload.lng;
        }
        if (event.event_type === 'guardians_notified') {
            alert.guardian_count = event.payload.guardian_count;
            alert.guardian_ids = event.payload.guardian_ids;
        }
        if (event.event_type === 'muted' || event.event_type === 'unmuted') {
            alert.muted = event.event_type === 'muted';
        }
    });

    socket.on('alert:resolved', ({ alert_id }) => {
        // Remove from the live board rather than just flagging — resolved alerts
        // belong in a history/report view, not competing for attention on the
        // active dashboard. Full journey is still in the DB via alert_events.
        alerts.delete(alert_id);
    });

    async function hydrateOpenAlerts() {
        const res = await fetch('/api/admin/alerts/open', {
            credentials: 'include',
        });
        const data = await res.json();
        data.forEach((alert) => alerts.set(alert.id, alert));
    }

    function toggleMute(alertId, muted) {
        fetch(`/api/admin/alerts/${alertId}/mute`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ muted }),
        });
        // optimistic update; server event will confirm/correct via alert:event
        const alert = alerts.get(alertId);
        if (alert && alert.type !== 'panic' && alert.type !== 'sos') {
            alert.muted = muted;
        }
    }

    function logCallAttempt(alertId, outcome) {
        fetch(`/api/admin/alerts/${alertId}/call-log`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ outcome }),
        });
    }

    function resolve(alertId, resolution) {
        fetch(`/api/admin/alerts/${alertId}/resolve`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ resolution }),
        });
        // optimistic removal; alert:resolved event will confirm for all connected admins
        alerts.delete(alertId);
    }

    return { alerts, connectionStatus, toggleMute, logCallAttempt, resolve };
}

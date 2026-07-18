import { io } from 'socket.io-client';
import { reactive, ref } from 'vue';

export function useAdminAlerts() {
    const alerts = reactive(new Map());
    const connectionStatus = ref('connecting');

    const socket = io(import.meta.env.VITE_SOCKET_URL, {
        withCredentials: true,
    });

    socket.on('connect', () => {
        console.log(
            '[useAdminAlerts] socket connected, emitting join-admin-room',
        );
        socket.emit('join-admin-room');
    });

    socket.on('admin-room-joined', async ({ role } = {}) => {
        console.log('[useAdminAlerts] admin-room-joined', role);
        connectionStatus.value = 'live';
        await hydrateOpenAlerts();
    });

    socket.on('admin-room-join-failed', ({ reason } = {}) => {
        console.error('[useAdminAlerts] admin-room-join-failed', reason);
        connectionStatus.value = 'error';
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
        if (!alert) return;
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
        alerts.delete(alertId);
    }

    return { alerts, connectionStatus, toggleMute, logCallAttempt, resolve };
}

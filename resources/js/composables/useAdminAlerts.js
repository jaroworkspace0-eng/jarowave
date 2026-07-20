import { io } from 'socket.io-client';
import { reactive, ref } from 'vue';

function authHeaders(extra = {}) {
    return {
        Authorization: `Bearer ${localStorage.getItem('token')}`,
        ...extra,
    };
}

async function fetchHandshakeCode() {
    const res = await fetch('/api/live-alerts/handshake', {
        headers: authHeaders(),
    });
    if (!res.ok) throw new Error(`handshake fetch failed: ${res.status}`);
    const data = await res.json();
    return data.code;
}

const SOUND_URL = '/sounds/sos-alert.mp3';

const soundEnabled = ref(false);
const activeSounds = new Map();

function enableSound() {
    const unlockAudio = new Audio(SOUND_URL);
    unlockAudio.volume = 0;
    unlockAudio
        .play()
        .then(() => {
            unlockAudio.pause();
            unlockAudio.currentTime = 0;
            soundEnabled.value = true;
        })
        .catch((e) =>
            console.warn('[useAdminAlerts] unlock failed:', e.message),
        );
}

function playAlertSound(alertId) {
    if (activeSounds.has(alertId)) return;
    const audio = new Audio(SOUND_URL);
    audio.loop = true;
    audio.volume = 0.8;
    audio
        .play()
        .catch((e) =>
            console.warn('[useAdminAlerts] sound blocked:', e.message),
        );
    activeSounds.set(alertId, audio);
}

function stopAlertSound(alertId) {
    const audio = activeSounds.get(alertId);
    if (audio) {
        audio.pause();
        audio.currentTime = 0;
        activeSounds.delete(alertId);
    }
}

export function useAdminAlerts() {
    const alerts = reactive(new Map());
    const connectionStatus = ref('connecting');
    const hydrated = ref(false);
    const olderUnresolvedCount = ref(0);

    const socket = io(import.meta.env.VITE_SOCKET_URL, {
        withCredentials: true,
    });

    socket.on('connect', async () => {
        try {
            const code = await fetchHandshakeCode();
            socket.emit('join-admin-room', { code });
        } catch (e) {
            console.error('[useAdminAlerts] failed to fetch handshake code', e);
            connectionStatus.value = 'error';
        }
    });

    socket.on('admin-room-joined', async ({ role } = {}) => {
        console.log('[useAdminAlerts] admin-room-joined', role);
        connectionStatus.value = 'live';
        await hydrateOpenAlerts();
        await fetchOlderUnresolvedCount();
    });

    socket.on('admin-room-join-failed', ({ reason } = {}) => {
        console.error('[useAdminAlerts] admin-room-join-failed', reason);
        connectionStatus.value = 'error';
    });

    socket.on('disconnect', () => {
        connectionStatus.value = 'reconnecting';
    });

    socket.on('alert:new', (alert) => {
        const isLiveArrival = hydrated.value;
        alerts.set(alert.id, {
            ...alert,
            events: alert.events ?? [],
            guardians: alert.guardians ?? [],
            muted: false,
            justArrived: isLiveArrival,
        });
        if (isLiveArrival && ['panic', 'sos'].includes(alert.type)) {
            playAlertSound(alert.id);
        }
    });

    // Every event that arrives gets appended to the alert's timeline
    // ("Journey"), regardless of type — that's the single source that
    // renders the expanded Journey list. Specific event types additionally
    // update the summary fields shown on the collapsed card.
    socket.on('alert:event', ({ alert_id, event }) => {
        const alert = alerts.get(alert_id);
        if (!alert) return;

        alert.events.push(event);

        if (event.event_type === 'guard_acknowledged' && !alert.first_ack_at) {
            alert.first_ack_at = event.created_at;
            stopAlertSound(alert_id);
        }
        if (event.event_type === 'location_updated') {
            alert.last_lat = event.payload.lat;
            alert.last_lng = event.payload.lng;
        }
        if (event.event_type === 'guardians_notified') {
            alert.guardian_count = event.payload.guardian_count;
            if (event.payload.guardians) {
                alert.guardians = event.payload.guardians;
            }
        }
        if (event.event_type === 'guardian_responded') {
            const g = alert.guardians?.find(
                (guardian) => guardian.id === event.actor_id,
            );
            if (g) {
                g.responded_at = event.created_at;
                g.response_type = event.payload?.response_type;
            }
        }
        if (event.event_type === 'muted' || event.event_type === 'unmuted') {
            alert.muted = event.event_type === 'muted';
        }
    });

    socket.on('alert:resolved', ({ alert_id }) => {
        stopAlertSound(alert_id);
        alerts.delete(alert_id);
    });

    async function hydrateOpenAlerts() {
        const res = await fetch('/api/admin/alerts/open', {
            headers: authHeaders(),
        });
        const data = await res.json();
        data.forEach((alert) => {
            alerts.set(alert.id, {
                ...alert,
                events: alert.events ?? [],
                guardians: alert.guardians ?? [],
                justArrived: false,
            });
        });
        hydrated.value = true;
    }

    async function fetchOlderUnresolvedCount() {
        try {
            const res = await fetch(
                '/api/admin/alerts/older-unresolved-count',
                {
                    headers: authHeaders(),
                },
            );
            const data = await res.json();
            olderUnresolvedCount.value = data.older_unresolved_count ?? 0;
        } catch (e) {
            console.warn(
                '[useAdminAlerts] failed to fetch older-unresolved count:',
                e.message,
            );
        }
    }

    function markAlertSeen(alertId) {
        stopAlertSound(alertId);
        const alert = alerts.get(alertId);
        if (alert) alert.justArrived = false;
    }

    function toggleMute(alertId, muted) {
        fetch(`/api/admin/alerts/${alertId}/mute`, {
            method: 'POST',
            headers: authHeaders({ 'Content-Type': 'application/json' }),
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
            headers: authHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({ outcome }),
        });
    }

    function resolve(alertId, resolution) {
        fetch(`/api/admin/alerts/${alertId}/resolve`, {
            method: 'POST',
            headers: authHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({ resolution }),
        });
        stopAlertSound(alertId);
        alerts.delete(alertId);
    }

    return {
        alerts,
        connectionStatus,
        toggleMute,
        logCallAttempt,
        resolve,
        soundEnabled,
        enableSound,
        markAlertSeen,
        olderUnresolvedCount,
    };
}

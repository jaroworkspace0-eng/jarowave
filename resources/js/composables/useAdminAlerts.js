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
const SEEN_STORAGE_KEY = 'echoLink:seenAlertIds';

const soundEnabled = ref(false);
const activeSounds = new Map();

function loadSeenIds() {
    try {
        const raw = localStorage.getItem(SEEN_STORAGE_KEY);
        return new Set(raw ? JSON.parse(raw) : []);
    } catch (e) {
        return new Set();
    }
}

function persistSeenIds(set) {
    try {
        localStorage.setItem(SEEN_STORAGE_KEY, JSON.stringify([...set]));
    } catch (e) {
        console.warn(
            '[useAdminAlerts] failed to persist seen alerts',
            e.message,
        );
    }
}

const seenIds = loadSeenIds();

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
    });

    socket.on('admin-room-join-failed', ({ reason } = {}) => {
        console.error('[useAdminAlerts] admin-room-join-failed', reason);
        connectionStatus.value = 'error';
    });

    socket.on('disconnect', () => {
        connectionStatus.value = 'reconnecting';
    });

    socket.on('alert:new', (alert) => {
        const alreadySeen = seenIds.has(String(alert.id));
        const isLiveArrival = hydrated.value;
        alerts.set(alert.id, {
            ...alert,
            events: alert.events ?? [],
            muted: false,
            justArrived: !alreadySeen,
        });
        if (
            isLiveArrival &&
            !alreadySeen &&
            ['panic', 'sos'].includes(alert.type)
        ) {
            playAlertSound(alert.id);
        }
    });

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
            alert.guardian_ids = event.payload.guardian_ids;
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
                justArrived: !seenIds.has(String(alert.id)),
            });
        });
        hydrated.value = true;
    }

    function markAlertSeen(alertId) {
        seenIds.add(String(alertId));
        persistSeenIds(seenIds);
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
    };
}

<script setup>
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    onUnmounted,
    ref,
    watch,
} from 'vue';

const props = defineProps({
    alert: { type: Object, required: true },
});
const emit = defineEmits([
    'mute',
    'call-log',
    'resolve',
    'seen',
    'notify-guards',
    'dispatch',
]);

/* ---------------- Dispatch ---------------- */

const selectedDispatchGuardId = ref('');
const dispatching = ref(false);
const dispatchError = ref('');

async function dispatchSelectedGuard() {
    if (!selectedDispatchGuardId.value || dispatching.value) return;
    dispatchError.value = '';
    dispatching.value = true;
    try {
        await emit(
            'dispatch',
            props.alert.id,
            Number(selectedDispatchGuardId.value),
        );
    } finally {
        setTimeout(() => (dispatching.value = false), 1500);
    }
}

/* ------------------------------------------- */

const expanded = ref(false);
const mapFullscreen = ref(false);

const isDV = computed(() => props.alert.type === 'domestic_violence');
const isPanicLike = computed(() => ['panic', 'sos'].includes(props.alert.type));

const now = ref(Date.now());
let clockInterval;
onMounted(() => {
    clockInterval = setInterval(() => {
        now.value = Date.now();
    }, 1000);
});
onUnmounted(() => {
    clearInterval(clockInterval);
});

const secondsSinceAck = computed(() => {
    if (props.alert.first_ack_at) return null;
    return Math.floor(
        (now.value - new Date(props.alert.created_at).getTime()) / 1000,
    );
});
const escalated = computed(
    () => secondsSinceAck.value !== null && secondsSinceAck.value > 90,
);

const isNew = computed(() => !!props.alert.justArrived);

const typeMeta = computed(
    () =>
        ({
            panic: { label: 'Panic', badge: 'ac-badge--panic' },
            sos: { label: 'SOS', badge: 'ac-badge--panic' },
            domestic_violence: { label: 'DV Alert', badge: 'ac-badge--dv' },
            guardian: { label: 'Guardian', badge: 'ac-badge--guardian' },
        })[props.alert.type] || {
            label: props.alert.type || 'Alert',
            badge: 'ac-badge--general',
        },
);

const formattedDateTime = computed(() => {
    const d = new Date(props.alert.created_at);
    return d.toLocaleString([], {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
});

const formattedAckTime = computed(() => {
    if (!props.alert.first_ack_at) return null;
    return new Date(props.alert.first_ack_at).toLocaleTimeString();
});

const hasRealLocation = computed(() => {
    const lat = Number(props.alert.last_lat);
    const lng = Number(props.alert.last_lng);
    return !!(lat || lng);
});

const coordsLabel = computed(() => {
    if (!hasRealLocation.value) return null;
    const lat = Number(props.alert.last_lat);
    const lng = Number(props.alert.last_lng);
    return `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
});

const accuracyLabel = computed(() => {
    const raw = props.alert.accuracy;
    if (!raw || raw === 'awaiting_gps') return null;
    const acc = Number(raw);
    if (!acc || Number.isNaN(acc)) return null;
    return `±${Math.round(acc)}m`;
});

/* ---------------- Location source (GPS vs registered address) ---------------- */

const isRegisteredAddress = computed(
    () => props.alert.alert_location_source === 'registered_address',
);

const registeredAddressDisplay = computed(() => {
    if (!isRegisteredAddress.value) return null;

    if (props.alert.is_estate) {
        return props.alert.unit_number
            ? `Unit ${props.alert.unit_number}`
            : null;
    }

    return [
        props.alert.address_line_1,
        props.alert.complex_name,
        props.alert.suburb,
        props.alert.unit_number ? `Unit ${props.alert.unit_number}` : null,
    ]
        .filter(Boolean)
        .join(', ');
});

const locationSourceLabel = computed(() => {
    const source = props.alert.alert_location_source;
    if (source === 'gps') return 'Live GPS';
    if (source === 'registered_address') return 'Registered address';
    return null;
});

/* -------------------------------------------------------------------------- */

function guardianStatusLabel(g) {
    if (!g.responded_at) return 'no response yet';
    const type = (g.response_type || 'responded').replace(/_/g, ' ');
    return `${type} · ${new Date(g.responded_at).toLocaleTimeString()}`;
}

function haversineKm(lat1, lng1, lat2, lng2) {
    const R = 6371;
    const dLat = ((lat2 - lat1) * Math.PI) / 180;
    const dLng = ((lng2 - lng1) * Math.PI) / 180;
    const a =
        Math.sin(dLat / 2) ** 2 +
        Math.cos((lat1 * Math.PI) / 180) *
            Math.cos((lat2 * Math.PI) / 180) *
            Math.sin(dLng / 2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

const responderDistanceKm = computed(() => {
    if (!hasRealLocation.value || !props.alert.responderLocation) return null;
    return haversineKm(
        Number(props.alert.last_lat),
        Number(props.alert.last_lng),
        Number(props.alert.responderLocation.lat),
        Number(props.alert.responderLocation.lng),
    );
});

const responderDistanceLabel = computed(() => {
    if (responderDistanceKm.value === null) return null;
    return responderDistanceKm.value < 1
        ? `${Math.round(responderDistanceKm.value * 1000)}m`
        : `${responderDistanceKm.value.toFixed(1)}km`;
});

const etaMinutes = computed(() => {
    if (responderDistanceKm.value === null) return null;
    return Math.max(1, Math.round((responderDistanceKm.value / 40) * 60));
});

/* ---------------- Map (Leaflet + OpenStreetMap, no API key) ---------------- */

const thumbEl = ref(null);
const modalEl = ref(null);
let thumbMap = null;
let modalMap = null;
let thumbLayer = null;
let modalLayer = null;

const routeCoords = ref(null);

async function fetchRoute(lat1, lng1, lat2, lng2) {
    try {
        const url = `https://router.project-osrm.org/route/v1/driving/${lng1},${lat1};${lng2},${lat2}?overview=full&geometries=geojson`;
        const res = await fetch(url);
        if (!res.ok) return null;
        const data = await res.json();
        const coords = data?.routes?.[0]?.geometry?.coordinates;
        if (!coords) return null;
        return coords.map(([lng, lat]) => [lat, lng]);
    } catch {
        return null;
    }
}

function buildMap(container, interactive) {
    const map = L.map(container, {
        zoomControl: interactive,
        dragging: interactive,
        scrollWheelZoom: interactive,
        doubleClickZoom: interactive,
        boxZoom: interactive,
        keyboard: interactive,
        attributionControl: interactive,
    });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);
    return map;
}

function getAlertPoints() {
    const points = [];
    if (hasRealLocation.value) {
        points.push([
            Number(props.alert.last_lat),
            Number(props.alert.last_lng),
        ]);
    }
    if (props.alert.responderLocation) {
        points.push([
            Number(props.alert.responderLocation.lat),
            Number(props.alert.responderLocation.lng),
        ]);
    }
    return points;
}

function fitAlertBounds(map) {
    const points = getAlertPoints();
    if (points.length === 2) {
        map.fitBounds(points, { padding: [24, 24] });
    } else if (points.length === 1) {
        map.setView(points[0], 15);
    }
}

function recenterMap(map) {
    if (!map) return;
    fitAlertBounds(map);
}

function drawAlertOnMap(map) {
    if (!map) return null;
    const layer = L.layerGroup().addTo(map);

    if (hasRealLocation.value) {
        const hLat = Number(props.alert.last_lat);
        const hLng = Number(props.alert.last_lng);
        L.circleMarker([hLat, hLng], {
            radius: 8,
            color: '#dc2626',
            fillColor: '#dc2626',
            fillOpacity: 1,
            weight: 2,
        })
            .bindTooltip(
                `Household: ${props.alert.household_name || 'Unknown'}`,
                {
                    permanent: true,
                    direction: 'top',
                    offset: [0, -10],
                    className: 'ac-leaflet-label ac-leaflet-label--household',
                },
            )
            .addTo(layer);
    }

    if (props.alert.responderLocation) {
        const gLat = Number(props.alert.responderLocation.lat);
        const gLng = Number(props.alert.responderLocation.lng);
        L.circleMarker([gLat, gLng], {
            radius: 8,
            color: '#2563eb',
            fillColor: '#2563eb',
            fillOpacity: 1,
            weight: 2,
        })
            .bindTooltip(
                `Guard: ${props.alert.currentResponder?.username || 'Unknown'}`,
                {
                    permanent: true,
                    direction: 'top',
                    offset: [0, -10],
                    className: 'ac-leaflet-label ac-leaflet-label--guard',
                },
            )
            .addTo(layer);
    }

    if (routeCoords.value?.length) {
        L.polyline(routeCoords.value, {
            color: '#2563eb',
            weight: 4,
            opacity: 0.75,
        }).addTo(layer);
    }

    fitAlertBounds(map);
    return layer;
}

function refreshMaps() {
    if (thumbMap) {
        if (thumbLayer) thumbMap.removeLayer(thumbLayer);
        thumbLayer = drawAlertOnMap(thumbMap);
    }
    if (modalMap) {
        if (modalLayer) modalMap.removeLayer(modalLayer);
        modalLayer = drawAlertOnMap(modalMap);
    }
}

async function ensureThumbMap() {
    if (!hasRealLocation.value || thumbMap || !thumbEl.value) return;
    thumbMap = buildMap(thumbEl.value, false);
    refreshMaps();
}

async function ensureModalMap() {
    await nextTick();
    if (!modalMap && modalEl.value) {
        modalMap = buildMap(modalEl.value, true);
    }
    modalMap?.invalidateSize();
    refreshMaps();
}

onMounted(async () => {
    await nextTick();
    ensureThumbMap();
});

watch(hasRealLocation, async (has) => {
    if (has) {
        await nextTick();
        ensureThumbMap();
    }
});

watch(mapFullscreen, (open) => {
    if (open) ensureModalMap();
});

const mapStateKey = computed(() => {
    const h = hasRealLocation.value
        ? `${props.alert.last_lat},${props.alert.last_lng}`
        : '';
    const g = props.alert.responderLocation
        ? `${props.alert.responderLocation.lat},${props.alert.responderLocation.lng}`
        : '';
    return `${h}|${g}`;
});

watch(mapStateKey, async () => {
    if (hasRealLocation.value && props.alert.responderLocation) {
        routeCoords.value = await fetchRoute(
            Number(props.alert.last_lat),
            Number(props.alert.last_lng),
            Number(props.alert.responderLocation.lat),
            Number(props.alert.responderLocation.lng),
        );
    } else {
        routeCoords.value = null;
    }
    refreshMaps();
});

onBeforeUnmount(() => {
    thumbMap?.remove();
    modalMap?.remove();
    thumbMap = null;
    modalMap = null;
});

/* ---------------- Guard broadcast (one-way push, not a chat) ---------------- */

const channelGuards = computed(() => props.alert.channelGuards || []);

const notifyTarget = ref('all');
const selectedGuardIds = ref([]);
const notifyMessage = ref('');
const notifySent = ref(false);

const notifyTargetCount = computed(() => {
    if (notifyTarget.value === 'all') return channelGuards.value.length;
    if (notifyTarget.value === 'responder')
        return props.alert.currentResponder ? 1 : 0;
    return selectedGuardIds.value.length;
});

const quickMessages = [
    {
        label: 'No response yet',
        text: 'No guard has acknowledged this alert yet - please respond immediately.',
    },
    {
        label: 'Reinforcement needed',
        text: 'Requesting backup - please assist the responding guard at this location.',
    },
    {
        label: 'Stand down',
        text: 'Household confirmed safe. Stand down on this alert.',
    },
];

function applyQuickMessage(text) {
    notifyMessage.value = text;
}

function sendNotification() {
    const message = notifyMessage.value.trim();
    if (!message || !notifyTargetCount.value) return;

    const guardIds =
        notifyTarget.value === 'all'
            ? channelGuards.value.map((g) => g.id)
            : notifyTarget.value === 'responder'
              ? [props.alert.currentResponder.id]
              : [...selectedGuardIds.value];

    emit('notify-guards', props.alert.id, {
        target: notifyTarget.value,
        guardIds,
        message,
    });

    notifyMessage.value = '';
    notifySent.value = true;
    setTimeout(() => (notifySent.value = false), 2000);
}

watch(
    () => props.alert.currentResponder,
    (responder) => {
        if (!responder && notifyTarget.value === 'responder') {
            notifyTarget.value = 'all';
        }
    },
);

/* ---------------------------------------------------------------------- */

function eventLabel(ev) {
    const name = ev.payload?.username;
    switch (ev.event_type) {
        case 'guard_responding':
            return `${name || 'A guard'} is responding`;
        case 'guard_acknowledged':
            return `${name || 'A guard'} acknowledged (not responding)`;
        case 'guard_unassigned':
            return `${ev.payload?.previous_username || 'Guard'} no longer responding`;
        case 'location_updated':
            return 'Household location updated';
        case 'responder_location_updated':
            return 'Responder location updated';
        case 'guardians_notified':
            return `Notified ${ev.payload?.guardian_count ?? 0} guardian(s)`;
        case 'guardian_responded':
            return `Guardian responded: ${(ev.payload?.response_type || '').replace(/_/g, ' ')}`;
        case 'cancelled':
            return `Cancelled by household${ev.payload?.cancelled_by ? ' (' + ev.payload.cancelled_by + ')' : ''}`;
        case 'resolved':
            return `Resolved: ${(ev.payload?.resolution || '').replace(/_/g, ' ')}`;
        case 'muted':
            return 'Muted by admin';
        case 'unmuted':
            return 'Unmuted by admin';
        case 'admin_call_logged':
            return `Admin call logged: ${ev.payload?.outcome || ''}`;
        default:
            return `${ev.actor_type} ${ev.event_type.replace(/_/g, ' ')}`;
    }
}

function logCall(outcome) {
    window.location.href = `tel:${props.alert.household_phone}`;
    emit('call-log', props.alert.id, outcome);
}

function onResolveChange(e) {
    const value = e.target.value;
    e.target.value = '';
    if (value) emit('resolve', props.alert.id, value);
}
</script>

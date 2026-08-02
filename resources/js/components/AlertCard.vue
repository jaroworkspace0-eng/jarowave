<script setup>
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import {
    CheckCircle2,
    Crosshair,
    Megaphone,
    Send,
    Siren,
    UserPlus,
    X,
} from 'lucide-vue-next';
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
    // True for estate admin dashboards (dispatch + single-source address view).
    // False (default) for Echo Link admin (no dispatch, dual address view).
    isEstateAdmin: { type: Boolean, default: false },
});

const emit = defineEmits([
    'mute',
    'call-log',
    'resolve',
    'seen',
    'notify-guards',
    'dispatch',
]);

/* ---------------- Searchable dropdown state (dispatch + notify) ---------------- */

const dispatchWrapEl = ref(null);
const dispatchOpen = ref(false);
const dispatchQuery = ref('');

const selectedDispatchGuard = computed(
    () =>
        channelGuards.value.find(
            (g) => g.id === selectedDispatchGuardId.value,
        ) || null,
);

const filteredDispatchGuards = computed(() => {
    const q = dispatchQuery.value.trim().toLowerCase();
    if (!q) return channelGuards.value;
    return channelGuards.value.filter(
        (g) =>
            g.username?.toLowerCase().includes(q) ||
            g.phone?.toLowerCase().includes(q),
    );
});

function selectDispatchGuard(g) {
    selectedDispatchGuardId.value = g.id;
    dispatchQuery.value = '';
    dispatchOpen.value = false;
}

const notifyWrapEl = ref(null);
const notifyOpen = ref(false);
const notifyQuery = ref('');

const filteredNotifyGuards = computed(() => {
    const q = notifyQuery.value.trim().toLowerCase();
    if (!q) return channelGuards.value;
    return channelGuards.value.filter(
        (g) =>
            g.username?.toLowerCase().includes(q) ||
            g.phone?.toLowerCase().includes(q),
    );
});

const selectedNotifyGuards = computed(() =>
    channelGuards.value.filter((g) => selectedGuardIds.value.includes(g.id)),
);

function toggleNotifyGuard(g) {
    const i = selectedGuardIds.value.indexOf(g.id);
    if (i === -1) selectedGuardIds.value.push(g.id);
    else selectedGuardIds.value.splice(i, 1);
}

function handleOutsideClick(e) {
    if (
        dispatchOpen.value &&
        dispatchWrapEl.value &&
        !dispatchWrapEl.value.contains(e.target)
    ) {
        dispatchOpen.value = false;
    }
    if (
        notifyOpen.value &&
        notifyWrapEl.value &&
        !notifyWrapEl.value.contains(e.target)
    ) {
        notifyOpen.value = false;
    }
}

onMounted(() => document.addEventListener('mousedown', handleOutsideClick));
onBeforeUnmount(() =>
    document.removeEventListener('mousedown', handleOutsideClick),
);

/* ---------------- Dispatch (estate admin only) ---------------- */

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
        // The parent (LiveAlertsPage) owns the actual API call and error
        // surfacing today. We keep a local timeout so the button doesn't
        // stay stuck "Dispatching…" forever if the parent silently fails.
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

// Stays "new" (distinct highlight colors) only until the guard explicitly
// dismisses it or the page is reloaded. Escalation state is independent and
// must not clear the "new" styling on its own.
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

// Treat (0, 0) as "no location" regardless of whether it arrives as a
// number or a string — avoids the false "0.00000, 0.00000" display when
// a device sends default/placeholder coordinates before a real GPS fix.
const hasRealLocation = computed(() => {
    const lat = Number(props.alert.last_lat);
    const lng = Number(props.alert.last_lng);
    return !!(lat || lng);
});

/* ---------------- Registered address (always available, source-independent) ---------------- */

const registeredAddressAlways = computed(() => {
    if (props.alert.is_estate) {
        return props.alert.unit_number
            ? `Unit ${props.alert.unit_number}`
            : null;
    }
    return (
        [
            props.alert.address_line_1,
            props.alert.complex_name,
            props.alert.suburb,
            props.alert.unit_number ? `Unit ${props.alert.unit_number}` : null,
        ]
            .filter(Boolean)
            .join(', ') || null
    );
});

/* ---------------- GPS reverse geocode (address label from live coordinates) ---------------- */

const gpsAddressLabel = ref(null);
const gpsAddressLoading = ref(false);
const gpsAddressFailed = ref(false); // reverse geocode attempted but returned nothing

const isGpsSource = computed(() => props.alert.alert_location_source === 'gps');

// Nominatim's public endpoint — shared demo instance, rate-limited (max ~1
// req/sec), not for production volume. Fine for now; self-host Nominatim or
// use a paid geocoder if this scales.
async function reverseGeocode(lat, lng) {
    try {
        const url = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`;
        const res = await fetch(url, {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) return null;
        const data = await res.json();
        if (!data?.address) return data?.display_name || null;

        const a = data.address;
        // Keep it to street-level + suburb/area — drop city/province/country/
        // postcode, which is what makes Nominatim's raw display_name so long.
        const parts = [
            [a.house_number, a.road].filter(Boolean).join(' '),
            a.suburb || a.neighbourhood || a.residential,
            a.city || a.town || a.village,
        ].filter(Boolean);

        return parts.length ? parts.join(', ') : data.display_name || null;
    } catch {
        return null;
    }
}

// Keyed on lat/lng so it only re-fetches when the GPS fix actually moves,
// not on every unrelated alert prop change. Runs whenever a real fix exists,
// regardless of alert_location_source — Echo Link admin needs it even when
// the household's chosen source was "registered_address".
const gpsCoordsKey = computed(() =>
    hasRealLocation.value
        ? `${props.alert.last_lat},${props.alert.last_lng}`
        : '',
);

watch(
    gpsCoordsKey,
    async (key) => {
        gpsAddressLabel.value = null;
        gpsAddressFailed.value = false;
        if (!key) return;
        gpsAddressLoading.value = true;
        const result = await reverseGeocode(
            Number(props.alert.last_lat),
            Number(props.alert.last_lng),
        );
        gpsAddressLoading.value = false;
        if (result) {
            gpsAddressLabel.value = result;
        } else {
            // Nominatim returned nothing or the request failed — flag it so
            // the UI can mark the home_address fallback as approximate
            // rather than presenting it as equivalent to a live GPS fix.
            gpsAddressFailed.value = true;
            console.warn(
                `[AlertCard] Reverse geocode failed for alert ${props.alert.id} — falling back to registered home_address`,
            );
        }
    },
    { immediate: true },
);

// Estate-admin single-source display: what to show under "GPS" branch when
// alert_location_source === 'gps'. Falls back to stored home_address only
// when there's no real coordinate fix at all.
const gpsAddressDisplay = computed(() => {
    if (!isGpsSource.value) return null;
    if (hasRealLocation.value) return gpsAddressLabel.value;
    return props.alert.home_address || null;
});

// True only when we have a real GPS fix, tried to reverse-geocode it, and
// failed — meaning whatever address text is showing (home_address) is a
// stale fallback, not derived from the actual coordinates.
const isStaleFallbackAddress = computed(
    () => isGpsSource.value && hasRealLocation.value && gpsAddressFailed.value,
);

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

/* ---------------- Location source (GPS vs registered address) — estate admin view ---------------- */

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

const isEstateUnitOnly = computed(
    () => isRegisteredAddress.value && props.alert.is_estate,
);

/* -------------------------------------------------------------------------- */

function guardianStatusLabel(g) {
    if (!g.responded_at) return 'no response yet';
    const type = (g.response_type || 'responded').replace(/_/g, ' ');
    return `${type} · ${new Date(g.responded_at).toLocaleTimeString()}`;
}

// Straight-line distance — kept as the always-available fallback figure
// (shown in the card body) even when a routed line is also drawn on the map.
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

// Rough straight-line ETA — the routed line on the map is for visual
// context; this figure stays the simple, dependency-free estimate.
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

const routeCoords = ref(null); // [[lat,lng], ...] or null

// OSRM's public demo routing server — free, but it's a shared demo instance:
// no SLA, rate-limited, not meant for production traffic. Fine to prove this
// out now; for real deployment self-host OSRM or use a paid routing API.
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
    // OSM's raw tile servers have a usage policy (no heavy/commercial hammering
    // without permission). Fine for internal admin use at low volume; if this
    // dashboard scales up, switch to a provider with a free tier built for
    // apps (MapTiler, Stadia Maps, Thunderforest) instead of hitting tile.openstreetmap.org directly.
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);
    return map;
}

// Centralised so the "recenter" control and the initial draw always agree
// on where the map should be looking.
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

// Single key covering both points — refetches the route and redraws both
// maps whenever either location changes.
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

// Expects alert.channelGuards = [{ id, username, phone }] — the roster of
// guards assigned to this channel.
const channelGuards = computed(() => props.alert.channelGuards || []);

const notifyTarget = ref('all'); // 'all' | 'responder' | 'selected'
const selectedGuardIds = ref([]);
const notifyMessage = ref('');
const notifySent = ref(false);

const notifyTargetCount = computed(() => {
    if (notifyTarget.value === 'all') return channelGuards.value.length;
    if (notifyTarget.value === 'responder')
        return props.alert.currentResponder ? 1 : 0;
    return selectedGuardIds.value.length;
});

// Plain-language recipient line shown above the message box, so the admin
// always knows exactly who a broadcast is about to reach before sending it.
const notifyRecipientSummary = computed(() => {
    if (notifyTarget.value === 'all') {
        const n = channelGuards.value.length;
        return n
            ? `${n} guard${n === 1 ? '' : 's'} in this channel`
            : 'No guards in this channel';
    }
    if (notifyTarget.value === 'responder') {
        return props.alert.currentResponder
            ? props.alert.currentResponder.username
            : 'No guard currently responding';
    }
    const n = selectedGuardIds.value.length;
    return n
        ? `${n} guard${n === 1 ? '' : 's'} selected`
        : 'No guards selected yet';
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

<template>
    <div
        class="ac-card"
        :class="{ 'ac-card--escalated': escalated, 'ac-card--new': isNew }"
    >
        <button
            v-if="isNew"
            type="button"
            class="ac-new-ribbon"
            @click="$emit('seen', alert.id)"
        >
            <Siren :size="13" />
            New Alert — tap to dismiss
        </button>

        <!-- Header -->
        <div class="ac-card__header">
            <div>
                <p class="ac-card__household">{{ alert.household_name }}</p>
                <p v-if="alert.household_phone" class="ac-card__phone">
                    {{ alert.household_phone }}
                </p>
                <p class="ac-card__meta">{{ alert.channel_name }}</p>

                <p v-if="isEstateUnitOnly" class="ac-unit-callout">
                    <strong>Unit {{ alert.unit_number }}</strong>
                </p>
                <p v-else-if="isRegisteredAddress" class="ac-card__address">
                    {{ registeredAddressDisplay }}
                </p>
                <p v-else-if="gpsAddressDisplay" class="ac-card__address">
                    {{ gpsAddressDisplay }}
                    <span
                        v-if="isStaleFallbackAddress"
                        class="ac-address-approx"
                        >(approximate)</span
                    >
                </p>
                <p
                    v-else-if="isGpsSource && gpsAddressLoading"
                    class="ac-card__address ac-card__address--pending"
                >
                    Resolving address…
                </p>
                <p v-else-if="alert.home_address" class="ac-card__address">
                    {{ alert.home_address }}
                </p>
            </div>
            <div class="ac-card__header-right">
                <span class="ac-badge" :class="typeMeta.badge">{{
                    typeMeta.label
                }}</span>
                <p class="ac-card__time">
                    {{ formattedDateTime }}
                </p>
            </div>
        </div>

        <p v-if="escalated" class="ac-escalation-flag">
            No guard acknowledgement &gt; 90s
        </p>
        <p v-else-if="formattedAckTime" class="ac-ack-flag">
            <CheckCircle2 :size="13" />
            Acknowledged {{ formattedAckTime }}
        </p>

        <!-- Map thumbnail -->
        <div class="ac-map-thumb">
            <div
                v-if="hasRealLocation"
                ref="thumbEl"
                class="ac-map-thumb__map"
            ></div>
            <span v-else class="ac-map-thumb__empty">No location yet</span>
            <button
                v-if="hasRealLocation"
                type="button"
                class="ac-map-thumb__overlay"
                aria-label="Expand map"
                @click="mapFullscreen = true"
            >
                <span class="ac-map-thumb__expand">Expand</span>
            </button>
        </div>
        <p v-if="coordsLabel" class="ac-coords">
            {{ coordsLabel }}
            <span v-if="accuracyLabel" class="ac-accuracy"
                >({{ accuracyLabel }})</span
            >
        </p>

        <!-- Responder status -->
        <div v-if="alert.currentResponder" class="ac-responder">
            <p class="ac-responder__label">RESPONDING GUARD</p>
            <p class="ac-responder__name">
                {{ alert.currentResponder.username }}
            </p>
            <p v-if="alert.currentResponder.phone" class="ac-responder__phone">
                {{ alert.currentResponder.phone }}
            </p>
            <p v-if="responderDistanceLabel" class="ac-responder__distance">
                {{ responderDistanceLabel }} away · ~{{ etaMinutes }} min
            </p>
            <p
                v-else
                class="ac-responder__distance ac-responder__distance--pending"
            >
                Waiting for responder location…
            </p>
        </div>
        <p v-else-if="alert.acknowledgedBy?.length" class="ac-guardian-line">
            Acknowledged by {{ alert.acknowledgedBy.join(', ') }} - not yet
            responding
        </p>

        <!-- Guardian notification summary -->
        <p class="ac-guardian-line">
            Notified {{ alert.guardian_count ?? 0 }} paired guardian{{
                (alert.guardian_count ?? 0) === 1 ? '' : 's'
            }}
            <button
                v-if="alert.guardian_count"
                class="ac-link-btn"
                @click="expanded = !expanded"
            >
                {{ expanded ? 'hide' : 'view' }}
            </button>
        </p>

        <!-- Actions -->
        <div class="ac-actions">
            <button
                v-if="!isDV"
                class="ac-toggle-btn"
                :class="{ 'ac-toggle-btn--muted-tone': isPanicLike }"
                @click="logCall('attempted')"
            >
                {{ isPanicLike ? 'Verify by phone' : 'Call household' }}
            </button>

            <button
                class="ac-toggle-btn"
                :class="{ 'ac-toggle-btn--on': alert.muted }"
                :disabled="isPanicLike"
                @click="$emit('mute', alert.id, !alert.muted)"
            >
                {{ alert.muted ? 'Unmute' : 'Mute' }}
            </button>

            <button class="ac-toggle-btn" @click="expanded = !expanded">
                {{ expanded ? 'Collapse' : 'Full journey' }}
            </button>

            <div class="ac-resolve-wrapper">
                <select class="ac-resolve-select" @change="onResolveChange">
                    <option value="" disabled selected>Resolve as…</option>
                    <option value="household_safe">
                        Household confirmed safe
                    </option>
                    <option value="guard_handled">
                        Guard attended / handled
                    </option>
                    <option value="false_alarm">False alarm</option>
                    <option value="escalated_external">
                        Escalated externally
                    </option>
                </select>
            </div>
        </div>

        <!-- Expanded: timeline + guardian list -->
        <transition name="ac-slide-down">
            <div v-if="expanded" class="ac-expanded">
                <div v-if="alert.guardians?.length" class="ac-expanded__block">
                    <p class="ac-expanded__label">Paired guardians notified</p>
                    <ul class="ac-expanded__list ac-guardian-list">
                        <li v-for="g in alert.guardians" :key="g.id">
                            <span class="ac-guardian-list__name">{{
                                g.name
                            }}</span>
                            <span
                                class="ac-guardian-list__status"
                                :class="{
                                    'ac-guardian-list__status--responded':
                                        g.responded_at,
                                }"
                            >
                                {{ guardianStatusLabel(g) }}
                            </span>
                        </li>
                    </ul>
                </div>
                <div
                    v-else-if="alert.guardian_ids?.length"
                    class="ac-expanded__block"
                >
                    <p class="ac-expanded__label">Paired guardians notified</p>
                    <ul class="ac-expanded__list">
                        <li v-for="g in alert.guardian_ids" :key="g">
                            Guardian #{{ g }}
                        </li>
                    </ul>
                </div>

                <div class="ac-expanded__block">
                    <p class="ac-expanded__label">Journey</p>
                    <ol v-if="alert.events?.length" class="ac-timeline">
                        <li v-for="(ev, i) in alert.events" :key="i">
                            <span class="ac-timeline__time">{{
                                new Date(ev.created_at).toLocaleTimeString()
                            }}</span>
                            <span>{{ eventLabel(ev) }}</span>
                        </li>
                    </ol>
                    <p v-else class="ac-timeline__empty">
                        No events recorded yet.
                    </p>
                </div>
            </div>
        </transition>

        <!-- Full-screen map modal -->
        <Teleport to="body">
            <transition name="ac-modal">
                <div
                    v-if="mapFullscreen"
                    class="ac-modal-backdrop"
                    @click.self="mapFullscreen = false"
                >
                    <div class="ac-map-modal">
                        <button
                            class="ac-close-btn"
                            @click="mapFullscreen = false"
                        >
                            <X :size="16" />
                        </button>

                        <div class="ac-map-modal__body">
                            <div
                                v-if="hasRealLocation"
                                class="ac-map-modal__map-wrap"
                            >
                                <div
                                    ref="modalEl"
                                    class="ac-map-modal__map"
                                ></div>
                                <button
                                    type="button"
                                    class="ac-recenter-btn"
                                    @click="recenterMap(modalMap)"
                                >
                                    <Crosshair :size="14" />
                                    Recenter
                                </button>
                            </div>
                            <div v-else class="ac-map-modal__empty">
                                No location data for this alert yet
                            </div>

                            <div class="ac-map-modal__details">
                                <div class="ac-detail-group">
                                    <p class="ac-detail-group__label">
                                        Household
                                    </p>
                                    <p class="ac-detail-row">
                                        <strong>{{
                                            alert.household_name
                                        }}</strong>
                                    </p>
                                    <p
                                        v-if="alert.household_phone"
                                        class="ac-detail-row"
                                    >
                                        {{ alert.household_phone }}
                                    </p>

                                    <!-- Estate admin: single-source view, unchanged behavior -->
                                    <template v-if="isEstateAdmin">
                                        <p
                                            v-if="isEstateUnitOnly"
                                            class="ac-unit-callout ac-unit-callout--modal"
                                        >
                                            <strong
                                                >Unit
                                                {{ alert.unit_number }}</strong
                                            >
                                        </p>
                                        <p
                                            v-else-if="isRegisteredAddress"
                                            class="ac-detail-row"
                                        >
                                            {{ registeredAddressDisplay }}
                                            <span class="ac-detail-row--muted">
                                                ·
                                                {{ locationSourceLabel }}</span
                                            >
                                        </p>
                                        <p
                                            v-else-if="gpsAddressDisplay"
                                            class="ac-detail-row"
                                        >
                                            {{ gpsAddressDisplay }}
                                            <span class="ac-detail-row--muted">
                                                ·
                                                {{ locationSourceLabel }}</span
                                            >
                                            <span
                                                v-if="isStaleFallbackAddress"
                                                class="ac-address-approx"
                                            >
                                                (approximate)</span
                                            >
                                        </p>
                                        <p
                                            v-else-if="
                                                isGpsSource && gpsAddressLoading
                                            "
                                            class="ac-detail-row ac-detail-row--muted"
                                        >
                                            Resolving address…
                                        </p>
                                        <p
                                            v-else-if="alert.home_address"
                                            class="ac-detail-row"
                                        >
                                            {{ alert.home_address }}
                                        </p>
                                    </template>

                                    <!-- Echo Link admin: always show both registered info and live GPS -->
                                    <template v-else>
                                        <p
                                            v-if="registeredAddressAlways"
                                            class="ac-detail-row"
                                        >
                                            {{ registeredAddressAlways }}
                                            <span class="ac-detail-row--muted">
                                                · Registered address</span
                                            >
                                        </p>
                                        <p
                                            v-if="
                                                hasRealLocation &&
                                                gpsAddressLabel
                                            "
                                            class="ac-detail-row"
                                        >
                                            {{ gpsAddressLabel }}
                                            <span class="ac-detail-row--muted">
                                                · Live GPS</span
                                            >
                                        </p>
                                        <p
                                            v-else-if="
                                                hasRealLocation &&
                                                gpsAddressLoading
                                            "
                                            class="ac-detail-row ac-detail-row--muted"
                                        >
                                            Resolving GPS address…
                                        </p>
                                        <p
                                            v-else-if="
                                                hasRealLocation &&
                                                gpsAddressFailed
                                            "
                                            class="ac-detail-row ac-detail-row--muted"
                                        >
                                            GPS coordinates only (address lookup
                                            failed)
                                        </p>
                                    </template>

                                    <p
                                        v-if="coordsLabel"
                                        class="ac-detail-row ac-detail-row--mono"
                                    >
                                        {{ coordsLabel }}
                                        <span v-if="accuracyLabel"
                                            >({{ accuracyLabel }})</span
                                        >
                                    </p>
                                </div>

                                <div
                                    v-if="alert.currentResponder"
                                    class="ac-detail-group"
                                >
                                    <p class="ac-detail-group__label">
                                        Responding guard
                                    </p>
                                    <p class="ac-detail-row">
                                        <strong>{{
                                            alert.currentResponder.username
                                        }}</strong>
                                    </p>
                                    <p
                                        v-if="alert.currentResponder.phone"
                                        class="ac-detail-row"
                                    >
                                        {{ alert.currentResponder.phone }}
                                    </p>
                                    <p
                                        v-if="responderDistanceLabel"
                                        class="ac-detail-row"
                                    >
                                        {{ responderDistanceLabel }} away · ~{{
                                            etaMinutes
                                        }}
                                        min ETA (straight-line estimate)
                                    </p>
                                </div>
                                <div v-else class="ac-detail-group">
                                    <p class="ac-detail-group__label">
                                        Responding guard
                                    </p>
                                    <p
                                        class="ac-detail-row ac-detail-row--muted"
                                    >
                                        No guard responding yet
                                    </p>
                                </div>

                                <!-- Dispatch guard — estate admin only -->
                                <div
                                    v-if="isEstateAdmin"
                                    class="ac-detail-group ac-dispatch-group"
                                >
                                    <p class="ac-detail-group__label">
                                        <UserPlus :size="12" />
                                        Dispatch guard
                                    </p>
                                    <div
                                        ref="dispatchWrapEl"
                                        class="ac-searchselect"
                                    >
                                        <button
                                            type="button"
                                            class="ac-searchselect__trigger mt-2"
                                            @click="
                                                dispatchOpen = !dispatchOpen
                                            "
                                        >
                                            <span
                                                v-if="selectedDispatchGuard"
                                                class="ac-searchselect__value"
                                            >
                                                {{
                                                    selectedDispatchGuard.username
                                                }}{{
                                                    selectedDispatchGuard.phone
                                                        ? ' · ' +
                                                          selectedDispatchGuard.phone
                                                        : ''
                                                }}
                                            </span>
                                            <span
                                                v-else
                                                class="ac-searchselect__placeholder"
                                                >Select a guard…</span
                                            >
                                            <ChevronDown :size="14" />
                                        </button>

                                        <div
                                            v-if="dispatchOpen"
                                            class="ac-searchselect__panel"
                                        >
                                            <input
                                                v-model="dispatchQuery"
                                                type="text"
                                                class="ac-searchselect__search"
                                                placeholder="Search guards…"
                                                @click.stop
                                            />
                                            <ul class="ac-searchselect__list">
                                                <li
                                                    v-for="g in filteredDispatchGuards"
                                                    :key="g.id"
                                                    class="ac-searchselect__option"
                                                    :class="{
                                                        'ac-searchselect__option--active':
                                                            g.id ===
                                                            selectedDispatchGuardId,
                                                    }"
                                                    @click="
                                                        selectDispatchGuard(g)
                                                    "
                                                >
                                                    {{ g.username
                                                    }}{{
                                                        g.phone
                                                            ? ' · ' + g.phone
                                                            : ''
                                                    }}
                                                    <Check
                                                        v-if="
                                                            g.id ===
                                                            selectedDispatchGuardId
                                                        "
                                                        :size="14"
                                                    />
                                                </li>
                                                <li
                                                    v-if="
                                                        !filteredDispatchGuards.length
                                                    "
                                                    class="ac-searchselect__empty"
                                                >
                                                    No guards match
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                    <p
                                        v-if="channelGuards.length === 0"
                                        class="ac-dispatch-empty"
                                    >
                                        No guards found for this channel.
                                    </p>
                                    <button
                                        type="button"
                                        class="ac-dispatch-btn"
                                        :class="{
                                            'ac-dispatch-btn--ready':
                                                !!selectedDispatchGuardId &&
                                                !dispatching,
                                        }"
                                        :disabled="
                                            !selectedDispatchGuardId ||
                                            dispatching
                                        "
                                        @click="dispatchSelectedGuard"
                                    >
                                        {{
                                            dispatching
                                                ? 'Dispatching…'
                                                : alert.currentResponder
                                                  ? 'Reassign guard'
                                                  : 'Dispatch guard'
                                        }}
                                    </button>
                                    <p
                                        v-if="dispatchError"
                                        class="ac-dispatch-error"
                                    >
                                        {{ dispatchError }}
                                    </p>
                                </div>

                                <div class="ac-detail-group">
                                    <p class="ac-detail-group__label">Alert</p>
                                    <p class="ac-detail-row">
                                        {{ typeMeta.label }} ·
                                        {{ formattedDateTime }}
                                    </p>
                                    <p
                                        v-if="formattedAckTime"
                                        class="ac-detail-row"
                                    >
                                        Acknowledged {{ formattedAckTime }}
                                    </p>
                                    <p
                                        v-else-if="escalated"
                                        class="ac-detail-row ac-detail-row--warn"
                                    >
                                        No guard acknowledgement &gt; 90s
                                    </p>
                                    <p class="ac-detail-row">
                                        Notified
                                        {{ alert.guardian_count ?? 0 }}
                                        paired guardian{{
                                            (alert.guardian_count ?? 0) === 1
                                                ? ''
                                                : 's'
                                        }}
                                    </p>
                                </div>

                                <!-- Notify guards — redesigned: "Send to" and "Message" are
                                     visually separate steps, with a plain-language recipient
                                     summary so it's always clear who a broadcast will reach. -->
                                <div class="ac-detail-group ac-notify-group">
                                    <p class="ac-detail-group__label">
                                        <Megaphone :size="12" />
                                        Notify guards
                                    </p>

                                    <div class="ac-notify-step">
                                        <p class="ac-notify-step__label">
                                            1. Send to
                                        </p>
                                        <div class="ac-notify-target">
                                            <button
                                                type="button"
                                                class="ac-notify-target__btn"
                                                :class="{
                                                    'ac-notify-target__btn--active':
                                                        notifyTarget === 'all',
                                                }"
                                                @click="notifyTarget = 'all'"
                                            >
                                                All in channel ({{
                                                    channelGuards.length
                                                }})
                                            </button>
                                            <button
                                                v-if="alert.currentResponder"
                                                type="button"
                                                class="ac-notify-target__btn"
                                                :class="{
                                                    'ac-notify-target__btn--active':
                                                        notifyTarget ===
                                                        'responder',
                                                }"
                                                @click="
                                                    notifyTarget = 'responder'
                                                "
                                            >
                                                Responding guard
                                            </button>
                                            <button
                                                type="button"
                                                class="ac-notify-target__btn"
                                                :class="{
                                                    'ac-notify-target__btn--active':
                                                        notifyTarget ===
                                                        'selected',
                                                }"
                                                @click="
                                                    notifyTarget = 'selected'
                                                "
                                            >
                                                Choose specific
                                            </button>
                                        </div>

                                        <div
                                            v-if="notifyTarget === 'selected'"
                                            ref="notifyWrapEl"
                                            class="ac-searchselect"
                                        >
                                            <button
                                                type="button"
                                                class="ac-searchselect__trigger"
                                                @click="
                                                    notifyOpen = !notifyOpen
                                                "
                                            >
                                                <span
                                                    v-if="
                                                        selectedNotifyGuards.length
                                                    "
                                                    class="ac-searchselect__value"
                                                >
                                                    {{
                                                        selectedNotifyGuards.length
                                                    }}
                                                    guard{{
                                                        selectedNotifyGuards.length ===
                                                        1
                                                            ? ''
                                                            : 's'
                                                    }}
                                                    selected
                                                </span>
                                                <span
                                                    v-else
                                                    class="ac-searchselect__placeholder"
                                                    >Select guards…</span
                                                >
                                                <ChevronDown :size="14" />
                                            </button>

                                            <div
                                                v-if="notifyOpen"
                                                class="ac-searchselect__panel"
                                            >
                                                <input
                                                    v-model="notifyQuery"
                                                    type="text"
                                                    class="ac-searchselect__search"
                                                    placeholder="Search guards…"
                                                    @click.stop
                                                />
                                                <ul
                                                    class="ac-searchselect__list"
                                                >
                                                    <li
                                                        v-for="g in filteredNotifyGuards"
                                                        :key="g.id"
                                                        class="ac-searchselect__option"
                                                        :class="{
                                                            'ac-searchselect__option--active':
                                                                selectedGuardIds.includes(
                                                                    g.id,
                                                                ),
                                                        }"
                                                        @click="
                                                            toggleNotifyGuard(g)
                                                        "
                                                    >
                                                        {{ g.username
                                                        }}{{
                                                            g.phone
                                                                ? ' · ' +
                                                                  g.phone
                                                                : ''
                                                        }}
                                                        <Check
                                                            v-if="
                                                                selectedGuardIds.includes(
                                                                    g.id,
                                                                )
                                                            "
                                                            :size="14"
                                                        />
                                                    </li>
                                                    <li
                                                        v-if="
                                                            !filteredNotifyGuards.length
                                                        "
                                                        class="ac-searchselect__empty"
                                                    >
                                                        No guards match
                                                    </li>
                                                </ul>
                                            </div>

                                            <div
                                                v-if="
                                                    selectedNotifyGuards.length
                                                "
                                                class="ac-searchselect__chips"
                                            >
                                                <span
                                                    v-for="g in selectedNotifyGuards"
                                                    :key="g.id"
                                                    class="ac-searchselect__chip"
                                                >
                                                    {{ g.username }}
                                                    <button
                                                        type="button"
                                                        @click="
                                                            toggleNotifyGuard(g)
                                                        "
                                                        aria-label="Remove"
                                                    >
                                                        <X :size="10" />
                                                    </button>
                                                </span>
                                            </div>
                                        </div>

                                        <p class="ac-notify-recipient">
                                            Sending to:
                                            <strong>{{
                                                notifyRecipientSummary
                                            }}</strong>
                                        </p>
                                    </div>

                                    <div class="ac-notify-step">
                                        <p class="ac-notify-step__label">
                                            2. Message
                                        </p>

                                        <div class="ac-notify-templates">
                                            <span
                                                class="ac-notify-templates__label"
                                                >Quick fill:</span
                                            >
                                            <button
                                                v-for="q in quickMessages"
                                                :key="q.label"
                                                type="button"
                                                class="ac-notify-chip"
                                                @click="
                                                    applyQuickMessage(q.text)
                                                "
                                            >
                                                {{ q.label }}
                                            </button>
                                        </div>

                                        <textarea
                                            v-model="notifyMessage"
                                            class="ac-notify-textarea"
                                            rows="3"
                                            maxlength="240"
                                            placeholder="Message to broadcast as a push notification…"
                                        ></textarea>

                                        <div class="ac-notify-footer">
                                            <span class="ac-notify-count"
                                                >{{
                                                    notifyMessage.length
                                                }}/240</span
                                            >
                                            <button
                                                type="button"
                                                class="ac-notify-send"
                                                :class="{
                                                    'ac-notify-send--sent':
                                                        notifySent,
                                                }"
                                                :disabled="
                                                    !notifyMessage.trim() ||
                                                    !notifyTargetCount
                                                "
                                                @click="sendNotification"
                                            >
                                                <Send :size="13" />
                                                {{
                                                    notifySent
                                                        ? 'Sent ✓'
                                                        : 'Broadcast'
                                                }}
                                            </button>
                                        </div>
                                    </div>

                                    <p class="ac-notify-hint">
                                        One-way push announcement — guards can't
                                        reply here.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>
    </div>
</template>

<style scoped>
.ac-card {
    --c-primary: #ea580c;
    --c-primary-h: #c2410c;
    --c-text: #1a2332;
    --c-muted: #64748b;
    --c-faint: #94a3b8;
    --c-border: #e4e8ef;
    font-family: 'DM Sans', system-ui, sans-serif;
    background: #ffffff;
    border: 1px solid var(--c-border);
    border-radius: 16px;
    padding: 18px 20px;
    position: relative;
    box-shadow:
        0 1px 3px rgba(0, 0, 0, 0.06),
        0 1px 2px rgba(0, 0, 0, 0.04);
    transition:
        box-shadow 0.2s,
        transform 0.2s;
}
.ac-card--escalated {
    border-color: #fca5a5;
    animation: ac-pulse 1.4s ease-in-out infinite;
}
@keyframes ac-pulse {
    0%,
    100% {
        box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.25);
    }
    50% {
        box-shadow: 0 0 0 6px rgba(220, 38, 38, 0.08);
    }
}

.ac-card--new {
    border: 3px solid #f59e0b;
    background: #fffbeb;
    animation: ac-new-pulse 1.1s ease-in-out infinite;
}
@keyframes ac-new-pulse {
    0%,
    100% {
        box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.5);
    }
    50% {
        box-shadow: 0 0 0 14px rgba(245, 158, 11, 0.12);
    }
}
.ac-new-ribbon {
    position: absolute;
    top: -12px;
    left: 16px;
    background: #f59e0b;
    color: #fff;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.4px;
    padding: 4px 12px;
    border: none;
    border-radius: 6px;
    text-transform: uppercase;
    box-shadow: 0 2px 8px rgba(245, 158, 11, 0.45);
    animation: ac-ribbon-bounce 0.5s ease-in-out infinite alternate;
    z-index: 1;
    cursor: pointer;
    font-family: inherit;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.ac-new-ribbon:hover {
    background: #d97706;
}
@keyframes ac-ribbon-bounce {
    from {
        transform: translateY(0);
    }
    to {
        transform: translateY(-3px);
    }
}

.ac-card__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}
.ac-card__household {
    font-size: 14px;
    font-weight: 700;
    color: var(--c-text);
    margin: 0;
}
.ac-card__phone {
    font-size: 12px;
    font-weight: 600;
    color: var(--c-muted);
    margin: 1px 0 0;
}
.ac-card__meta {
    font-size: 12px;
    color: var(--c-faint);
    margin: 1px 0 0;
}
.ac-card__address {
    font-size: 11px;
    color: var(--c-muted);
    margin: 2px 0 0;
}
.ac-coords {
    margin: 6px 0 0;
    font-size: 11px;
    color: var(--c-faint);
    font-variant-numeric: tabular-nums;
}
.ac-accuracy {
    color: var(--c-faint);
    font-weight: 500;
}
.ac-card__header-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
}
.ac-card__time {
    font-size: 11px;
    color: var(--c-faint);
    margin: 0;
}

.ac-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}
.ac-badge--panic {
    background: #fef2f2;
    color: #dc2626;
}
.ac-badge--dv {
    background: #f5f3ff;
    color: #dc2626;
}
.ac-badge--guardian {
    background: #fff7ed;
    color: #ea580c;
}
.ac-badge--general {
    background: #f1f5f9;
    color: #475569;
}

.ac-escalation-flag {
    margin: 8px 0 0;
    font-size: 11px;
    font-weight: 700;
    color: #dc2626;
}
.ac-ack-flag {
    margin: 8px 0 0;
    font-size: 11px;
    font-weight: 700;
    color: #16a34a;
    display: flex;
    align-items: center;
    gap: 5px;
}

.ac-map-thumb {
    margin-top: 12px;
    width: 100%;
    height: 96px;
    border-radius: 10px;
    background: #f1f5f9;
    border: 1px solid var(--c-border);
    position: relative;
    overflow: hidden;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}
.ac-map-thumb__map {
    width: 100%;
    height: 100%;
}
.ac-map-thumb__empty {
    font-size: 12px;
    color: var(--c-faint);
    font-weight: 600;
}
.ac-map-thumb__overlay {
    position: absolute;
    inset: 0;
    background: transparent;
    border: none;
    padding: 0;
    cursor: pointer;
    z-index: 500;
}
.ac-map-thumb__expand {
    position: absolute;
    bottom: 6px;
    right: 6px;
    font-size: 10px;
    font-weight: 600;
    color: #fff;
    background: rgba(26, 35, 50, 0.65);
    padding: 2px 8px;
    border-radius: 6px;
}

.ac-guardian-line {
    margin: 10px 0 0;
    font-size: 12px;
    color: var(--c-muted);
}
.ac-responder {
    margin-top: 12px;
    padding: 12px;
    background: #f0fdf4;
    border: 1.5px solid #86efac;
    border-radius: 10px;
}
.ac-responder__label {
    font-size: 10px;
    font-weight: 700;
    color: #16a34a;
    letter-spacing: 0.5px;
    margin: 0 0 4px;
}
.ac-responder__name {
    font-size: 13px;
    font-weight: 700;
    color: var(--c-text);
    margin: 0;
}
.ac-responder__phone {
    font-size: 12px;
    color: var(--c-muted);
    margin: 2px 0 0;
}
.ac-responder__distance {
    font-size: 12px;
    font-weight: 600;
    color: #16a34a;
    margin: 6px 0 0;
}
.ac-responder__distance--pending {
    color: var(--c-faint);
    font-weight: 500;
    font-style: italic;
}
.ac-link-btn {
    background: none;
    border: none;
    color: var(--c-primary);
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    padding: 0;
    margin-left: 4px;
    font-family: inherit;
    text-decoration: underline;
    text-underline-offset: 2px;
}
.ac-link-btn:hover {
    color: var(--c-primary-h);
}

.ac-actions {
    margin-top: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.ac-toggle-btn {
    padding: 7px 12px;
    background: #f8fafc;
    border: 1.5px solid var(--c-border);
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    color: var(--c-muted);
    cursor: pointer;
    transition: all 0.15s;
    font-family: inherit;
    white-space: nowrap;
}
.ac-toggle-btn:hover:not(:disabled) {
    border-color: #cbd5e1;
}
.ac-toggle-btn--on {
    border-color: var(--c-primary);
    background: #fff7ed;
    color: var(--c-primary);
}
.ac-toggle-btn--muted-tone {
    color: var(--c-faint);
}
.ac-toggle-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.ac-resolve-wrapper {
    margin-left: auto;
}
.ac-resolve-select {
    font-family: inherit;
    font-size: 12px;
    font-weight: 600;
    color: #16a34a;
    background: #fff;
    border: 1.5px solid #86efac;
    border-radius: 8px;
    padding: 7px 10px;
    cursor: pointer;
    outline: none;
}

.ac-expanded {
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid var(--c-border);
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.ac-expanded__label {
    font-size: 11px;
    font-weight: 700;
    color: var(--c-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0 0 6px;
}
.ac-expanded__list {
    margin: 0;
    padding-left: 18px;
    font-size: 12px;
    color: var(--c-muted);
}
.ac-guardian-list {
    padding-left: 0;
    list-style: none;
}
.ac-guardian-list li {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 8px;
    padding: 3px 0;
}
.ac-guardian-list__name {
    font-weight: 600;
    color: var(--c-text);
}
.ac-guardian-list__status {
    color: var(--c-faint);
    font-style: italic;
    white-space: nowrap;
    font-size: 11px;
}
.ac-guardian-list__status--responded {
    color: #16a34a;
    font-style: normal;
    font-weight: 600;
}
.ac-timeline {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.ac-timeline li {
    font-size: 12px;
    color: var(--c-muted);
    display: flex;
    gap: 8px;
}
.ac-timeline__time {
    color: var(--c-faint);
    flex-shrink: 0;
}
.ac-timeline__empty {
    font-size: 12px;
    color: var(--c-faint);
    font-style: italic;
    margin: 0;
}

.ac-slide-down-enter-active,
.ac-slide-down-leave-active {
    transition: all 0.2s ease;
}
.ac-slide-down-enter-from,
.ac-slide-down-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}

.ac-modal-backdrop {
    --c-primary: #ea580c;
    --c-primary-h: #c2410c;
    --c-text: #1a2332;
    --c-muted: #64748b;
    --c-faint: #94a3b8;
    --c-border: #e4e8ef;
    position: fixed;
    inset: 0;
    background: rgba(10, 18, 30, 0.55);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 24px;
}
.ac-map-modal {
    background: #fff;
    border-radius: 20px;
    width: 100%;
    max-width: 1100px;
    height: 80vh;
    position: relative;
    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.18);
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
}
.ac-map-modal__body {
    display: flex;
    width: 100%;
    height: 100%;
}
.ac-map-modal__map-wrap {
    flex: 2 1 0;
    height: 100%;
    position: relative;
}
.ac-map-modal__map {
    width: 100%;
    height: 100%;
}
.ac-recenter-btn {
    position: absolute;
    bottom: 12px;
    left: 12px;
    z-index: 1000;
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 7px 12px;
    background: #fff;
    border: none;
    border-radius: 8px;
    font-family: inherit;
    font-size: 12px;
    font-weight: 700;
    color: var(--c-text);
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    transition: background 0.15s;
}
.ac-recenter-btn:hover {
    background: #f1f5f9;
}
.ac-map-modal__empty {
    flex: 2 1 0;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    color: var(--c-faint);
    font-weight: 600;
}
.ac-map-modal__details {
    flex: 1 1 300px;
    max-width: 320px;
    height: 100%;
    overflow-y: auto;
    padding: 22px 20px;
    border-left: 1px solid var(--c-border);
    background: #f8fafc;
}
.ac-detail-group {
    margin-bottom: 18px;
}
.ac-detail-group__label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--c-muted);
    margin: 0 0 6px;
    display: flex;
    align-items: center;
    gap: 5px;
}
.ac-detail-row {
    font-size: 13px;
    color: var(--c-text);
    margin: 2px 0;
}
.ac-detail-row--mono {
    font-variant-numeric: tabular-nums;
    color: var(--c-muted);
    font-size: 12px;
}
.ac-detail-row--muted {
    color: var(--c-faint);
    font-style: italic;
}
.ac-detail-row--warn {
    color: #dc2626;
    font-weight: 700;
}
.ac-close-btn {
    position: absolute;
    top: 12px;
    right: 12px;
    z-index: 1000;
    width: 34px;
    height: 34px;
    background: #fff;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    transition: background 0.15s;
}
.ac-close-btn:hover {
    background: #f1f5f9;
}

.ac-modal-enter-active,
.ac-modal-leave-active {
    transition: opacity 0.22s ease;
}
.ac-modal-enter-from,
.ac-modal-leave-to {
    opacity: 0;
}

/* Dispatch guard panel — deliberately high-contrast, distinct from the
   Notify panel below it, so the button state is unambiguous at a glance. */
.ac-dispatch-group {
    padding: 12px;
    background: #eff6ff;
    border: 1.5px solid #bfdbfe;
    border-radius: 10px;
}
.ac-dispatch-select {
    width: 100%;
    font-family: inherit;
    font-size: 12px;
    font-weight: 600;
    color: var(--c-text);
    background: #fff;
    border: 1.5px solid #bfdbfe;
    border-radius: 8px;
    padding: 8px 10px;
    outline: none;
    margin-bottom: 8px;
    cursor: pointer;
}
.ac-dispatch-select:focus {
    border-color: #2563eb;
}
.ac-dispatch-empty {
    font-size: 11px;
    color: var(--c-faint);
    font-style: italic;
    margin: 0 0 8px;
}
.ac-dispatch-btn {
    width: 100%;
    padding: 9px 14px;
    font-family: inherit;
    font-size: 12.5px;
    font-weight: 700;
    border-radius: 8px;
    border: 1.5px solid #cbd5e1;
    background: #e2e8f0;
    color: #94a3b8;
    cursor: not-allowed;
    transition: all 0.15s;
}
.ac-dispatch-btn--ready {
    background: #2563eb;
    border-color: #2563eb;
    color: #fff;
    cursor: pointer;
}
.ac-dispatch-btn--ready:hover {
    background: #1d4ed8;
    border-color: #1d4ed8;
}
.ac-dispatch-error {
    margin: 8px 0 0;
    font-size: 11px;
    font-weight: 600;
    color: #dc2626;
}

/* Guard broadcast panel — restructured into two clear numbered steps
   (recipient, then message) instead of one undifferentiated cluster of pills. */
.ac-notify-group {
    padding-top: 16px;
    border-top: 1px dashed var(--c-border);
}
.ac-notify-step {
    margin-bottom: 14px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--c-border);
}
.ac-notify-step:last-of-type {
    border-bottom: none;
    padding-bottom: 0;
}
.ac-notify-step__label {
    font-size: 11px;
    font-weight: 700;
    color: var(--c-text);
    margin: 0 0 8px;
}

.ac-notify-target {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 8px;
}
.ac-notify-target__btn {
    padding: 6px 10px;
    font-family: inherit;
    font-size: 11px;
    font-weight: 700;
    color: var(--c-muted);
    background: #fff;
    border: 1.5px solid var(--c-border);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.15s;
}
.ac-notify-target__btn:hover {
    border-color: #cbd5e1;
}
.ac-notify-target__btn--active {
    border-color: #2563eb;
    background: #eff6ff;
    color: #2563eb;
}
.ac-notify-select {
    width: 100%;
    min-height: 72px;
    margin-bottom: 8px;
    font-family: inherit;
    font-size: 12px;
    color: var(--c-text);
    background: #fff;
    border: 1.5px solid var(--c-border);
    border-radius: 8px;
    padding: 6px;
}
.ac-notify-recipient {
    font-size: 11.5px;
    color: var(--c-muted);
    margin: 4px 0 0;
}
.ac-notify-recipient strong {
    color: var(--c-text);
}

.ac-notify-templates {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    margin-bottom: 8px;
}
.ac-notify-templates__label {
    font-size: 11px;
    font-weight: 600;
    color: var(--c-faint);
    margin-right: 2px;
}
.ac-notify-chip {
    padding: 5px 10px;
    font-family: inherit;
    font-size: 11px;
    font-weight: 600;
    color: var(--c-muted);
    background: #f8fafc;
    border: 1px solid var(--c-border);
    border-radius: 20px;
    cursor: pointer;
    transition: all 0.15s;
}
.ac-notify-chip:hover {
    border-color: var(--c-primary);
    color: var(--c-primary);
    background: #fff7ed;
}
.ac-notify-textarea {
    width: 100%;
    resize: vertical;
    font-family: inherit;
    font-size: 12px;
    color: var(--c-text);
    background: #fff;
    border: 1.5px solid var(--c-border);
    border-radius: 8px;
    padding: 8px 10px;
    outline: none;
}
.ac-notify-textarea:focus {
    border-color: var(--c-primary);
}
.ac-notify-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 6px;
}
.ac-notify-count {
    font-size: 10px;
    color: var(--c-faint);
    font-variant-numeric: tabular-nums;
}
.ac-notify-send {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    font-family: inherit;
    font-size: 12px;
    font-weight: 700;
    color: #fff;
    background: var(--c-primary);
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition:
        background 0.15s,
        opacity 0.15s;
}
.ac-notify-send:hover:not(:disabled) {
    background: var(--c-primary-h);
}
.ac-notify-send:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}
.ac-notify-send--sent {
    background: #16a34a;
}
.ac-notify-hint {
    margin: 10px 0 0;
    font-size: 10px;
    color: var(--c-faint);
    font-style: italic;
}
</style>

<!-- Unscoped: Leaflet renders these tooltips outside Vue's compiled template,
     so scoped attribute selectors never reach them. -->
<style>
.ac-leaflet-label {
    font-family: 'DM Sans', system-ui, sans-serif;
    font-size: 11px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 6px;
    border: none;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
    color: #fff;
}
.ac-leaflet-label--household {
    background: #dc2626;
}
.ac-leaflet-label--household::before {
    border-top-color: #dc2626 !important;
}
.ac-leaflet-label--guard {
    background: #2563eb;
}
.ac-leaflet-label--guard::before {
    border-top-color: #2563eb !important;
}

.ac-unit-callout {
    display: inline-flex;
    align-items: center;
    margin: 6px 0 0;
    padding: 4px 12px;
    background: #fef2f2;
    border: 1.5px solid #fca5a5;
    border-radius: 8px;
    font-size: 15px;
    font-weight: 800;
    color: #dc2626;
    letter-spacing: 0.3px;
}
.ac-unit-callout--modal {
    font-size: 16px;
    padding: 6px 14px;
}

.ac-card__address--pending {
    font-style: italic;
    color: var(--c-faint);
}
.ac-address-approx {
    font-style: italic;
    color: #d97706;
    font-weight: 600;
}
.ac-searchselect {
    position: relative;
}
.ac-searchselect__trigger {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 8px 10px;
    font-family: inherit;
    font-size: 12px;
    font-weight: 600;
    color: var(--c-text);
    background: #fff;
    border: 1.5px solid var(--c-border);
    border-radius: 8px;
    cursor: pointer;
    text-align: left;
}
.ac-searchselect__trigger:hover {
    border-color: #cbd5e1;
}
.ac-searchselect__placeholder {
    color: var(--c-faint);
    font-weight: 500;
}
.ac-searchselect__panel {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    z-index: 20;
    background: #fff;
    border: 1.5px solid var(--c-border);
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
    overflow: hidden;
}
.ac-searchselect__search {
    width: 100%;
    padding: 8px 10px;
    font-family: inherit;
    font-size: 12px;
    border: none;
    border-bottom: 1px solid var(--c-border);
    outline: none;
}
.ac-searchselect__list {
    list-style: none;
    margin: 0;
    padding: 4px 0;
    max-height: 180px;
    overflow-y: auto;
}
.ac-searchselect__option {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 8px 10px;
    font-size: 12.5px;
    color: var(--c-text);
    cursor: pointer;
}
.ac-searchselect__option:hover {
    background: #f8fafc;
}
.ac-searchselect__option--active {
    background: #eff6ff;
    color: #1d4ed8;
    font-weight: 600;
}
.ac-searchselect__empty {
    padding: 10px;
    font-size: 12px;
    color: var(--c-faint);
    font-style: italic;
}
.ac-searchselect__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
}
.ac-searchselect__chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 6px 4px 10px;
    font-size: 11px;
    font-weight: 600;
    color: var(--c-text);
    background: #f1f5f9;
    border-radius: 20px;
}
.ac-searchselect__chip button {
    display: flex;
    align-items: center;
    background: none;
    border: none;
    cursor: pointer;
    color: var(--c-faint);
    padding: 2px;
}
.ac-searchselect__chip button:hover {
    color: #dc2626;
}
</style>

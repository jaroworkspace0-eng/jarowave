<script setup>
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { CheckCircle2, Siren, X } from 'lucide-vue-next';
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
const emit = defineEmits(['mute', 'call-log', 'resolve', 'seen']);

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

function drawAlertOnMap(map) {
    if (!map) return null;
    const layer = L.layerGroup().addTo(map);
    const points = [];

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
            .bindTooltip('Household')
            .addTo(layer);
        points.push([hLat, hLng]);
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
            .bindTooltip('Guard')
            .addTo(layer);
        points.push([gLat, gLng]);
    }

    if (routeCoords.value?.length) {
        L.polyline(routeCoords.value, {
            color: '#2563eb',
            weight: 4,
            opacity: 0.75,
        }).addTo(layer);
    }

    if (points.length === 2) {
        map.fitBounds(points, { padding: [24, 24] });
    } else if (points.length === 1) {
        map.setView(points[0], 15);
    }

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
                <p v-if="alert.home_address" class="ac-card__address">
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
            <span v-else class="ac-dv-note"
                >Silent alert — guardians/guards notified only</span
            >

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
                                ref="modalEl"
                                class="ac-map-modal__map"
                            ></div>
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
                                    <p
                                        v-if="alert.home_address"
                                        class="ac-detail-row"
                                    >
                                        {{ alert.home_address }}
                                    </p>
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
    color: #7c3aed;
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

.ac-dv-note {
    font-size: 12px;
    color: #7c3aed;
    font-style: italic;
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
.ac-map-modal__map {
    flex: 2 1 0;
    height: 100%;
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
</style>

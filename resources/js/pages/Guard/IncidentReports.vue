<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import {
    CheckCircle2,
    Crosshair,
    MapPin,
    Siren,
    UserCheck,
    X,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';

const breadcrumbs = [];

const reports = ref({ data: [], total: 0, from: 0, to: 0, links: [] });
const reportList = ref([]);
const loading = ref(false);
const searchQuery = ref('');
const filterStatus = ref('');
const filterOutcome = ref('');
let searchTimeout = null;

const today = new Date().toISOString().split('T')[0];
const firstOfMonth = new Date(
    new Date().getFullYear(),
    new Date().getMonth(),
    1,
)
    .toISOString()
    .split('T')[0];
const dateFrom = ref(firstOfMonth);
const dateTo = ref(today);
const dateError = ref('');

const flash = ref(null);

const getHeaders = () => ({
    headers: { Authorization: `Bearer ${localStorage.getItem('token')}` },
});

function showFlash(msg, type = 'success') {
    flash.value = { msg, type };
    setTimeout(() => (flash.value = null), 5000);
}

function validateDates() {
    if (!dateFrom.value || !dateTo.value) {
        dateError.value = 'Both dates are required.';
        return false;
    }
    if (new Date(dateTo.value) < new Date(dateFrom.value)) {
        dateError.value = '"To" date must be after "From" date.';
        return false;
    }
    dateError.value = '';
    return true;
}

async function loadReports(url) {
    if (!validateDates()) return;
    loading.value = true;
    try {
        const { data } = await axios.get(
            url || `${import.meta.env.VITE_APP_URL}/api/guard/incident-reports`,
            {
                params: {
                    search: searchQuery.value || undefined,
                    status: filterStatus.value || undefined,
                    outcome: filterOutcome.value || undefined,
                    date_from: dateFrom.value,
                    date_to: dateTo.value,
                },
                ...getHeaders(),
            },
        );
        reports.value = data;
        reportList.value = data.data;
    } catch {
        showFlash('Failed to load reports.', 'error');
    } finally {
        loading.value = false;
    }
}

function handleSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => loadReports(), 400);
}

function setStatus(v) {
    filterStatus.value = v;
    loadReports();
}

function setOutcome(v) {
    filterOutcome.value = v;
    loadReports();
}

// ══════════ VIEW-DETAIL (read only) ══════════
const selectedReport = ref(null);
const showDetail = ref(false);

function openDetail(report) {
    selectedReport.value = report;
    showDetail.value = true;
    nextTick(() => initMap());
}
function closeDetail() {
    showDetail.value = false;
    selectedReport.value = null;
    destroyMap();
}

// ══════════ Date/number formatting ══════════

function toDate(d) {
    if (!d) return null;
    let s = String(d).trim();
    // Already has an explicit UTC/offset marker — parse as-is.
    if (/Z$|[+-]\d{2}:?\d{2}$/.test(s)) return new Date(s);
    // Bare "YYYY-MM-DD HH:mm:ss" (raw MySQL/Carbon output, no timezone) is
    // parsed as LOCAL time by the browser, but the backend stores/serves
    // these as UTC — normalize so it matches fields that already carry an
    // offset (e.g. location_updated_at), fixing the "wrong time" bug.
    s = s.replace(' ', 'T');
    return new Date(/T\d{2}:\d{2}/.test(s) ? s + 'Z' : s);
}

function fmtDate(d) {
    const date = toDate(d);
    if (!date) return '—';
    return date.toLocaleDateString('en-ZA', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}
function fmtDateTime(d) {
    const date = toDate(d);
    if (!date) return '—';
    return date.toLocaleString('en-ZA', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function fmtUnit(u) {
    if (!u) return '';
    const s = String(u).trim();
    return /unit/i.test(s) ? s : `Unit ${s}`;
}

function fmtDistance(v) {
    if (v === null || v === undefined || v === '') return '—';
    const n = Number(v);
    if (isNaN(n)) return '—';
    // Assumes backend sends meters — adjust if it's already km.
    return n >= 1000 ? `${(n / 1000).toFixed(2)} km` : `${Math.round(n)} m`;
}

function fmtAccuracy(v) {
    if (v === null || v === undefined || v === '') return '—';
    const n = Number(v);
    if (isNaN(n)) return '—';
    return `${Math.round(n)} m`;
}

function fmtDuration(v) {
    if (v === null || v === undefined || v === '') return '—';
    const n = Number(v);
    if (isNaN(n)) return '—';
    // Assumes backend sends seconds — adjust if it's already minutes.
    if (n < 60) return `${Math.round(n)} sec`;
    if (n < 3600) {
        const mins = Math.floor(n / 60);
        const secs = Math.round(n % 60);
        return secs > 0 ? `${mins} min ${secs} sec` : `${mins} min`;
    }
    const hrs = Math.floor(n / 3600);
    const mins = Math.round((n % 3600) / 60);
    return mins > 0 ? `${hrs} hr ${mins} min` : `${hrs} hr`;
}

// ══════════ Map (Leaflet, npm package — matches LiveAlertsCard.vue) ══════════
// Points, roles, reverse-geocoded names, an OSRM route between the guard's
// start/arrival, and permanent named tooltips on every marker.

const mapEl = ref(null);
const mapLoading = ref(false);
const geocoded = ref({});
const routeCoords = ref(null);
let mapInstance = null;

// Unit number only means something when the household's location source is
// their registered address — for a GPS-sourced alert, the unit on file may
// not be where the alert happened. alert_location_source lives on the
// household's user record (not the alert), per Karabo.
const isRegisteredAddressSource = computed(
    () =>
        selectedReport.value?.alert?.alert_location_source ===
        'registered_address',
);

const isEstateAlert = computed(() => !!selectedReport.value?.alert?.is_estate);

const householdAddress = computed(() => {
    const a = selectedReport.value?.alert;
    if (!a) return null;
    return [a.complex_name, a.address_line_1, a.suburb]
        .filter(Boolean)
        .join(', ');
});

const locationSourceLabel = {
    gps: 'GPS',
    registered_address: 'Registered Address',
};

const mapPoints = computed(() => {
    const r = selectedReport.value;
    if (!r) return [];
    const a = r.alert;
    const res = r.resolution;
    const householdName = r.household?.name || 'Household';
    const guardName = r.reporter?.name || 'Guard';
    const pts = [];

    if (a?.trigger_lat && a?.trigger_lng) {
        pts.push({
            key: 'trigger',
            label: 'Alert Triggered',
            role: 'household',
            person: householdName,
            color: '#dc2626',
            lat: Number(a.trigger_lat),
            lng: Number(a.trigger_lng),
        });
    }
    if (
        a?.last_lat &&
        a?.last_lng &&
        (a.last_lat !== a.trigger_lat || a.last_lng !== a.trigger_lng)
    ) {
        pts.push({
            key: 'last',
            label: 'Last Known Location',
            role: 'household',
            person: householdName,
            color: '#f97316',
            lat: Number(a.last_lat),
            lng: Number(a.last_lng),
        });
    }
    if (res?.start_latitude && res?.start_longitude) {
        pts.push({
            key: 'start',
            label: 'Guard Start',
            role: 'guard',
            person: guardName,
            color: '#2563eb',
            lat: Number(res.start_latitude),
            lng: Number(res.start_longitude),
        });
    }
    if (res?.arrival_latitude && res?.arrival_longitude) {
        pts.push({
            key: 'arrival',
            label: 'Guard Arrival',
            role: 'guard',
            person: guardName,
            color: '#059669',
            lat: Number(res.arrival_latitude),
            lng: Number(res.arrival_longitude),
        });
    }
    return pts;
});

// The route drawn on the map: prefer the guard's actual start → arrival
// journey; fall back to guard start → household's last known location if
// no arrival fix was recorded.
const routeEndpoints = computed(() => {
    const start = mapPoints.value.find((p) => p.key === 'start');
    const arrival = mapPoints.value.find((p) => p.key === 'arrival');
    if (start && arrival) return { from: start, to: arrival };
    const household = mapPoints.value.find((p) => p.role === 'household');
    if (start && household) return { from: start, to: household };
    return null;
});

const geocodeCache = new Map();

async function reverseGeocode(lat, lng) {
    const key = `${lat.toFixed(5)},${lng.toFixed(5)}`;
    if (geocodeCache.has(key)) return geocodeCache.get(key) ?? null;
    try {
        const url = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`;
        const res = await fetch(url, {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) return null;
        const data = await res.json();
        let result = null;
        if (!data?.address) {
            result = data?.display_name || null;
        } else {
            const a = data.address;
            const parts = [
                [a.house_number, a.road].filter(Boolean).join(' '),
                a.suburb || a.neighbourhood || a.residential,
                a.city || a.town || a.village,
            ].filter(Boolean);
            result = parts.length
                ? parts.join(', ')
                : data.display_name || null;
        }
        geocodeCache.set(key, result);
        return result;
    } catch {
        return null;
    }
}

// OSRM's public demo routing server — free, shared, rate-limited; fine for
// low-volume internal admin use. Self-host or use a paid router at scale.
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

function buildMap(container) {
    const map = L.map(container, {
        zoomControl: true,
        attributionControl: false,
    });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);
    return map;
}

function drawPoints(map) {
    const layer = L.layerGroup().addTo(map);
    const bounds = [];

    for (const p of mapPoints.value) {
        const icon = L.divIcon({
            className: 'map-pin',
            html: `<span class="map-pin__dot" style="background:${p.color}"></span>`,
            iconSize: [16, 16],
            iconAnchor: [8, 8],
        });
        L.marker([p.lat, p.lng], { icon })
            .bindTooltip(
                `<strong>${p.label}</strong><br>${p.role === 'guard' ? 'Guard' : 'Household'} — ${p.person}`,
                {
                    permanent: true,
                    direction: 'top',
                    offset: [0, -10],
                    className: `ir-leaflet-label ir-leaflet-label--${p.role}`,
                },
            )
            .addTo(layer);
        bounds.push([p.lat, p.lng]);

        reverseGeocode(p.lat, p.lng).then((addr) => {
            geocoded.value[p.key] = addr;
        });
    }

    if (routeCoords.value?.length) {
        L.polyline(routeCoords.value, {
            color: '#dc2626',
            weight: 4,
            opacity: 0.75,
        }).addTo(layer);
    }

    return bounds;
}

function fitToPoints(map) {
    const bounds = mapPoints.value.map((p) => [p.lat, p.lng]);
    if (bounds.length === 1) map.setView(bounds[0], 16);
    else if (bounds.length > 1) map.fitBounds(bounds, { padding: [30, 30] });
}

function recenterMap() {
    if (mapInstance) fitToPoints(mapInstance);
}

async function initMap() {
    if (mapPoints.value.length === 0) return;
    mapLoading.value = true;
    await nextTick();
    if (!mapEl.value) {
        mapLoading.value = false;
        return;
    }
    if (mapInstance) {
        mapInstance.remove();
        mapInstance = null;
    }
    mapInstance = buildMap(mapEl.value);

    const ep = routeEndpoints.value;
    routeCoords.value = ep
        ? await fetchRoute(ep.from.lat, ep.from.lng, ep.to.lat, ep.to.lng)
        : null;

    drawPoints(mapInstance);
    fitToPoints(mapInstance);
    mapLoading.value = false;
}

function destroyMap() {
    if (mapInstance) {
        mapInstance.remove();
        mapInstance = null;
    }
    geocoded.value = {};
    routeCoords.value = null;
}

onUnmounted(() => destroyMap());

// ══════════ Response timeline ══════════

const timelineSteps = computed(() => {
    const r = selectedReport.value;
    if (!r) return [];
    const a = r.alert;
    const res = r.resolution;
    return [
        {
            key: 'triggered',
            label: 'Alert Triggered',
            time: a?.created_at,
            icon: Siren,
            done: !!a?.created_at,
        },
        // {
        //     key: 'ack',
        //     label: 'First Acknowledged',
        //     time: a?.first_ack_at,
        //     icon: BellRing,
        //     done: !!a?.first_ack_at,
        // },
        {
            key: 'accepted',
            label: 'Guard Accepted',
            time: res?.accepted_at,
            icon: UserCheck,
            done: !!res?.accepted_at,
        },
        {
            key: 'arrived',
            label: 'Guard Arrived',
            time: res?.arrival_time,
            icon: MapPin,
            done: !!res?.arrival_time,
        },
        {
            key: 'resolved',
            label: 'Resolved',
            time: res?.resolution_time,
            icon: CheckCircle2,
            done: !!res?.resolution_time,
        },
    ];
});

// ══════════ NEW REPORT WIZARD ══════════
const showWizard = ref(false);
const wizardStep = ref(1); // 1: pick guard, 2: pick incident, 3: fill form
const guards = ref([]);
const guardsLoading = ref(false);
const selectedGuard = ref(null);

const pendingIncidents = ref([]);
const pendingLoading = ref(false);
const selectedIncident = ref(null);

const submitLoading = ref(false);
const form = ref({
    outcome: 'legitimate',
    misuse_category: '',
    narrative: '',
    arrived_at: '',
    departed_at: '',
    injuries_reported: false,
    property_damage: false,
    additional_notes: '',
});

async function openWizard() {
    showWizard.value = true;
    wizardStep.value = 1;
    selectedGuard.value = null;
    selectedIncident.value = null;
    pendingIncidents.value = [];
    resetForm();

    if (guards.value.length === 0) {
        guardsLoading.value = true;
        try {
            const { data } = await axios.get(
                `${import.meta.env.VITE_APP_URL}/api/guard/incident-reports/guards`,
                getHeaders(),
            );
            guards.value = data;
        } catch {
            showFlash('Failed to load guards.', 'error');
        } finally {
            guardsLoading.value = false;
        }
    }
}

function closeWizard() {
    showWizard.value = false;
}

function resetForm() {
    form.value = {
        outcome: 'legitimate',
        misuse_category: '',
        narrative: '',
        arrived_at: '',
        departed_at: '',
        injuries_reported: false,
        property_damage: false,
        additional_notes: '',
    };
}

async function pickGuard(guard) {
    selectedGuard.value = guard;
    wizardStep.value = 2;
    pendingLoading.value = true;
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/guard/incident-reports/pending/${guard.user_id}`,
            getHeaders(),
        );
        pendingIncidents.value = data;
    } catch {
        showFlash('Failed to load incidents for this guard.', 'error');
    } finally {
        pendingLoading.value = false;
    }
}

function pickIncident(incident) {
    selectedIncident.value = incident;
    wizardStep.value = 3;
}

function backToGuards() {
    wizardStep.value = 1;
    selectedGuard.value = null;
    selectedIncident.value = null;
}

function backToIncidents() {
    wizardStep.value = 2;
    selectedIncident.value = null;
    resetForm();
}

async function submitReport() {
    if (!form.value.narrative.trim()) {
        showFlash('Narrative is required.', 'error');
        return;
    }
    if (form.value.outcome === 'misuse' && !form.value.misuse_category) {
        showFlash('Select a misuse category.', 'error');
        return;
    }
    submitLoading.value = true;
    try {
        const payload = {
            reporter_user_id: selectedGuard.value.user_id,
            household_user_id: selectedIncident.value.alert.user_id,
            emergency_alert_id: selectedIncident.value.alert.id,
            ...form.value,
        };
        const { data } = await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/guard/incident-reports`,
            payload,
            getHeaders(),
        );
        showFlash('Incident report submitted.');
        showWizard.value = false;
        await loadReports();
    } catch (err) {
        showFlash(
            err.response?.data?.message ?? 'Failed to submit report.',
            'error',
        );
    } finally {
        submitLoading.value = false;
    }
}

const statusOptions = [
    { value: '', label: 'All' },
    { value: 'pending', label: 'Pending' },
    { value: 'reviewed', label: 'Reviewed' },
    { value: 'warned', label: 'Warned' },
    { value: 'blocked', label: 'Blocked' },
    { value: 'dismissed', label: 'Dismissed' },
];

const outcomeOptions = [
    { value: '', label: 'All' },
    { value: 'misuse', label: 'Misuse' },
    { value: 'legitimate', label: 'Legitimate' },
];

const misuseCategoryOptions = [
    { value: 'accidental', label: 'Accidental' },
    { value: 'prank', label: 'Prank' },
    { value: 'domestic_dispute', label: 'Domestic Dispute' },
    { value: 'unfounded_fear', label: 'Unfounded Fear' },
    { value: 'repeated_false_alarm', label: 'Repeated False Alarm' },
    { value: 'other', label: 'Other' },
];

const statusConfig = {
    pending: { label: 'Pending', cls: 'bg-amber-50 text-amber-700' },
    reviewed: { label: 'Reviewed', cls: 'bg-blue-50 text-blue-700' },
    warned: { label: 'Warned', cls: 'bg-orange-50 text-orange-700' },
    blocked: { label: 'Blocked', cls: 'bg-red-50 text-red-600' },
    dismissed: { label: 'Dismissed', cls: 'bg-slate-100 text-slate-500' },
};
const outcomeConfig = {
    legitimate: { label: 'Legitimate', cls: 'bg-emerald-50 text-emerald-700' },
    misuse: { label: 'Misuse', cls: 'bg-red-50 text-red-600' },
};
const misuseCategoryLabel = {
    accidental: 'Accidental',
    prank: 'Prank',
    domestic_dispute: 'Domestic Dispute',
    unfounded_fear: 'Unfounded Fear',
    repeated_false_alarm: 'Repeated False Alarm',
    other: 'Other',
};

onMounted(() => loadReports());
</script>

<template>
    <Head title="Incident Reports" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="page-root">
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Safety</div>
                    <h1 class="page-header__title">Incident Reports</h1>
                    <p class="page-header__sub">
                        Log and review SOS incident reports for your channel
                    </p>
                </div>
                <div class="page-header__right">
                    <button class="btn-primary" @click="openWizard">
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M12 4v16m8-8H4"
                            />
                        </svg>
                        New Report
                    </button>
                </div>
            </div>

            <!-- FILTER BAR -->
            <div class="filter-card">
                <div class="filter-card__top">
                    <div class="search-input-row search-input-row--standalone">
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="search-icon"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                            />
                        </svg>
                        <input
                            v-model="searchQuery"
                            @input="handleSearch"
                            type="text"
                            class="search-input"
                            placeholder="Search by household name or unit number…"
                        />
                        <span
                            v-if="searchQuery"
                            class="search-clear"
                            @click="
                                searchQuery = '';
                                loadReports();
                            "
                            >×</span
                        >
                    </div>

                    <div class="date-range">
                        <div class="date-field">
                            <label class="date-field__label">From</label>
                            <input
                                v-model="dateFrom"
                                type="date"
                                :max="dateTo"
                                class="field__input field__input--date"
                            />
                        </div>
                        <div class="date-field">
                            <label class="date-field__label">To</label>
                            <input
                                v-model="dateTo"
                                type="date"
                                :min="dateFrom"
                                :max="today"
                                class="field__input field__input--date"
                            />
                        </div>
                        <button
                            class="btn-secondary btn-secondary--compact"
                            :disabled="loading"
                            @click="loadReports()"
                        >
                            {{ loading ? 'Loading…' : 'Apply' }}
                        </button>
                    </div>
                </div>
                <p v-if="dateError" class="date-error">{{ dateError }}</p>

                <div class="filter-groups">
                    <div class="filter-group">
                        <span class="filter-group__label">Status</span>
                        <div class="filter-bar__chips">
                            <button
                                v-for="f in statusOptions"
                                :key="f.value"
                                class="chip"
                                :class="{
                                    'chip--active': filterStatus === f.value,
                                }"
                                @click="setStatus(f.value)"
                            >
                                {{ f.label }}
                            </button>
                        </div>
                    </div>
                    <div class="filter-group">
                        <span class="filter-group__label">Outcome</span>
                        <div class="filter-bar__chips">
                            <button
                                v-for="f in outcomeOptions"
                                :key="f.value"
                                class="chip"
                                :class="{
                                    'chip--active': filterOutcome === f.value,
                                }"
                                @click="setOutcome(f.value)"
                            >
                                {{ f.label }}
                            </button>
                        </div>
                    </div>
                    <button
                        class="btn-secondary btn-secondary--compact ml-auto"
                        @click="loadReports()"
                    >
                        Refresh
                    </button>
                </div>
            </div>

            <!-- TABLE -->
            <div class="table-card">
                <div v-if="loading" class="empty-state">
                    <span class="text-sm text-slate-400">Loading reports…</span>
                </div>
                <div v-else-if="reportList.length === 0" class="empty-state">
                    <p class="empty-state__title">No reports found</p>
                    <p class="empty-state__sub">
                        Try adjusting the date range or filters
                    </p>
                </div>
                <div v-else class="table-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Cancel Pin</th>
                                <th>Household</th>
                                <th>Unit</th>
                                <th>Type</th>
                                <th>Source</th>
                                <th>Guard</th>
                                <th>Outcome</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="report in reportList"
                                :key="report.id"
                                class="clickable-row"
                                @click="openDetail(report)"
                            >
                             <td>
                                    <span
                                        class="type-badge"
                                        :class="
                                            report.alert?.cancel_pin_used ===
                                            'duress'
                                                ? 'bg-red-50 text-red-600'
                                                : 'bg-slate-100 text-slate-600'
                                        "
                                    >
                                        {{
                                            report.alert?.cancel_pin_used ===
                                            'safe_cancel'
                                                ? 'Safe Cancel'
                                                : report.alert
                                                        ?.cancel_pin_used ===
                                                    'duress'
                                                  ? 'Duress'
                                                  : 'None'
                                        }}
                                    </span>
                                </td>
                                <td>
                                    <div class="reporter-cell__name-row">
                                        <span class="reporter-cell__name">
                                            {{ report.alert?.name ?? '—' }}
                                        </span>
                                    </div>
                                    <!-- <div class="reporter-cell__sub">
                                    {{ report.alert?.email }}
                                </div> -->
                                </td>
                                <td>
                                    <span
                                        v-if="
                                            report.alert?.is_estate &&
                                            report.alert?.unit_number
                                        "
                                        class="td-time"
                                    >
                                        {{ fmtUnit(report.alert?.unit_number) }}
                                    </span>
                                    <span v-else class="td-time"> - </span>
                                </td>
                                <td>
                                    <span class="td-time">
                                        {{ report.alert?.alert_type ?? '—' }}
                                    </span>
                                </td>
                                <td>
                                    <span
                                        v-if="
                                            report.alert?.alert_location_source
                                        "
                                        class="td-time"
                                    >
                                        {{
                                            locationSourceLabel[
                                                report.alert
                                                    .alert_location_source
                                            ]
                                        }}
                                    </span>
                                </td>
                                <td>
                                    <div class="td-time">
                                        {{ report.reporter?.name ?? '—' }}
                                    </div>
                                </td>
                                <td>
                                    <span
                                        class="type-badge"
                                        :class="
                                            outcomeConfig[report.outcome]?.cls
                                        "
                                    >
                                        {{
                                            outcomeConfig[report.outcome]
                                                ?.label ?? report.outcome
                                        }}
                                    </span>
                                </td>
                                <td>
                                    <span
                                        class="type-badge"
                                        :class="
                                            statusConfig[report.status]?.cls
                                        "
                                    >
                                        {{
                                            statusConfig[report.status]
                                                ?.label ?? report.status
                                        }}
                                    </span>
                                </td>
                                <td class="td-time">
                                    {{ fmtDateTime(report.created_at) }}
                                </td>
                                <td>
                                    <button
                                        class="row-action-btn"
                                        @click.stop="openDetail(report)"
                                    >
                                        View
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    class="pagination-bar"
                    v-if="!loading && reportList.length > 0"
                >
                    <span class="pagination-bar__info">
                        Showing {{ reports.from ?? 0 }} to
                        {{ reports.to ?? 0 }} of
                        {{ reports.total ?? 0 }} reports
                    </span>
                    <div class="pagination-bar__pages">
                        <template v-for="(link, i) in reports.links" :key="i">
                            <button
                                v-if="link.url"
                                @click="loadReports(link.url)"
                                v-html="link.label"
                                class="page-btn"
                                :class="{ 'page-btn--active': link.active }"
                            />
                            <span
                                v-else
                                v-html="link.label"
                                class="page-btn page-btn--disabled"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════ DETAIL MODAL (read-only) ═══════ -->
        <Teleport to="body">
            <transition name="modal">
                <div
                    v-if="showDetail"
                    class="modal-backdrop"
                    @click.self="closeDetail"
                >
                    <div class="modal-sheet modal-sheet--wide">
                        <div class="modal-sheet__header">
                            <div>
                                <div class="modal-sheet__title">
                                    Incident Report #{{ selectedReport?.id }}
                                </div>
                                <div class="modal-sheet__sub">
                                    {{
                                        fmtDateTime(selectedReport?.created_at)
                                    }}
                                </div>
                            </div>
                            <button class="close-btn" @click="closeDetail">
                                <X :size="16" />
                            </button>
                        </div>
                        <div class="modal-sheet__layout">
                            <div class="modal-sheet__body">
                                <div class="toggle-row">
                                    <span
                                        class="type-badge"
                                        :class="
                                            outcomeConfig[
                                                selectedReport?.outcome
                                            ]?.cls
                                        "
                                        >{{
                                            outcomeConfig[
                                                selectedReport?.outcome
                                            ]?.label
                                        }}</span
                                    >
                                    <span
                                        class="type-badge"
                                        :class="
                                            statusConfig[selectedReport?.status]
                                                ?.cls
                                        "
                                        >{{
                                            statusConfig[selectedReport?.status]
                                                ?.label
                                        }}</span
                                    >
                                    <span
                                        v-if="selectedReport?.misuse_category"
                                        class="type-badge bg-slate-100 text-slate-600"
                                    >
                                        {{
                                            misuseCategoryLabel[
                                                selectedReport.misuse_category
                                            ]
                                        }}
                                    </span>
                                </div>

                                <div
                                    v-if="
                                        selectedReport?.alert
                                            ?.cancel_pin_used === 'duress'
                                    "
                                    class="duress-banner"
                                >
                                    ⚠ Duress PIN was used to cancel this alert
                                </div>

                                <div class="toggle-row">
                                    <span
                                        class="type-badge bg-slate-100 text-slate-600"
                                    >
                                        {{
                                            selectedReport?.alert?.alert_type ??
                                            'sos'
                                        }}
                                    </span>
                                    <span
                                        v-if="selectedReport?.alert?.muted"
                                        class="type-badge bg-slate-100 text-slate-600"
                                    >
                                        Muted
                                    </span>
                                    <span
                                        v-if="
                                            selectedReport?.alert
                                                ?.cancel_pin_used &&
                                            selectedReport.alert
                                                .cancel_pin_used !== 'none'
                                        "
                                        class="type-badge"
                                        :class="
                                            selectedReport.alert
                                                .cancel_pin_used === 'duress'
                                                ? 'bg-red-50 text-red-600'
                                                : 'bg-slate-100 text-slate-600'
                                        "
                                    >
                                        Cancel PIN:
                                        {{
                                            selectedReport.alert
                                                .cancel_pin_used === 'duress'
                                                ? 'Duress'
                                                : 'Safe Cancel'
                                        }}
                                    </span>
                                </div>

                                <div class="toggle-row">
                                    <div class="review-info-panel">
                                        <div class="field__label">
                                            Household
                                        </div>
                                        <div class="review-info-panel__name">
                                            {{
                                                selectedReport?.household?.name
                                            }}
                                        </div>

                                        <div
                                            class="toggle-row"
                                            style="margin: 2px 0"
                                        >
                                            <span
                                                class="unit-plain"
                                                v-if="
                                                    isRegisteredAddressSource &&
                                                    selectedReport?.alert
                                                        ?.unit_number
                                                "
                                            >
                                                {{
                                                    fmtUnit(
                                                        selectedReport.alert
                                                            .unit_number,
                                                    )
                                                }}
                                            </span>
                                            <span
                                                v-if="
                                                    selectedReport?.alert
                                                        ?.alert_location_source
                                                "
                                                class="type-badge bg-slate-100 text-slate-600"
                                            >
                                                {{
                                                    locationSourceLabel[
                                                        selectedReport.alert
                                                            .alert_location_source
                                                    ]
                                                }}
                                            </span>
                                        </div>

                                        <div
                                            v-if="
                                                isRegisteredAddressSource &&
                                                !isEstateAlert &&
                                                householdAddress
                                            "
                                            class="review-info-panel__sub"
                                        >
                                            {{ householdAddress }}
                                        </div>
                                        <div class="review-info-panel__sub">
                                            {{ selectedReport?.alert?.email }}
                                        </div>
                                        <div class="review-info-panel__sub">
                                            {{ selectedReport?.alert?.phone }}
                                        </div>
                                    </div>
                                    <div class="review-info-panel">
                                        <div class="field__label">Guard</div>
                                        <div class="review-info-panel__name">
                                            {{ selectedReport?.reporter?.name }}
                                        </div>
                                        <div class="review-info-panel__sub">
                                            {{
                                                selectedReport?.reporter?.email
                                            }}
                                        </div>
                                        <div class="review-info-panel__sub">
                                            {{
                                                selectedReport?.reporter?.phone
                                            }}
                                        </div>
                                    </div>
                                </div>

                                <div class="review-info-panel">
                                    <div class="field__label">
                                        Response Timeline
                                    </div>
                                    <div class="ir-timeline">
                                        <div
                                            v-for="(step, i) in timelineSteps"
                                            :key="step.key"
                                            class="ir-timeline__step"
                                        >
                                            <div class="ir-timeline__rail">
                                                <span
                                                    class="ir-timeline__dot"
                                                    :class="{
                                                        'ir-timeline__dot--done':
                                                            step.done,
                                                    }"
                                                >
                                                    <component
                                                        :is="step.icon"
                                                        :size="12"
                                                    />
                                                </span>
                                                <span
                                                    v-if="
                                                        i <
                                                        timelineSteps.length - 1
                                                    "
                                                    class="ir-timeline__line"
                                                    :class="{
                                                        'ir-timeline__line--done':
                                                            step.done,
                                                    }"
                                                ></span>
                                            </div>
                                            <div class="ir-timeline__body">
                                                <div class="ir-timeline__label">
                                                    {{ step.label }}
                                                </div>
                                                <div
                                                    class="ir-timeline__time"
                                                    :class="{
                                                        'ir-timeline__time--pending':
                                                            !step.done,
                                                    }"
                                                >
                                                    {{
                                                        step.done
                                                            ? fmtDateTime(
                                                                  step.time,
                                                              )
                                                            : 'Pending'
                                                    }}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="detail-grid detail-grid--pad">
                                        <div>
                                            <div class="field__label">
                                                Response Duration
                                            </div>
                                            <div class="detail-grid__value">
                                                {{
                                                    fmtDuration(
                                                        selectedReport
                                                            ?.resolution
                                                            ?.response_duration,
                                                    )
                                                }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="field__label">
                                                Distance Traveled
                                            </div>
                                            <div class="detail-grid__value">
                                                {{
                                                    fmtDistance(
                                                        selectedReport
                                                            ?.resolution
                                                            ?.distance_traveled,
                                                    )
                                                }}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="review-info-panel">
                                    <div class="field__label">Locations</div>
                                    <div class="ir-location-list">
                                        <div
                                            v-for="p in mapPoints"
                                            :key="p.key"
                                            class="ir-location-row"
                                        >
                                            <span
                                                class="ir-location-row__dot"
                                                :style="{
                                                    background: p.color,
                                                }"
                                            ></span>
                                            <div class="ir-location-row__body">
                                                <div
                                                    class="ir-location-row__top"
                                                >
                                                    <span
                                                        class="ir-location-row__label"
                                                        >{{ p.label }}</span
                                                    >
                                                    <span
                                                        class="ir-location-row__role"
                                                        :class="`ir-location-row__role--${p.role}`"
                                                    >
                                                        {{
                                                            p.role === 'guard'
                                                                ? 'Guard'
                                                                : 'Household'
                                                        }}
                                                        — {{ p.person }}
                                                    </span>
                                                </div>
                                                <div
                                                    class="ir-location-row__addr"
                                                >
                                                    {{
                                                        geocoded[p.key] ??
                                                        'Locating…'
                                                    }}
                                                </div>
                                                <div
                                                    class="ir-location-row__coords"
                                                >
                                                    {{ p.lat.toFixed(5) }},
                                                    {{ p.lng.toFixed(5) }}
                                                </div>
                                            </div>
                                        </div>
                                        <p
                                            v-if="mapPoints.length === 0"
                                            class="ir-location-empty"
                                        >
                                            No location data available for this
                                            report
                                        </p>
                                    </div>
                                    <div class="detail-grid detail-grid--pad">
                                        <div>
                                            <div class="field__label">
                                                GPS Accuracy
                                            </div>
                                            <div class="detail-grid__value">
                                                {{
                                                    fmtAccuracy(
                                                        selectedReport?.alert
                                                            ?.accuracy,
                                                    )
                                                }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="field__label">
                                                Location Updated
                                            </div>
                                            <div class="detail-grid__value">
                                                {{
                                                    fmtDateTime(
                                                        selectedReport?.alert
                                                            ?.location_updated_at,
                                                    )
                                                }}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="review-info-panel">
                                    <div class="detail-grid">
                                        <div>
                                            <div class="field__label">
                                                Guard-Reported Arrival
                                            </div>
                                            <div class="detail-grid__value">
                                                {{
                                                    fmtDateTime(
                                                        selectedReport?.arrived_at,
                                                    )
                                                }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="field__label">
                                                Guard-Reported Departure
                                            </div>
                                            <div class="detail-grid__value">
                                                {{
                                                    fmtDateTime(
                                                        selectedReport?.departed_at,
                                                    )
                                                }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="field__label">
                                                Injuries
                                            </div>
                                            <div
                                                class="detail-grid__value"
                                                :class="{
                                                    'detail-grid__value--danger':
                                                        selectedReport?.injuries_reported,
                                                }"
                                            >
                                                {{
                                                    selectedReport?.injuries_reported
                                                        ? 'Yes'
                                                        : 'No'
                                                }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="field__label">
                                                Property Damage
                                            </div>
                                            <div
                                                class="detail-grid__value"
                                                :class="{
                                                    'detail-grid__value--danger':
                                                        selectedReport?.property_damage,
                                                }"
                                            >
                                                {{
                                                    selectedReport?.property_damage
                                                        ? 'Yes'
                                                        : 'No'
                                                }}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="field">
                                    <label class="field__label"
                                        >Narrative</label
                                    >
                                    <p class="review-description">
                                        {{ selectedReport?.narrative }}
                                    </p>
                                </div>
                                <div
                                    v-if="selectedReport?.alert?.audio_path"
                                    class="field"
                                >
                                    <label class="field__label"
                                        >Alert Audio</label
                                    >
                                    <audio
                                        controls
                                        :src="selectedReport.alert.audio_path"
                                        class="audio-player"
                                    ></audio>
                                </div>

                                <div
                                    v-if="
                                        selectedReport?.resolution
                                            ?.victim_response
                                    "
                                    class="field"
                                >
                                    <label class="field__label"
                                        >Victim Response</label
                                    >
                                    <p class="review-description">
                                        {{
                                            selectedReport.resolution
                                                .victim_response
                                        }}
                                    </p>
                                </div>

                                <div class="review-info-panel">
                                    <div class="field__label">Confirmation</div>
                                    <div class="detail-grid">
                                        <div>
                                            <div class="field__label">
                                                Status
                                            </div>
                                            <div
                                                class="detail-grid__value"
                                                style="
                                                    text-transform: capitalize;
                                                "
                                            >
                                                {{
                                                    selectedReport?.resolution
                                                        ?.confirmation_status ??
                                                    '—'
                                                }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="field__label">
                                                Confirmed By
                                            </div>
                                            <div class="detail-grid__value">
                                                {{
                                                    selectedReport?.resolution
                                                        ?.confirmed_by ?? '—'
                                                }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="field__label">
                                                Confirmed At
                                            </div>
                                            <div class="detail-grid__value">
                                                {{
                                                    fmtDateTime(
                                                        selectedReport
                                                            ?.resolution
                                                            ?.confirmed_at,
                                                    )
                                                }}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    v-if="selectedReport?.additional_notes"
                                    class="field"
                                >
                                    <label class="field__label"
                                        >Additional Notes</label
                                    >
                                    <p class="review-description">
                                        {{ selectedReport.additional_notes }}
                                    </p>
                                </div>

                                <div
                                    v-if="selectedReport?.status !== 'pending'"
                                    class="review-description text-center"
                                >
                                    Report
                                    <strong>{{
                                        selectedReport?.status
                                    }}</strong>
                                    by admin — no action needed from you.
                                </div>
                            </div>

                            <div class="modal-sheet__map-panel">
                                <div class="modal-sheet__map-wrap">
                                    <div
                                        ref="mapEl"
                                        class="modal-sheet__map"
                                    ></div>
                                    <button
                                        v-if="mapPoints.length"
                                        type="button"
                                        class="ir-recenter-btn"
                                        @click="recenterMap"
                                    >
                                        <Crosshair :size="14" />
                                        Recenter
                                    </button>
                                    <div v-if="mapLoading" class="map-loading">
                                        Loading map…
                                    </div>
                                </div>
                                <div class="map-legend">
                                    <div
                                        v-for="p in mapPoints"
                                        :key="p.key"
                                        class="map-legend__row"
                                    >
                                        <span
                                            class="map-legend__dot"
                                            :style="{ background: p.color }"
                                        ></span>
                                        <div>
                                            <div class="map-legend__label">
                                                {{ p.label }}
                                                <span class="map-legend__role"
                                                    >({{
                                                        p.role === 'guard'
                                                            ? 'Guard'
                                                            : 'Household'
                                                    }}
                                                    — {{ p.person }})</span
                                                >
                                            </div>
                                            <div class="map-legend__addr">
                                                {{
                                                    geocoded[p.key] ??
                                                    'Locating…'
                                                }}
                                            </div>
                                            <div class="map-legend__coords">
                                                {{ p.lat.toFixed(5) }},
                                                {{ p.lng.toFixed(5) }}
                                            </div>
                                        </div>
                                    </div>
                                    <div
                                        v-if="mapPoints.length === 0"
                                        class="map-legend__empty"
                                    >
                                        No location data available for this
                                        report
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>

        <!-- ═══════ NEW REPORT WIZARD ═══════ -->
        <Teleport to="body">
            <transition name="modal">
                <div
                    v-if="showWizard"
                    class="modal-backdrop"
                    @click.self="closeWizard"
                >
                    <div class="modal-sheet">
                        <div class="modal-sheet__header">
                            <div>
                                <div class="modal-sheet__title">
                                    <template v-if="wizardStep === 1"
                                        >Select Guard</template
                                    >
                                    <template v-else-if="wizardStep === 2"
                                        >Select Incident</template
                                    >
                                    <template v-else>Report Details</template>
                                </div>
                                <div class="modal-sheet__sub">
                                    <template v-if="wizardStep === 1"
                                        >Who responded to the
                                        incident?</template
                                    >
                                    <template v-else-if="wizardStep === 2"
                                        >Unreported incidents for
                                        {{
                                            selectedGuard?.user?.name
                                        }}</template
                                    >
                                    <template v-else
                                        >Incident on
                                        {{
                                            fmtDateTime(
                                                selectedIncident?.alert
                                                    ?.created_at,
                                            )
                                        }}</template
                                    >
                                </div>
                            </div>
                            <button class="close-btn" @click="closeWizard">
                                <X :size="16" />
                            </button>
                        </div>

                        <div class="modal-sheet__body">
                            <!-- STEP 1: guard list -->
                            <template v-if="wizardStep === 1">
                                <div v-if="guardsLoading" class="empty-state">
                                    <span class="text-sm text-slate-400"
                                        >Loading guards…</span
                                    >
                                </div>
                                <div
                                    v-else-if="guards.length === 0"
                                    class="empty-state"
                                >
                                    <p class="empty-state__sub">
                                        No guards found for this channel.
                                    </p>
                                </div>
                                <div v-else class="pick-list">
                                    <button
                                        v-for="g in guards"
                                        :key="g.id"
                                        class="pick-row"
                                        @click="pickGuard(g)"
                                    >
                                        <div class="reporter-cell__avatar">
                                            {{
                                                (g.user?.name || 'G')
                                                    .charAt(0)
                                                    .toUpperCase()
                                            }}
                                        </div>
                                        <div>
                                            <div class="reporter-cell__name">
                                                {{ g.user?.name }}
                                            </div>
                                            <div class="reporter-cell__sub">
                                                {{ g.user?.email }}
                                            </div>
                                        </div>
                                    </button>
                                </div>
                            </template>

                            <!-- STEP 2: incident list -->
                            <template v-else-if="wizardStep === 2">
                                <div v-if="pendingLoading" class="empty-state">
                                    <span class="text-sm text-slate-400"
                                        >Loading incidents…</span
                                    >
                                </div>
                                <div
                                    v-else-if="pendingIncidents.length === 0"
                                    class="empty-state"
                                >
                                    <p class="empty-state__title">
                                        No unreported incidents
                                    </p>
                                    <p class="empty-state__sub">
                                        Every incident this guard responded to
                                        already has a report.
                                    </p>
                                </div>
                                <div v-else class="pick-list">
                                    <button
                                        v-for="inc in pendingIncidents"
                                        :key="inc.id"
                                        class="pick-row"
                                        @click="pickIncident(inc)"
                                    >
                                        <div>
                                            <div class="reporter-cell__name">
                                                Alert #{{ inc.alert?.id }} —
                                                {{ inc.alert?.alert_type }}
                                            </div>
                                            <div class="reporter-cell__sub">
                                                Household:
                                                {{
                                                    inc.alert?.user?.name ?? '—'
                                                }}
                                                ·
                                                {{
                                                    fmtDateTime(
                                                        inc.alert?.created_at,
                                                    )
                                                }}
                                            </div>
                                        </div>
                                    </button>
                                </div>
                                <button
                                    class="btn-ghost"
                                    style="margin-top: 4px"
                                    @click="backToGuards"
                                >
                                    ← Back to guards
                                </button>
                            </template>

                            <!-- STEP 3: report form -->
                            <template v-else>
                                <div class="field">
                                    <label class="field__label">Outcome</label>
                                    <div class="toggle-row">
                                        <button
                                            class="chip"
                                            :class="{
                                                'chip--active':
                                                    form.outcome ===
                                                    'legitimate',
                                            }"
                                            @click="form.outcome = 'legitimate'"
                                        >
                                            Legitimate
                                        </button>
                                        <button
                                            class="chip"
                                            :class="{
                                                'chip--active':
                                                    form.outcome === 'misuse',
                                            }"
                                            @click="form.outcome = 'misuse'"
                                        >
                                            Misuse
                                        </button>
                                    </div>
                                </div>

                                <div
                                    v-if="form.outcome === 'misuse'"
                                    class="field"
                                >
                                    <label class="field__label"
                                        >Misuse Category</label
                                    >
                                    <select
                                        v-model="form.misuse_category"
                                        class="field__input"
                                    >
                                        <option value="">Select…</option>
                                        <option
                                            v-for="c in misuseCategoryOptions"
                                            :key="c.value"
                                            :value="c.value"
                                        >
                                            {{ c.label }}
                                        </option>
                                    </select>
                                </div>

                                <div class="detail-grid">
                                    <div class="field">
                                        <label class="field__label"
                                            >Arrived At</label
                                        >
                                        <input
                                            v-model="form.arrived_at"
                                            type="datetime-local"
                                            class="field__input"
                                        />
                                    </div>
                                    <div class="field">
                                        <label class="field__label"
                                            >Departed At</label
                                        >
                                        <input
                                            v-model="form.departed_at"
                                            type="datetime-local"
                                            class="field__input"
                                        />
                                    </div>
                                </div>

                                <div class="toggle-row">
                                    <label class="checkbox-row">
                                        <input
                                            type="checkbox"
                                            v-model="form.injuries_reported"
                                        />
                                        Injuries reported
                                    </label>
                                    <label class="checkbox-row">
                                        <input
                                            type="checkbox"
                                            v-model="form.property_damage"
                                        />
                                        Property damage
                                    </label>
                                </div>

                                <div class="field">
                                    <label class="field__label"
                                        >Narrative</label
                                    >
                                    <textarea
                                        v-model="form.narrative"
                                        class="field__input field__textarea"
                                        rows="4"
                                        placeholder="What happened, on arrival, actions taken…"
                                    ></textarea>
                                </div>

                                <div class="field">
                                    <label class="field__label"
                                        >Additional Notes (optional)</label
                                    >
                                    <textarea
                                        v-model="form.additional_notes"
                                        class="field__input field__textarea"
                                        rows="2"
                                    ></textarea>
                                </div>

                                <div class="modal-actions">
                                    <button
                                        class="btn-ghost"
                                        :disabled="submitLoading"
                                        @click="backToIncidents"
                                    >
                                        ← Back
                                    </button>
                                    <button
                                        class="btn-primary"
                                        :disabled="submitLoading"
                                        @click="submitReport"
                                    >
                                        {{
                                            submitLoading
                                                ? 'Submitting…'
                                                : 'Submit Report'
                                        }}
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>

        <!-- TOAST -->
        <Teleport to="body">
            <transition name="modal">
                <div
                    v-if="flash"
                    class="toast"
                    :class="
                        flash.type === 'success'
                            ? 'toast--success'
                            : 'toast--error'
                    "
                >
                    {{ flash.msg }}
                </div>
            </transition>
        </Teleport>
    </AppLayout>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap');

.page-root,
.modal-backdrop,
.toast {
    --c-primary: #ea580c;
    --c-primary-h: #c2410c;
    --c-danger: #dc2626;
    --radius-md: 12px;
    --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
    --shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.14);
    font-family: 'DM Sans', system-ui, sans-serif;
}

.page-root {
    padding: 28px 32px;
    display: flex;
    flex-direction: column;
    gap: 20px;
    min-height: 100%;
    background: #f4f6f9;
}

.page-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}
.page-header__eyebrow {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: #ea580c;
    margin-bottom: 4px;
}
.page-header__title {
    font-size: 22px;
    font-weight: 700;
    color: #1a2332;
    margin: 0;
    letter-spacing: -0.3px;
}
.page-header__sub {
    font-size: 13px;
    color: #64748b;
    margin: 4px 0 0;
}

.btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #ea580c !important;
    color: #fff !important;
    border: none;
    border-radius: 12px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(234, 88, 12, 0.3);
    font-family: inherit;
}
.btn-primary:hover:not(:disabled) {
    background: #c2410c !important;
}
.btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-secondary {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #fff;
    color: #1a2332;
    border: 1.5px solid #e4e8ef;
    border-radius: 12px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    font-family: inherit;
}
.btn-secondary:hover:not(:disabled) {
    border-color: #ea580c;
    color: #ea580c;
    background: #fff7ed;
}
.btn-secondary--compact {
    padding: 8px 14px;
    font-size: 12px;
}

.btn-ghost {
    background: #f1f5f9;
    color: #64748b;
    border: none;
    border-radius: 12px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}
.btn-ghost:hover:not(:disabled) {
    background: #e2e8f0;
}
.btn-ghost:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.filter-card {
    background: #fff;
    border: 1px solid #e4e8ef;
    border-radius: 16px;
    box-shadow: var(--shadow-sm);
    padding: 16px 20px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.filter-card__top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}
.search-input-row--standalone {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 14px;
    border-radius: 10px;
    background: #f8fafc;
    border: 1.5px solid #e4e8ef;
    max-width: 340px;
    flex: 1;
}
.search-input-row--standalone:focus-within {
    border-color: #ea580c;
}
.search-icon {
    width: 15px;
    height: 15px;
    color: #94a3b8;
    flex-shrink: 0;
}
.search-input {
    flex: 1;
    border: none;
    background: transparent;
    font-size: 13px;
    font-family: inherit;
    color: #1a2332;
    outline: none;
}
.search-clear {
    font-size: 16px;
    color: #94a3b8;
    cursor: pointer;
    padding: 0 2px;
}

.date-range {
    display: flex;
    align-items: flex-end;
    gap: 10px;
}
.date-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.date-field__label {
    font-size: 11px;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.6px;
}
.date-error {
    font-size: 12px;
    color: #dc2626;
    margin: -4px 0 0;
}

.filter-groups {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 20px;
}
.filter-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.filter-group__label {
    font-size: 11px;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.6px;
}
.ml-auto {
    margin-left: auto;
}

.filter-bar__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.chip {
    padding: 5px 14px;
    border-radius: 20px;
    border: 1px solid #e4e8ef;
    background: #fff;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
    font-family: inherit;
}
.chip:hover {
    border-color: #cbd5e1;
    background: #f8fafc;
}
.chip--active {
    background: #ea580c;
    color: #fff;
    border-color: #ea580c;
}

.table-card {
    background: #fff;
    border: 1px solid #e4e8ef;
    border-radius: 16px;
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 64px 24px;
    gap: 8px;
}
.empty-state__title {
    font-size: 15px;
    font-weight: 700;
    color: #1a2332;
}
.empty-state__sub {
    font-size: 13px;
    color: #64748b;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.data-table thead tr {
    background: #f8fafc;
    border-bottom: 1px solid #e4e8ef;
}
.data-table th {
    padding: 11px 16px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #94a3b8;
    text-align: left;
    white-space: nowrap;
}
.data-table tbody tr {
    border-bottom: 1px solid #e4e8ef;
}
.data-table tbody tr:last-child {
    border-bottom: none;
}
.data-table td {
    padding: 13px 16px;
    vertical-align: middle;
}
.clickable-row {
    cursor: pointer;
}
.clickable-row:hover {
    background: #fafbfc;
}

.type-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
    text-transform: capitalize;
}

.reporter-cell__avatar {
    width: 32px;
    height: 32px;
    border-radius: 10px;
    background: linear-gradient(135deg, #ea580c, #c2410c);
    color: #fff;
    font-size: 12px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.reporter-cell__name {
    font-size: 13px;
    font-weight: 700;
    color: #1a2332;
}
.reporter-cell__name-row {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.reporter-cell__sub {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 1px;
}

/* ── Unit number — deliberately loud, registered-address households only ── */
.ir-unit-badge {
    display: inline-flex;
    align-items: center;
    background: #fef2f2;
    border: 1.5px solid #fca5a5;
    border-radius: 8px;
    font-weight: 800;
    color: #dc2626;
    letter-spacing: 0.3px;
    white-space: nowrap;
}
.ir-unit-badge--table {
    padding: 2px 9px;
    font-size: 11px;
}
.ir-unit-badge--modal {
    align-self: flex-start;
    margin: 4px 0 2px;
    padding: 5px 14px;
    font-size: 16px;
}
.td-time {
    color: #64748b;
    white-space: nowrap;
    font-size: 12px;
}

.row-action-btn {
    padding: 6px 12px;
    border-radius: 8px;
    border: 1.5px solid #e4e8ef;
    background: #fff;
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    cursor: pointer;
    font-family: inherit;
    white-space: nowrap;
}
.row-action-btn:hover {
    border-color: #ea580c;
    color: #ea580c;
    background: #fff7ed;
}

.pagination-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-top: 1px solid #e4e8ef;
    flex-wrap: wrap;
    gap: 8px;
}
.pagination-bar__info {
    font-size: 12px;
    color: #94a3b8;
}
.pagination-bar__pages {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}
.page-btn {
    min-width: 34px;
    height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 12px;
    border: 1px solid #e4e8ef;
    border-radius: 8px;
    background: #fff;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
}
.page-btn--active {
    background: #ea580c;
    border-color: #ea580c;
    color: #fff;
}
.page-btn--disabled {
    background: #f8fafc;
    color: #94a3b8;
    cursor: default;
}

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(10, 18, 30, 0.55) !important;
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 24px;
}
.modal-sheet {
    background: #fff !important;
    border-radius: 20px;
    width: 100%;
    max-width: 580px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.18);
    border: 1px solid #e4e8ef;
}
.modal-sheet--wide {
    max-width: 1180px;
    height: 86vh;
    max-height: 86vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.modal-sheet__header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 22px 24px;
    border-bottom: 1px solid #e4e8ef;
    position: sticky;
    top: 0;
    background: #fff !important;
    z-index: 2;
    justify-content: space-between;
    flex-shrink: 0;
}
.modal-sheet__title {
    font-size: 15px;
    font-weight: 700;
    color: #1a2332;
}
.modal-sheet__sub {
    font-size: 12px;
    color: #94a3b8;
}
.close-btn {
    flex-shrink: 0;
    width: 34px;
    height: 34px;
    background: #f1f5f9;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: center;
}
.close-btn:hover {
    background: #e2e8f0;
}
.modal-sheet__layout {
    display: flex;
    flex: 1;
    min-height: 0;
}
.modal-sheet__body {
    flex: 1 1 44%;
    min-width: 0;
    overflow-y: auto;
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 18px;
}
.modal-sheet__map-panel {
    flex: 1 1 56%;
    min-width: 360px;
    border-left: 1px solid #e4e8ef;
    display: flex;
    flex-direction: column;
    background: #f8fafc;
    min-height: 0;
}
.modal-sheet__map-wrap {
    position: relative;
    flex: 1 1 auto;
    min-height: 240px;
}
.modal-sheet__map {
    height: 100%;
    width: 100%;
}
.map-loading {
    position: absolute;
    top: 12px;
    left: 12px;
    background: #fff;
    border: 1px solid #e4e8ef;
    border-radius: 8px;
    padding: 4px 10px;
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    box-shadow: var(--shadow-sm);
}
.ir-recenter-btn {
    position: absolute;
    bottom: 12px;
    left: 12px;
    z-index: 500;
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
    color: #1a2332;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}
.ir-recenter-btn:hover {
    background: #f1f5f9;
}
.map-legend {
    flex: 0 0 auto;
    max-height: 34%;
    padding: 14px 16px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 12px;
    border-top: 1px solid #e4e8ef;
}
.map-legend__row {
    display: flex;
    gap: 8px;
    align-items: flex-start;
}
.map-legend__dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    margin-top: 4px;
    flex-shrink: 0;
    border: 1.5px solid #fff;
    box-shadow: 0 0 0 1px #e4e8ef;
}
.map-legend__label {
    font-size: 12px;
    font-weight: 700;
    color: #1a2332;
}
.map-legend__role {
    font-weight: 600;
    color: #94a3b8;
    font-size: 11px;
    margin-left: 3px;
}
.map-legend__addr {
    font-size: 12px;
    color: #64748b;
    margin-top: 1px;
}
.map-legend__coords {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 1px;
    font-variant-numeric: tabular-nums;
}
.map-legend__empty {
    font-size: 12px;
    color: #94a3b8;
}

.pick-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.pick-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    border: 1.5px solid #e4e8ef;
    border-radius: 10px;
    background: #f8fafc;
    cursor: pointer;
    text-align: left;
    font-family: inherit;
    transition: all 0.15s;
}
.pick-row:hover {
    border-color: #ea580c;
    background: #fff7ed;
}

.review-info-panel {
    background: #f8fafc;
    border: 1.5px solid #e4e8ef;
    border-radius: 10px;
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 3px;
    flex: 1;
}
.review-info-panel__name {
    font-size: 14px;
    font-weight: 700;
    color: #1a2332;
}
.review-info-panel__sub {
    font-size: 12px;
    color: #94a3b8;
}
.review-description {
    background: #f8fafc;
    border: 1.5px solid #e4e8ef;
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 13px;
    line-height: 1.6;
    color: #475569;
    margin: 0;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}
.detail-grid--pad {
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px dashed #e4e8ef;
}
.detail-grid__value {
    margin-top: 2px;
    font-size: 13px;
    font-weight: 700;
    color: #1a2332;
}
.detail-grid__value--danger {
    color: #dc2626;
}

.field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.field__label {
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    letter-spacing: 0.3px;
}
.field__input {
    width: 100%;
    box-sizing: border-box;
    background: #f8fafc;
    border: 1.5px solid #e4e8ef;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 14px;
    font-family: inherit;
    color: #1a2332;
    outline: none;
}
.field__input:focus {
    border-color: #ea580c;
    background: #fff;
}
.field__input--date {
    background: #fff;
    padding: 8px 12px;
    font-size: 13px;
}
.field__textarea {
    resize: vertical;
    min-height: 60px;
    line-height: 1.6;
}

.checkbox-row {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 600;
    color: #475569;
    cursor: pointer;
}

.toggle-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.modal-actions {
    display: flex;
    gap: 10px;
    padding-top: 4px;
    flex-wrap: wrap;
}
.modal-actions .btn-ghost,
.modal-actions .btn-primary {
    flex: 1;
    justify-content: center;
    min-width: 120px;
}

/* ── Response timeline ── */
.ir-timeline {
    display: flex;
    flex-direction: column;
}
.ir-timeline__step {
    display: flex;
    gap: 12px;
}
.ir-timeline__rail {
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 22px;
    flex-shrink: 0;
}
.ir-timeline__dot {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #f1f5f9;
    color: #94a3b8;
    border: 2px solid #fff;
    box-shadow: 0 0 0 2px #e4e8ef;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.ir-timeline__dot--done {
    background: #ea580c;
    color: #fff;
    box-shadow: 0 0 0 2px #fed7aa;
}
.ir-timeline__line {
    flex: 1;
    width: 2px;
    min-height: 20px;
    background: #e4e8ef;
    margin: 2px 0;
}
.ir-timeline__line--done {
    background: #fdba74;
}
.ir-timeline__body {
    flex: 1;
    padding-bottom: 16px;
}
.ir-timeline__step:last-child .ir-timeline__body {
    padding-bottom: 0;
}
.ir-timeline__label {
    font-size: 13px;
    font-weight: 700;
    color: #1a2332;
}
.ir-timeline__time {
    font-size: 12px;
    color: #64748b;
    margin-top: 1px;
}
.ir-timeline__time--pending {
    color: #94a3b8;
    font-style: italic;
}

/* ── Named location rows (replaces raw coordinate display) ── */
.ir-location-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.ir-location-row {
    display: flex;
    gap: 10px;
}
.ir-location-row__dot {
    width: 11px;
    height: 11px;
    border-radius: 50%;
    margin-top: 4px;
    flex-shrink: 0;
    border: 1.5px solid #fff;
    box-shadow: 0 0 0 1px #e4e8ef;
}
.ir-location-row__body {
    flex: 1;
    min-width: 0;
}
.ir-location-row__top {
    display: flex;
    align-items: baseline;
    gap: 8px;
    flex-wrap: wrap;
}
.ir-location-row__label {
    font-size: 13px;
    font-weight: 700;
    color: #1a2332;
}
.ir-location-row__role {
    font-size: 11px;
    font-weight: 700;
    padding: 1px 8px;
    border-radius: 10px;
}
.ir-location-row__role--household {
    background: #fef2f2;
    color: #f97316;
}
.ir-location-row__role--guard {
    background: #eff6ff;
    color: #2563eb;
}
.ir-location-row__addr {
    font-size: 12.5px;
    color: #475569;
    margin-top: 2px;
}
.ir-location-row__coords {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 1px;
    font-variant-numeric: tabular-nums;
}
.ir-location-empty {
    font-size: 12px;
    color: #94a3b8;
    margin: 0;
}

.toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 10000;
    padding: 12px 20px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 700;
    box-shadow: var(--shadow-lg);
    color: #fff;
}
.toast--success {
    background: #059669;
}
.toast--error {
    background: #f97316;
}

.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.22s ease;
}
.modal-enter-active .modal-sheet,
.modal-leave-active .modal-sheet {
    transition:
        transform 0.22s ease,
        opacity 0.22s ease;
}
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
.modal-enter-from .modal-sheet,
.modal-leave-to .modal-sheet {
    transform: scale(0.97) translateY(12px);
}

.table-scroll {
    overflow-x: auto;
}
.data-table {
    min-width: 1150px;
}

@media (max-width: 900px) {
    .modal-sheet--wide {
        max-width: 640px;
        height: auto;
        max-height: 92vh;
    }
    .modal-sheet__layout {
        flex-direction: column;
        overflow-y: auto;
    }
    .modal-sheet__map-panel {
        min-width: 0;
        border-left: none;
        border-top: 1px solid #e4e8ef;
    }
    .modal-sheet__map-wrap {
        min-height: 260px;
    }
}

@media (max-width: 640px) {
    .page-root {
        padding: 16px;
    }
    .data-table {
        min-width: 640px;
    }
    .table-card {
        overflow-x: auto;
    }
    .search-input-row--standalone {
        max-width: none;
    }
}
.duress-banner {
    background: #fef2f2;
    border: 1.5px solid #fecaca;
    color: #f97316;
    font-size: 13px;
    font-weight: 700;
    padding: 10px 14px;
    border-radius: 10px;
    text-align: center;
}
.audio-player {
    width: 100%;
    height: 36px;
}
</style>

<style>
/* Global (unscoped) — Leaflet renders these outside Vue's template, so
   scoped attributes never reach them. */
.map-pin__dot {
    display: block;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    border: 2px solid #fff;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.4);
}
.leaflet-popup-content {
    font-family: 'DM Sans', system-ui, sans-serif;
    font-size: 12px;
}
.ir-leaflet-label {
    font-family: 'DM Sans', system-ui, sans-serif;
    font-size: 11px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 6px;
    border: none;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
    color: #fff;
}
.ir-leaflet-label--household {
    background: #f97316;
}
.ir-leaflet-label--household::before {
    border-top-color: #f97316 !important;
}
.ir-leaflet-label--guard {
    background: #059669;
}
.ir-leaflet-label--guard::before {
    border-top-color: #059669 !important;
}

.unit-plain {
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
}
</style>

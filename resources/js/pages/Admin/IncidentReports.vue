<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import {
    BellRing,
    CheckCircle2,
    Crosshair,
    MapPin,
    Siren,
    UserCheck,
    X,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';

const auth = useAuthStore();

const breadcrumbs: BreadcrumbItem[] = [
    // { title: 'Dashboard', href: '/dashboard' },
    // { title: 'Incident Reports', href: '/incident-reports' },
];

onMounted(() => {
    if (auth.user?.role !== 'admin') {
        router.visit('/dashboard'); // redirect non-admins away
    }
});

const reports = ref<any>({ data: [], total: 0, from: 0, to: 0, links: [] });
const reportList = ref<any[]>([]);
const loading = ref(false);
const searchQuery = ref('');
const filterStatus = ref('');
const filterOutcome = ref('');
let searchTimeout: any = null;

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

const selectedReport = ref<any>(null);
const showDetail = ref(false);
const detailLoading = ref(false);
const actionLoading = ref(false);
const actionNotes = ref('');
const flash = ref<{ msg: string; type: 'success' | 'error' } | null>(null);

const showExport = ref(false);
const exportLoading = ref(false);

const showEmail = ref(false);
const emailInput = ref('');
const emailList = ref<string[]>([]);
const emailLoading = ref(false);
const emailError = ref('');

const getHeaders = () => ({
    headers: { Authorization: `Bearer ${localStorage.getItem('token')}` },
});

function showFlash(msg: string, type: 'success' | 'error' = 'success') {
    flash.value = { msg, type };
    setTimeout(() => (flash.value = null), 5000);
}

function validateDates(): boolean {
    if (!dateFrom.value || !dateTo.value) {
        dateError.value = 'Both dates are required.';
        return false;
    }
    if (new Date(dateTo.value) < new Date(dateFrom.value)) {
        dateError.value = '"To" date must be after "From" date.';
        return false;
    }
    const diffDays =
        (new Date(dateTo.value).getTime() -
            new Date(dateFrom.value).getTime()) /
        (1000 * 60 * 60 * 24);
    if (diffDays > 366) {
        dateError.value = 'Date range cannot exceed 1 year.';
        return false;
    }
    dateError.value = '';
    return true;
}

const stats = computed(() => {
    const all = reportList.value;
    return {
        total: all.length,
        pending: all.filter((r) => r.status === 'pending').length,
        misuse: all.filter((r) => r.outcome === 'misuse').length,
        legitimate: all.filter((r) => r.outcome === 'legitimate').length,
        warned: all.filter((r) => r.status === 'warned').length,
        blocked: all.filter((r) => r.status === 'blocked').length,
    };
});

async function loadReports(url?: string) {
    if (!validateDates()) return;
    loading.value = true;
    try {
        const { data } = await axios.get(
            url || `${import.meta.env.VITE_APP_URL}/api/admin/incident-reports`,
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

function setStatus(v: string) {
    filterStatus.value = v;
    loadReports();
}

function setOutcome(v: string) {
    filterOutcome.value = v;
    loadReports();
}

async function openDetail(report: any) {
    selectedReport.value = report;
    showDetail.value = true;
    detailLoading.value = true;
    actionNotes.value = '';
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/admin/incident-reports/${report.id}`,
            getHeaders(),
        );
        selectedReport.value = data;
    } catch {
        showFlash('Failed to load report detail.', 'error');
    } finally {
        detailLoading.value = false;
        await nextTick();
        initMap();
    }
}

function closeDetail() {
    showDetail.value = false;
    selectedReport.value = null;
    actionNotes.value = '';
    destroyMap();
}

async function takeAction(action: string) {
    if (!selectedReport.value) return;
    actionLoading.value = true;
    try {
        const { data } = await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/admin/incident-reports/${selectedReport.value.id}/action`,
            { action, admin_notes: actionNotes.value || undefined },
            getHeaders(),
        );
        showFlash(data.message);
        selectedReport.value = { ...selectedReport.value, ...data.report };
        await loadReports();
    } catch (err: any) {
        showFlash(err.response?.data?.message ?? 'Action failed.', 'error');
    } finally {
        actionLoading.value = false;
    }
}

function buildExportParams() {
    return new URLSearchParams({
        date_from: dateFrom.value,
        date_to: dateTo.value,
        ...(filterStatus.value ? { status: filterStatus.value } : {}),
        ...(filterOutcome.value ? { outcome: filterOutcome.value } : {}),
        ...(searchQuery.value ? { search: searchQuery.value } : {}),
    }).toString();
}

async function downloadPdf() {
    if (!validateDates()) return;
    exportLoading.value = true;
    showExport.value = false;
    try {
        const response = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/admin/incident-reports/export/pdf?${buildExportParams()}`,
            {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                },
                responseType: 'blob',
            },
        );
        const blob = new Blob([response.data], { type: 'application/pdf' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `incident-reports-${dateFrom.value}-to-${dateTo.value}.pdf`;
        a.click();
        URL.revokeObjectURL(url);
        showFlash('PDF downloaded successfully.');
    } catch {
        showFlash('Export failed. Try again.', 'error');
    } finally {
        exportLoading.value = false;
    }
}

function addEmail() {
    const e = emailInput.value.trim();
    if (!e) return;
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e)) {
        emailError.value = 'Invalid email address.';
        return;
    }
    if (emailList.value.includes(e)) {
        emailError.value = 'Already added.';
        return;
    }
    if (emailList.value.length >= 10) {
        emailError.value = 'Maximum 10 recipients.';
        return;
    }
    emailList.value.push(e);
    emailInput.value = '';
    emailError.value = '';
}

function removeEmail(e: string) {
    emailList.value = emailList.value.filter((x) => x !== e);
}

async function sendEmail() {
    if (!validateDates()) return;
    if (emailList.value.length === 0) {
        emailError.value = 'Add at least one recipient.';
        return;
    }
    emailLoading.value = true;
    try {
        const { data } = await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/admin/incident-reports/export/email`,
            {
                date_from: dateFrom.value,
                date_to: dateTo.value,
                emails: emailList.value,
                status: filterStatus.value || undefined,
                outcome: filterOutcome.value || undefined,
                search: searchQuery.value || undefined,
            },
            getHeaders(),
        );
        showFlash(data.message);
        showEmail.value = false;
        emailList.value = [];
        emailInput.value = '';
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to send email.',
            'error',
        );
    } finally {
        emailLoading.value = false;
    }
}

// ══════════ Date/number formatting ══════════

function toDate(d: string | null | undefined): Date | null {
    if (!d) return null;
    let s = String(d).trim();
    // Already has an explicit UTC/offset marker — parse as-is.
    if (/Z$|[+-]\d{2}:?\d{2}$/.test(s)) return new Date(s);
    // Bare "YYYY-MM-DD HH:mm:ss" (raw MySQL/Carbon output, no timezone) is
    // parsed as LOCAL time by the browser, but the backend stores/serves
    // these as UTC — normalize so it matches fields that already carry an
    // offset, fixing the "wrong time" bug.
    s = s.replace(' ', 'T');
    return new Date(/T\d{2}:\d{2}/.test(s) ? s + 'Z' : s);
}

function fmtDate(d: string) {
    const date = toDate(d);
    if (!date) return '—';
    return date.toLocaleDateString('en-ZA', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}
function fmtDateTime(d: string | null | undefined) {
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

function fmtUnit(u: string | number | null | undefined) {
    if (!u) return '';
    const s = String(u).trim();
    return /unit/i.test(s) ? s : `Unit ${s}`;
}

function fmtDistance(v: number | string | null | undefined) {
    if (v === null || v === undefined || v === '') return '—';
    const n = Number(v);
    if (isNaN(n)) return '—';
    // Assumes backend sends meters — adjust if it's already km.
    return n >= 1000 ? `${(n / 1000).toFixed(2)} km` : `${Math.round(n)} m`;
}

function fmtAccuracy(v: number | string | null | undefined) {
    if (v === null || v === undefined || v === '') return '—';
    const n = Number(v);
    if (isNaN(n)) return '—';
    return `${Math.round(n)} m`;
}

function fmtDuration(v: number | string | null | undefined) {
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

// Unit number only means something when the household's location source is
// their registered address — for a GPS-sourced alert, the unit on file may
// not be where the alert happened. alert_location_source lives on the
// household's user record (not the alert), per Karabo.
const isRegisteredAddressSource = computed(
    () =>
        selectedReport.value?.household?.alert_location_source ===
        'registered_address',
);

// ══════════ Map (Leaflet, npm package — matches LiveAlertsCard.vue) ══════════
// Points, roles, reverse-geocoded names, an OSRM route between the
// patroller's start/arrival, and permanent named tooltips on every marker.
// NOTE: this controller's relation is `emergencyAlert`, not `alert` — the
// underlying EmergencyAlert model/columns are the same as Estate/Guard.

const mapEl = ref<HTMLElement | null>(null);
const mapLoading = ref(false);
const geocoded = ref<Record<string, string | null>>({});
const routeCoords = ref<[number, number][] | null>(null);
let mapInstance: any = null;

type MapPoint = {
    key: string;
    label: string;
    role: 'household' | 'guard';
    person: string;
    color: string;
    lat: number;
    lng: number;
};

function roleLabel(role: 'household' | 'guard') {
    return role === 'guard' ? 'Patroller' : 'Household';
}

const mapPoints = computed<MapPoint[]>(() => {
    const r = selectedReport.value;
    if (!r) return [];
    const a = r.emergencyAlert;
    const res = r.resolution;
    const householdName = r.household?.name || 'Household';
    const patrollerName = r.reporter?.name || 'Patroller';
    const pts: MapPoint[] = [];

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
            label: 'Patroller Start',
            role: 'guard',
            person: patrollerName,
            color: '#2563eb',
            lat: Number(res.start_latitude),
            lng: Number(res.start_longitude),
        });
    }
    if (res?.arrival_latitude && res?.arrival_longitude) {
        pts.push({
            key: 'arrival',
            label: 'Patroller Arrival',
            role: 'guard',
            person: patrollerName,
            color: '#059669',
            lat: Number(res.arrival_latitude),
            lng: Number(res.arrival_longitude),
        });
    }
    return pts;
});

// The route drawn on the map: prefer the patroller's actual start → arrival
// journey; fall back to start → household's last known location if no
// arrival fix was recorded.
const routeEndpoints = computed(() => {
    const start = mapPoints.value.find((p) => p.key === 'start');
    const arrival = mapPoints.value.find((p) => p.key === 'arrival');
    if (start && arrival) return { from: start, to: arrival };
    const household = mapPoints.value.find((p) => p.role === 'household');
    if (start && household) return { from: start, to: household };
    return null;
});

const geocodeCache = new Map<string, string | null>();

async function reverseGeocode(
    lat: number,
    lng: number,
): Promise<string | null> {
    const key = `${lat.toFixed(5)},${lng.toFixed(5)}`;
    if (geocodeCache.has(key)) return geocodeCache.get(key) ?? null;
    try {
        const url = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`;
        const res = await fetch(url, {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) return null;
        const data = await res.json();
        let result: string | null = null;
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
async function fetchRoute(
    lat1: number,
    lng1: number,
    lat2: number,
    lng2: number,
): Promise<[number, number][] | null> {
    try {
        const url = `https://router.project-osrm.org/route/v1/driving/${lng1},${lat1};${lng2},${lat2}?overview=full&geometries=geojson`;
        const res = await fetch(url);
        if (!res.ok) return null;
        const data = await res.json();
        const coords = data?.routes?.[0]?.geometry?.coordinates;
        if (!coords) return null;
        return coords.map(([lng, lat]: [number, number]) => [lat, lng]);
    } catch {
        return null;
    }
}

function buildMap(container: HTMLElement) {
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

function drawPoints(map: any) {
    const layer = L.layerGroup().addTo(map);
    const bounds: [number, number][] = [];

    for (const p of mapPoints.value) {
        const icon = L.divIcon({
            className: 'map-pin',
            html: `<span class="map-pin__dot" style="background:${p.color}"></span>`,
            iconSize: [16, 16],
            iconAnchor: [8, 8],
        });
        L.marker([p.lat, p.lng], { icon })
            .bindTooltip(
                `<strong>${p.label}</strong><br>${roleLabel(p.role)} — ${p.person}`,
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
            color: '#2563eb',
            weight: 4,
            opacity: 0.75,
        }).addTo(layer);
    }

    return bounds;
}

function fitToPoints(map: any) {
    const bounds = mapPoints.value.map((p) => [p.lat, p.lng]) as [
        number,
        number,
    ][];
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
    const a = r.emergencyAlert;
    const res = r.resolution;
    return [
        {
            key: 'triggered',
            label: 'Alert Triggered',
            time: a?.created_at,
            icon: Siren,
            done: !!a?.created_at,
        },
        {
            key: 'ack',
            label: 'First Acknowledged',
            time: a?.first_ack_at,
            icon: BellRing,
            done: !!a?.first_ack_at,
        },
        {
            key: 'accepted',
            label: 'Patroller Accepted',
            time: res?.accepted_at,
            icon: UserCheck,
            done: !!res?.accepted_at,
        },
        {
            key: 'arrived',
            label: 'Patroller Arrived',
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

const statusOptions = [
    { value: '', label: 'All' },
    { value: 'pending', label: 'Pending' },
    { value: 'reviewed', label: 'Reviewed' },
    { value: 'warned', label: 'Warned' },
    { value: 'blocked', label: 'Blocked' },
    { value: 'dismissed', label: 'Dismissed' },
] as const;

const outcomeOptions = [
    { value: '', label: 'All' },
    { value: 'misuse', label: 'Misuse' },
    { value: 'legitimate', label: 'Legitimate' },
] as const;

const statusConfig: Record<string, { label: string; cls: string }> = {
    pending: { label: 'Pending', cls: 'bg-amber-50 text-amber-700' },
    reviewed: { label: 'Reviewed', cls: 'bg-blue-50 text-blue-700' },
    warned: { label: 'Warned', cls: 'bg-orange-50 text-orange-700' },
    blocked: { label: 'Blocked', cls: 'bg-red-50 text-red-600' },
    dismissed: { label: 'Dismissed', cls: 'bg-slate-100 text-slate-500' },
};
const outcomeConfig: Record<string, { label: string; cls: string }> = {
    legitimate: { label: 'Legitimate', cls: 'bg-emerald-50 text-emerald-700' },
    misuse: { label: 'Misuse', cls: 'bg-red-50 text-red-600' },
};
const misuseCategoryLabel: Record<string, string> = {
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
            <!-- PAGE HEADER -->
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Safety</div>
                    <h1 class="page-header__title">Incident Reports</h1>
                    <p class="page-header__sub">
                        SOS alert reports submitted by patrollers — review and
                        take action
                    </p>
                </div>
                <div class="page-header__right">
                    <button
                        class="btn-secondary"
                        :disabled="exportLoading"
                        @click="showExport = true"
                    >
                        <svg
                            v-if="!exportLoading"
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
                                d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"
                            />
                        </svg>
                        <svg
                            v-else
                            class="spin h-4 w-4"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                        >
                            <circle
                                class="opacity-25"
                                cx="12"
                                cy="12"
                                r="10"
                                stroke="currentColor"
                                stroke-width="4"
                            />
                            <path
                                class="opacity-75"
                                fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                            />
                        </svg>
                        {{ exportLoading ? 'Generating…' : 'Download PDF' }}
                    </button>
                    <button class="btn-primary" @click="showEmail = true">
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
                                d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
                            />
                        </svg>
                        Email Report
                    </button>
                </div>
            </div>

            <!-- STAT CARDS -->
            <div class="stat-row stat-row--six">
                <div class="stat-card">
                    <div class="stat-card__label">Total</div>
                    <div class="stat-card__value">{{ reports.total ?? 0 }}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Pending</div>
                    <div class="stat-card__value stat-card__value--orange">
                        {{ stats.pending }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Misuse</div>
                    <div class="stat-card__value stat-card__value--red">
                        {{ stats.misuse }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Legitimate</div>
                    <div class="stat-card__value stat-card__value--green">
                        {{ stats.legitimate }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Warned</div>
                    <div class="stat-card__value stat-card__value--orange">
                        {{ stats.warned }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Blocked</div>
                    <div class="stat-card__value stat-card__value--red">
                        {{ stats.blocked }}
                    </div>
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

            <!-- TABLE CARD -->
            <div class="table-card">
                <div v-if="loading" class="empty-state">
                    <svg
                        class="spin h-6 w-6 text-slate-400"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                    >
                        <circle
                            class="opacity-25"
                            cx="12"
                            cy="12"
                            r="10"
                            stroke="currentColor"
                            stroke-width="4"
                        />
                        <path
                            class="opacity-75"
                            fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                        />
                    </svg>
                    <span class="mt-2 text-sm text-slate-400"
                        >Loading reports…</span
                    >
                </div>

                <div v-else-if="reportList.length === 0" class="empty-state">
                    <div class="empty-state__icon">
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-8 w-8"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="1.2"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                            />
                        </svg>
                    </div>
                    <p class="empty-state__title">No reports found</p>
                    <p class="empty-state__sub">
                        Try adjusting the date range or filters
                    </p>
                </div>

                <table v-else class="data-table">
                    <thead>
                        <tr>
                            <th>Household</th>
                            <th>Reporter</th>
                            <th>Outcome</th>
                            <th>Category</th>
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
                                <div class="reporter-cell">
                                    <div class="reporter-cell__avatar">
                                        {{
                                            (report.household?.name || 'H')
                                                .charAt(0)
                                                .toUpperCase()
                                        }}
                                    </div>
                                    <div>
                                        <div class="reporter-cell__name-row">
                                            <span class="reporter-cell__name">
                                                {{
                                                    report.household?.name ??
                                                    '—'
                                                }}
                                            </span>
                                            <span
                                                v-if="
                                                    report.household
                                                        ?.unit_number
                                                "
                                                class="ir-unit-badge ir-unit-badge--table"
                                            >
                                                {{
                                                    fmtUnit(
                                                        report.household
                                                            .unit_number,
                                                    )
                                                }}
                                            </span>
                                        </div>
                                        <div class="reporter-cell__sub">
                                            {{ report.household?.email }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="reporter-cell__name">
                                    {{ report.reporter?.name ?? '—' }}
                                </div>
                                <div class="reporter-cell__sub">
                                    {{ report.reporter?.email }}
                                </div>
                            </td>
                            <td>
                                <span
                                    class="type-badge"
                                    :class="outcomeConfig[report.outcome]?.cls"
                                    >{{
                                        outcomeConfig[report.outcome]?.label ??
                                        report.outcome
                                    }}</span
                                >
                            </td>
                            <td class="td-time">
                                {{
                                    report.misuse_category
                                        ? misuseCategoryLabel[
                                              report.misuse_category
                                          ]
                                        : '—'
                                }}
                            </td>
                            <td>
                                <span
                                    class="type-badge"
                                    :class="statusConfig[report.status]?.cls"
                                    >{{
                                        statusConfig[report.status]?.label ??
                                        report.status
                                    }}</span
                                >
                            </td>
                            <td class="td-time">
                                {{ fmtDate(report.created_at) }}
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

                <div
                    class="pagination-bar"
                    v-if="!loading && reportList.length > 0"
                >
                    <span class="pagination-bar__info">
                        Showing {{ reports.from ?? 0 }} to
                        {{ reports.to ?? 0 }} of {{ reports.total ?? 0 }}
                        reports
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

        <!-- ═══════════════ DETAIL MODAL ═══════════════ -->
        <Teleport to="body">
            <transition name="modal">
                <div
                    v-if="showDetail"
                    class="modal-backdrop"
                    @click.self="closeDetail"
                >
                    <div class="modal-sheet modal-sheet--wide">
                        <div class="modal-sheet__header">
                            <div class="modal-sheet__header-left">
                                <div>
                                    <div class="modal-sheet__title">
                                        Incident Report #{{
                                            selectedReport?.id
                                        }}
                                    </div>
                                    <div class="modal-sheet__sub">
                                        {{
                                            fmtDateTime(
                                                selectedReport?.created_at,
                                            )
                                        }}
                                    </div>
                                </div>
                            </div>
                            <button class="close-btn" @click="closeDetail">
                                <X :size="16" />
                            </button>
                        </div>

                        <div
                            v-if="detailLoading"
                            class="flex items-center justify-center py-16"
                        >
                            <svg
                                class="spin h-6 w-6 text-slate-400"
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                            >
                                <circle
                                    class="opacity-25"
                                    cx="12"
                                    cy="12"
                                    r="10"
                                    stroke="currentColor"
                                    stroke-width="4"
                                />
                                <path
                                    class="opacity-75"
                                    fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                                />
                            </svg>
                        </div>

                        <div v-else class="modal-sheet__layout">
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
                                        >{{
                                            misuseCategoryLabel[
                                                selectedReport.misuse_category
                                            ]
                                        }}</span
                                    >
                                </div>

                                <div
                                    v-if="
                                        selectedReport?.emergencyAlert
                                            ?.cancel_pin_used === 'duress'
                                    "
                                    class="duress-banner"
                                >
                                    ⚠ Duress PIN was used to cancel this alert
                                </div>

                                <div class="toggle-row">
                                    <span
                                        v-if="
                                            selectedReport?.emergencyAlert
                                                ?.alert_type
                                        "
                                        class="type-badge bg-slate-100 text-slate-600"
                                    >
                                        {{
                                            selectedReport.emergencyAlert
                                                .alert_type
                                        }}
                                    </span>
                                    <span
                                        v-if="
                                            selectedReport?.emergencyAlert
                                                ?.muted
                                        "
                                        class="type-badge bg-slate-100 text-slate-600"
                                    >
                                        Muted
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
                                            class="ir-unit-badge ir-unit-badge--modal"
                                            v-if="
                                                isRegisteredAddressSource &&
                                                selectedReport?.household
                                                    ?.unit_number
                                            "
                                        >
                                            {{
                                                fmtUnit(
                                                    selectedReport.household
                                                        .unit_number,
                                                )
                                            }}
                                        </div>
                                        <div class="review-info-panel__sub">
                                            {{
                                                selectedReport?.household?.email
                                            }}
                                        </div>
                                        <div class="review-info-panel__sub">
                                            {{
                                                selectedReport?.household?.phone
                                            }}
                                        </div>
                                    </div>
                                    <div class="review-info-panel">
                                        <div class="field__label">
                                            Patroller
                                        </div>
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
                                                :style="{ background: p.color }"
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
                                                        {{ roleLabel(p.role) }}
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
                                                        selectedReport
                                                            ?.emergencyAlert
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
                                                        selectedReport
                                                            ?.emergencyAlert
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
                                                Patroller-Reported Arrival
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
                                                Patroller-Reported Departure
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
                                        >Patroller's Account</label
                                    >
                                    <p class="review-description">
                                        {{ selectedReport?.narrative }}
                                    </p>
                                </div>

                                <div
                                    v-if="
                                        selectedReport?.emergencyAlert
                                            ?.audio_path
                                    "
                                    class="field"
                                >
                                    <label class="field__label"
                                        >Alert Audio</label
                                    >
                                    <audio
                                        controls
                                        :src="
                                            selectedReport.emergencyAlert
                                                .audio_path
                                        "
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

                                <div
                                    v-if="selectedReport?.resolution"
                                    class="review-info-panel"
                                >
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
                                    v-if="selectedReport?.emergency_alert_id"
                                    class="flag-panel flag-panel--warn"
                                >
                                    Linked to Emergency Alert #{{
                                        selectedReport.emergency_alert_id
                                    }}
                                </div>

                                <div
                                    v-if="selectedReport?.actioned_by"
                                    class="review-info-panel"
                                >
                                    <div class="field__label">
                                        Previous Action
                                    </div>
                                    <p class="review-info-panel__name">
                                        By
                                        {{ selectedReport.actioned_by?.name }}
                                        on
                                        {{
                                            fmtDateTime(
                                                selectedReport.actioned_at,
                                            )
                                        }}
                                    </p>
                                    <p
                                        v-if="selectedReport.admin_notes"
                                        class="review-info-panel__sub"
                                    >
                                        {{ selectedReport.admin_notes }}
                                    </p>
                                </div>

                                <div
                                    v-if="
                                        !['blocked', 'dismissed'].includes(
                                            selectedReport?.status,
                                        )
                                    "
                                >
                                    <div class="field">
                                        <label class="field__label"
                                            >Admin Notes (optional)</label
                                        >
                                        <textarea
                                            v-model="actionNotes"
                                            class="field__input field__textarea"
                                            rows="2"
                                            placeholder="Add internal notes…"
                                        ></textarea>
                                    </div>
                                    <div class="modal-actions">
                                        <button
                                            v-if="
                                                selectedReport?.status ===
                                                'pending'
                                            "
                                            class="btn-secondary"
                                            :disabled="actionLoading"
                                            @click="takeAction('review')"
                                        >
                                            Mark Reviewed
                                        </button>
                                        <button
                                            v-if="
                                                selectedReport?.outcome ===
                                                    'misuse' &&
                                                selectedReport?.status !==
                                                    'warned'
                                            "
                                            class="btn-secondary"
                                            :disabled="actionLoading"
                                            @click="takeAction('warn')"
                                        >
                                            Send Warning
                                        </button>
                                        <button
                                            v-if="
                                                selectedReport?.outcome ===
                                                'misuse'
                                            "
                                            class="btn-danger"
                                            :disabled="actionLoading"
                                            @click="takeAction('block')"
                                        >
                                            Block SOS
                                        </button>
                                        <button
                                            class="btn-ghost"
                                            :disabled="actionLoading"
                                            @click="takeAction('dismiss')"
                                        >
                                            Dismiss
                                        </button>
                                    </div>
                                </div>
                                <div
                                    v-else
                                    class="review-description text-center"
                                >
                                    Report
                                    <strong>{{
                                        selectedReport?.status
                                    }}</strong>
                                    — no further action required.
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
                                                    >({{ roleLabel(p.role) }} —
                                                    {{ p.person }})</span
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

        <!-- ═══════════════ DOWNLOAD CONFIRM MODAL ═══════════════ -->
        <Teleport to="body">
            <transition name="modal">
                <div
                    v-if="showExport"
                    class="modal-backdrop"
                    @click.self="showExport = false"
                >
                    <div class="modal-sheet modal-sheet--sm">
                        <div class="modal-sheet__header">
                            <div class="modal-sheet__header-left">
                                <div>
                                    <div class="modal-sheet__title">
                                        Download PDF Report
                                    </div>
                                    <div class="modal-sheet__sub">
                                        Generated for the selected period and
                                        filters
                                    </div>
                                </div>
                            </div>
                            <button
                                class="close-btn"
                                @click="showExport = false"
                            >
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
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                        <div class="modal-sheet__body">
                            <div class="review-info-panel">
                                <div
                                    class="toggle-row"
                                    style="justify-content: space-between"
                                >
                                    <span class="field__label" style="margin: 0"
                                        >Period</span
                                    >
                                    <span class="review-info-panel__name">
                                        {{ dateFrom }} → {{ dateTo }}
                                    </span>
                                </div>
                                <div
                                    v-if="filterStatus || filterOutcome"
                                    class="toggle-row"
                                    style="
                                        justify-content: space-between;
                                        margin-top: 6px;
                                    "
                                >
                                    <span class="field__label" style="margin: 0"
                                        >Filters</span
                                    >
                                    <span class="review-info-panel__sub">
                                        {{
                                            [filterStatus, filterOutcome]
                                                .filter(Boolean)
                                                .join(', ')
                                        }}
                                    </span>
                                </div>
                            </div>
                            <div class="modal-actions">
                                <button
                                    class="btn-ghost"
                                    @click="showExport = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    class="btn-primary"
                                    @click="downloadPdf"
                                >
                                    Download PDF
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>

        <!-- ═══════════════ EMAIL MODAL ═══════════════ -->
        <Teleport to="body">
            <transition name="modal">
                <div
                    v-if="showEmail"
                    class="modal-backdrop"
                    @click.self="showEmail = false"
                >
                    <div class="modal-sheet modal-sheet--sm">
                        <div class="modal-sheet__header">
                            <div class="modal-sheet__header-left">
                                <div>
                                    <div class="modal-sheet__title">
                                        Email PDF Report
                                    </div>
                                    <div class="modal-sheet__sub">
                                        Compiled for the selected period and
                                        sent as an attachment
                                    </div>
                                </div>
                            </div>
                            <button
                                class="close-btn"
                                @click="showEmail = false"
                            >
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
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                        <div class="modal-sheet__body">
                            <div class="review-info-panel">
                                <div
                                    class="toggle-row"
                                    style="justify-content: space-between"
                                >
                                    <span class="field__label" style="margin: 0"
                                        >Period</span
                                    >
                                    <span class="review-info-panel__name">
                                        {{ dateFrom }} → {{ dateTo }}
                                    </span>
                                </div>
                            </div>

                            <div class="field">
                                <label class="field__label">Recipients</label>
                                <div class="email-add-row">
                                    <input
                                        v-model="emailInput"
                                        type="email"
                                        class="field__input"
                                        placeholder="email@example.com"
                                        @keydown.enter.prevent="addEmail"
                                    />
                                    <button
                                        class="btn-primary btn-primary--compact"
                                        @click="addEmail"
                                    >
                                        Add
                                    </button>
                                </div>
                                <p v-if="emailError" class="date-error">
                                    {{ emailError }}
                                </p>
                                <div
                                    v-if="emailList.length > 0"
                                    class="email-chip-row"
                                >
                                    <span
                                        v-for="e in emailList"
                                        :key="e"
                                        class="email-chip"
                                    >
                                        {{ e }}
                                        <button
                                            class="email-chip__remove"
                                            @click="removeEmail(e)"
                                        >
                                            ×
                                        </button>
                                    </span>
                                </div>
                                <p
                                    v-else
                                    class="empty-state__sub"
                                    style="
                                        text-align: left;
                                        padding: 0;
                                        margin-top: 4px;
                                    "
                                >
                                    No recipients yet — press Enter or click
                                    Add.
                                </p>
                            </div>

                            <div class="modal-actions">
                                <button
                                    class="btn-ghost"
                                    @click="
                                        showEmail = false;
                                        emailList = [];
                                        emailError = '';
                                    "
                                >
                                    Cancel
                                </button>
                                <button
                                    class="btn-primary"
                                    :disabled="
                                        emailLoading || emailList.length === 0
                                    "
                                    @click="sendEmail"
                                >
                                    <svg
                                        v-if="emailLoading"
                                        class="spin h-4 w-4"
                                        xmlns="http://www.w3.org/2000/svg"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                    >
                                        <circle
                                            class="opacity-25"
                                            cx="12"
                                            cy="12"
                                            r="10"
                                            stroke="currentColor"
                                            stroke-width="4"
                                        />
                                        <path
                                            class="opacity-75"
                                            fill="currentColor"
                                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                                        />
                                    </svg>
                                    {{
                                        emailLoading
                                            ? 'Sending…'
                                            : `Send to ${emailList.length} recipient${emailList.length !== 1 ? 's' : ''}`
                                    }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>

        <!-- ═══════════════ FLASH TOAST ═══════════════ -->
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
    --c-bg: #f4f6f9;
    --c-surface: #ffffff;
    --c-border: #e4e8ef;
    --c-text: #1a2332;
    --c-muted: #64748b;
    --c-faint: #94a3b8;
    --c-primary: #ea580c;
    --c-primary-h: #c2410c;
    --c-danger: #dc2626;
    --c-danger-h: #b91c1c;
    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 16px;
    --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
    --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.08);
    --shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.14);
    font-family: 'DM Sans', system-ui, sans-serif;
}

/* PAGE ROOT */
.page-root {
    padding: 28px 32px;
    display: flex;
    flex-direction: column;
    gap: 20px;
    min-height: 100%;
    background: #f4f6f9;
}

/* PAGE HEADER */
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
.page-header__right {
    display: flex;
    align-items: center;
    gap: 10px;
}

.btn-secondary {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #ffffff;
    color: #1a2332;
    border: 1.5px solid #e4e8ef;
    border-radius: 12px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.18s;
    white-space: nowrap;
    font-family: 'DM Sans', system-ui, sans-serif;
}
.btn-secondary:hover:not(:disabled) {
    border-color: #ea580c;
    color: #ea580c;
    background: #fff7ed;
}
.btn-secondary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.btn-secondary--compact {
    padding: 8px 14px;
    font-size: 12px;
}

/* STAT ROW */
.stat-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
}
.stat-row--six {
    grid-template-columns: repeat(6, 1fr);
}
.stat-card {
    background: #ffffff;
    border: 1px solid #e4e8ef;
    border-radius: 16px;
    padding: 20px 22px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    position: relative;
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    transition:
        box-shadow 0.2s,
        transform 0.2s;
}
.stat-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-1px);
}
.stat-card__label {
    font-size: 11px;
    font-weight: 600;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.stat-card__value {
    font-size: 30px;
    font-weight: 800;
    color: #1a2332;
    line-height: 1;
    letter-spacing: -1px;
}
.stat-card__value--red {
    color: #dc2626;
}
.stat-card__value--orange {
    color: #ea580c;
}
.stat-card__value--green {
    color: #059669;
}

/* FILTER CARD */
.filter-card {
    background: #ffffff;
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
    transition: border-color 0.15s;
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
.search-input::placeholder {
    color: #94a3b8;
}
.search-clear {
    font-size: 16px;
    color: #94a3b8;
    cursor: pointer;
    line-height: 1;
    padding: 0 2px;
    transition: color 0.15s;
}
.search-clear:hover {
    color: #64748b;
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

/* FILTER BAR / CHIPS */
.filter-bar__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.chip {
    padding: 5px 14px;
    border-radius: 20px;
    border: 1px solid #e4e8ef;
    background: #ffffff;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
    transition: all 0.15s;
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

/* TABLE CARD */
.table-card {
    background: #ffffff;
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
.empty-state__icon {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    background: #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #94a3b8;
    margin-bottom: 6px;
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
    transition: background 0.12s;
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

.reporter-cell {
    display: flex;
    align-items: center;
    gap: 10px;
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
.td-time {
    color: #64748b;
    white-space: nowrap;
    font-size: 12px;
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

.row-action-btn {
    padding: 6px 12px;
    border-radius: 8px;
    border: 1.5px solid #e4e8ef;
    background: #ffffff;
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    cursor: pointer;
    transition: all 0.15s;
    font-family: inherit;
    white-space: nowrap;
}
.row-action-btn:hover {
    border-color: #ea580c;
    color: #ea580c;
    background: #fff7ed;
}

/* PAGINATION */
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
    background: #ffffff;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
    transition: all 0.15s;
}
.page-btn:hover:not(.page-btn--disabled) {
    border-color: #ea580c;
    color: #ea580c;
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

/* BUTTONS */
.btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #ea580c !important;
    color: #ffffff !important;
    border: none;
    border-radius: 12px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.18s;
    box-shadow: 0 2px 8px rgba(234, 88, 12, 0.3);
    white-space: nowrap;
    font-family: 'DM Sans', system-ui, sans-serif;
}
.btn-primary:hover:not(:disabled) {
    background: #c2410c !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(234, 88, 12, 0.35);
}
.btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}
.btn-primary--compact {
    padding: 9px 16px;
    font-size: 12px;
}

.btn-ghost {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #f1f5f9;
    color: #64748b;
    border: none;
    border-radius: 12px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
}
.btn-ghost:hover:not(:disabled) {
    background: #e2e8f0;
}
.btn-ghost:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-danger {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #dc2626;
    color: #fff;
    border: none;
    border-radius: 12px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s;
    box-shadow: 0 2px 8px rgba(220, 38, 38, 0.2);
}
.btn-danger:hover:not(:disabled) {
    background: #b91c1c;
    transform: translateY(-1px);
}
.btn-danger:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

/* MODAL */
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(10, 18, 30, 0.55) !important;
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 24px;
}
.modal-sheet {
    background: #ffffff !important;
    border-radius: 20px;
    width: 100%;
    max-width: 580px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.18);
    border: 1px solid #e4e8ef;
}
.modal-sheet--sm {
    max-width: 440px;
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
    background: #ffffff !important;
    z-index: 2;
    flex-shrink: 0;
}
.modal-sheet__header-left {
    display: flex;
    align-items: center;
    gap: 14px;
    flex: 1;
    min-width: 0;
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
    transition: background 0.15s;
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

.flag-panel {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 11px 14px;
    border: 1.5px solid #e4e8ef;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 600;
    color: #94a3b8;
}
.flag-panel--warn {
    border-color: #93c5fd;
    background: #eff6ff;
    color: #1d4ed8;
}

/* FIELDS */
.field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.field__label {
    display: flex;
    justify-content: space-between;
    align-items: center;
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
    transition:
        border-color 0.15s,
        background 0.15s;
}
.field__input:focus {
    border-color: #ea580c;
    background: #fff;
}
.field__input--date {
    background: #ffffff;
    padding: 8px 12px;
    font-size: 13px;
}
.field__textarea {
    resize: vertical;
    min-height: 60px;
    line-height: 1.6;
}

.toggle-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.email-add-row {
    display: flex;
    gap: 8px;
}
.email-chip-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 4px;
}
.email-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border-radius: 20px;
    border: 1px solid #fed7aa;
    background: #fff7ed;
    color: #c2410c;
    padding: 5px 6px 5px 12px;
    font-size: 12px;
    font-weight: 700;
}
.email-chip__remove {
    background: none;
    border: none;
    color: #ea580c;
    cursor: pointer;
    font-size: 15px;
    line-height: 1;
    padding: 2px;
}
.email-chip__remove:hover {
    color: #c2410c;
}

/* MODAL ACTIONS */
.modal-actions {
    display: flex;
    gap: 10px;
    padding-top: 4px;
    flex-wrap: wrap;
}
.modal-actions .btn-ghost,
.modal-actions .btn-danger,
.modal-actions .btn-secondary,
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
    color: #dc2626;
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

/* TOAST */
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
    background: #dc2626;
}

/* TRANSITIONS */
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

.spin {
    animation: spin 0.65s linear infinite;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

/* RESPONSIVE */
@media (max-width: 1024px) {
    .stat-row--six {
        grid-template-columns: repeat(3, 1fr);
    }
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
@media (max-width: 768px) {
    .stat-row {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }
    .filter-groups {
        gap: 14px;
    }
    .filter-card__top {
        flex-direction: column;
        align-items: stretch;
    }
}
@media (max-width: 640px) {
    .page-root {
        padding: 16px;
    }
    .stat-card {
        padding: 14px;
    }
    .stat-card__value {
        font-size: 22px;
    }
    .data-table {
        min-width: 760px;
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
    color: #dc2626;
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
    background: #dc2626;
}
.ir-leaflet-label--household::before {
    border-top-color: #dc2626 !important;
}
.ir-leaflet-label--guard {
    background: #2563eb;
}
.ir-leaflet-label--guard::before {
    border-top-color: #2563eb !important;
}
</style>

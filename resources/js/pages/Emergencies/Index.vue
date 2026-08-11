<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import {
    CheckCircle2,
    MapPin as MapPinIcon,
    Siren,
    UserCheck,
    X,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

const stored = localStorage.getItem('user');
const user = stored ? JSON.parse(stored) : null;

onMounted(() => {
    const role = user?.role;
    const allowed =
        role === 'admin' ||
        role === 'client' ||
        role === 'estate_billing' ||
        user?.is_gate_guard;

    if (!allowed) router.visit('/dashboard');
});

const alerts = ref<any[]>([]);
const loading = ref(true);
const filter = ref<'all' | 'active' | 'resolved'>('all');
const search = ref('');
const selectedAlert = ref<any>(null);
const showDetail = ref(false);
const isProcessing = ref(false);
const flash = ref<{ msg: string; type: 'success' | 'error' } | null>(null);
const confirmDeleteId = ref<any>(null);
const confirmResolveAlert = ref<any>(null);

const pagination = ref({
    current_page: 1,
    last_page: 1,
    total: 0,
    from: 0,
    to: 0,
    links: [] as any[],
});

const authHeaders = () => ({
    Authorization: `Bearer ${localStorage.getItem('token')}`,
});

function showFlash(msg: string, type: 'success' | 'error' = 'success') {
    flash.value = { msg, type };
    setTimeout(() => (flash.value = null), 3500);
}

// Unit number, name, phone, email, alert_location_source, and is_estate are
// all snapshotted directly onto the EmergencyAlert row at creation time (not
// read live off the related User), so this stays accurate even after the
// household is removed from an estate (soft-deleted) or re-registers
// elsewhere. Always read these off the alert itself, never off `alert.user`.
// function shouldShowUnit(a: any) {
//     return a?.alert_location_source === 'registered_address' && a?.is_estate;
// }

function shouldShowUnit(a: any) {
    return a?.is_estate;
}

async function load(url: string | null = null) {
    loading.value = true;
    try {
        const endpoint =
            url || `${import.meta.env.VITE_APP_URL}/api/emergency-alerts`;
        const { data } = await axios.get(endpoint, {
            headers: authHeaders(),
            params: url
                ? undefined
                : {
                      search: search.value || undefined,
                      status: filter.value !== 'all' ? filter.value : undefined,
                  },
        });
        alerts.value = data.data;
        pagination.value = {
            current_page: data.current_page,
            last_page: data.last_page,
            total: data.total,
            from: data.from,
            to: data.to,
            links: data.links,
        };
    } catch (e) {
        console.error(e);
        showFlash('Failed to load alerts.', 'error');
    } finally {
        loading.value = false;
    }
}

let searchTimeout: any = null;
function handleSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => load(), 400);
}

function setFilter(v: any) {
    filter.value = v;
    load();
}

onMounted(() => load());

const isAdmin = computed(() => user?.role === 'admin');

const filteredAlerts = computed(() => {
    return alerts.value
        .filter((a) => {
            if (filter.value === 'active') return !a.is_resolved;
            if (filter.value === 'resolved') return a.is_resolved;
            return true;
        })
        .filter((a) => {
            if (!search.value) return true;
            const s = search.value.toLowerCase();
            return (
                a.name?.toLowerCase().includes(s) ||
                a.unit_number?.toString().toLowerCase().includes(s) ||
                a.channel?.name?.toLowerCase().includes(s) ||
                a.client?.user?.name?.toLowerCase().includes(s)
            );
        });
});

const stats = computed(() => ({
    total: alerts.value.length,
    active: alerts.value.filter((a) => !a.is_resolved).length,
    resolved: alerts.value.filter((a) => a.is_resolved).length,
    todayResolved: alerts.value.filter((a) => {
        if (!a.resolved_at) return false;
        return (
            new Date(a.resolved_at).toDateString() === new Date().toDateString()
        );
    }).length,
}));

const filterOptions = [
    { value: 'all', label: 'All' },
    { value: 'active', label: 'Active' },
    { value: 'resolved', label: 'Resolved' },
] as const;

function openDetail(alert: any) {
    selectedAlert.value = alert;
    showDetail.value = true;
}
function closeDetail() {
    showDetail.value = false;
    selectedAlert.value = null;
}

async function proceedResolve() {
    if (!confirmResolveAlert.value) return;
    isProcessing.value = true;
    try {
        await axios.patch(
            `${import.meta.env.VITE_APP_URL}/api/emergency-alerts/${confirmResolveAlert.value.id}/resolve`,
            {},
            { headers: authHeaders() },
        );
        showFlash('Alert marked as resolved');
        const resolvedId = confirmResolveAlert.value.id;
        await load();
        if (selectedAlert.value?.id === resolvedId) {
            selectedAlert.value = alerts.value.find((a) => a.id === resolvedId);
        }
    } catch (e) {
        console.error(e);
        showFlash('Failed to resolve alert.', 'error');
    } finally {
        isProcessing.value = false;
        confirmResolveAlert.value = null;
    }
}

async function proceedDelete() {
    if (!confirmDeleteId.value) return;
    isProcessing.value = true;
    try {
        await axios.delete(
            `${import.meta.env.VITE_APP_URL}/api/emergency-alerts/${confirmDeleteId.value}`,
            {
                headers: authHeaders(),
            },
        );
        showFlash('Alert deleted');
        if (selectedAlert.value?.id === confirmDeleteId.value) closeDetail();
        await load();
    } catch (e) {
        console.error(e);
        showFlash('Failed to delete alert.', 'error');
    } finally {
        isProcessing.value = false;
        confirmDeleteId.value = null;
    }
}

function fmtDateTime(ts: string | null | undefined) {
    if (!ts) return '—';
    return new Date(ts).toLocaleString('en-ZA', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function fmtUnit(u: any) {
    if (!u) return '';
    const s = String(u).trim();
    return /unit/i.test(s) ? s : `Unit ${s}`;
}

const isRegisteredAddressSource = computed(() =>
    shouldShowUnit(selectedAlert.value),
);

function timeAgo(ts: string) {
    if (!ts) return '—';
    const diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
    if (diff < 60) return 'Just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
}
function fmtDuration(seconds: number) {
    if (!seconds) return '—';
    if (seconds < 60) return `${seconds}s`;
    return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
}

// ══════════ Response timeline (mirrors Estate/Guard IncidentReports pattern) ══════════
const timelineSteps = computed(() => {
    const a = selectedAlert.value;
    if (!a) return [];
    const res = a.resolution;
    return [
        {
            key: 'triggered',
            label: 'Alert Triggered',
            time: a.created_at,
            icon: Siren,
            done: !!a.created_at,
        },
        {
            key: 'accepted',
            label: 'Responder Accepted',
            time: res?.accepted_at,
            icon: UserCheck,
            done: !!res?.accepted_at,
        },
        {
            key: 'arrived',
            label: 'Arrived On Site',
            time: res?.arrival_time,
            icon: MapPinIcon,
            done: !!res?.arrival_time,
        },
        {
            key: 'resolved',
            label: 'Resolved',
            time: a.is_resolved ? a.resolved_at : null,
            icon: CheckCircle2,
            done: !!a.is_resolved,
        },
    ];
});
</script>

<template>
    <Head title="Emergencies" />
    <AppLayout>
        <div class="page-root">
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Safety</div>
                    <h1 class="page-header__title">Emergency Alerts</h1>
                    <p class="page-header__sub">
                        Monitor and manage all panic alerts
                    </p>
                </div>
                <button class="btn-secondary" @click="load()">↻ Refresh</button>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card__value">{{ stats.total }}</div>
                    <div class="stat-card__label">Total</div>
                </div>
                <div class="stat-card stat-card--danger">
                    <div class="stat-card__value">{{ stats.active }}</div>
                    <div class="stat-card__label">Active</div>
                </div>
                <div class="stat-card stat-card--success">
                    <div class="stat-card__value">{{ stats.resolved }}</div>
                    <div class="stat-card__label">Resolved</div>
                </div>
                <div class="stat-card stat-card--info">
                    <div class="stat-card__value">
                        {{ stats.todayResolved }}
                    </div>
                    <div class="stat-card__label">Today</div>
                </div>
            </div>

            <div class="filter-card">
                <div class="filter-card__top">
                    <div class="search-input-row--standalone">
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
                            v-model="search"
                            @input="handleSearch"
                            type="text"
                            class="search-input"
                            placeholder="Search by name, unit…"
                        />
                        <span
                            v-if="search"
                            class="search-clear"
                            @click="
                                search = '';
                                load();
                            "
                            >×</span
                        >
                    </div>
                </div>

                <div class="filter-groups">
                    <div class="filter-group">
                        <span class="filter-group__label">Status</span>
                        <div class="filter-bar__chips">
                            <button
                                v-for="f in filterOptions"
                                :key="f.value"
                                class="chip"
                                :class="{ 'chip--active': filter === f.value }"
                                @click="setFilter(f.value)"
                            >
                                {{ f.label }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-card">
                <div v-if="loading" class="empty-state">
                    <span class="text-sm text-slate-400">Loading alerts…</span>
                </div>
                <div v-else-if="alerts.length === 0" class="empty-state">
                    <p class="empty-state__title">No alerts found</p>
                    <p class="empty-state__sub">
                        No emergency alerts match your current filter
                    </p>
                </div>
                <div v-else class="table-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Sender</th>
                                <th>Unit</th>
                                <th>Channel</th>
                                <th>Client</th>
                                <th>Responder</th>
                                <th>Time</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="alert in alerts"
                                :key="alert.id"
                                class="clickable-row"
                                @click="openDetail(alert)"
                            >
                                <td>
                                    <span
                                        class="type-badge"
                                        :class="
                                            alert.is_resolved
                                                ? 'bg-emerald-50 text-emerald-700'
                                                : 'badge--pulse bg-red-50 text-red-600'
                                        "
                                    >
                                        {{
                                            alert.is_resolved
                                                ? 'Resolved'
                                                : 'Active'
                                        }}
                                    </span>
                                </td>
                                <td>
                                    <div class="reporter-cell">
                                        <div>
                                            <div
                                                class="reporter-cell__name-row"
                                            >
                                                <span
                                                    class="reporter-cell__name"
                                                    >{{
                                                        alert.name ?? '—'
                                                    }}</span
                                                >
                                            </div>
                                            <div class="reporter-cell__sub">
                                                {{ alert.phone ?? '' }}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="td-time">
                                    <span
                                        v-if="
                                            shouldShowUnit(alert) &&
                                            alert.unit_number
                                        "
                                        class="td-time"
                                    >
                                        {{ fmtUnit(alert.unit_number) }}
                                    </span>
                                    <span v-else class="td-time text-slate-400"
                                        >—</span
                                    >
                                </td>

                                <td class="td-time">
                                    {{ alert.channel?.name ?? '—' }}
                                </td>
                                <td class="td-time">
                                    {{ alert.client?.user?.name ?? '—' }}
                                </td>
                                <td class="td-time">
                                    {{
                                        alert.resolution?.responder?.name ?? '—'
                                    }}
                                </td>
                                <td class="td-time">
                                    {{ fmtDateTime(alert.created_at) }}
                                </td>
                                <td v-if="isAdmin" @click.stop>
                                    <div class="row-actions">
                                        <button
                                            v-if="!alert.is_resolved"
                                            class="icon-btn icon-btn--success"
                                            title="Mark Resolved"
                                            @click="confirmResolveAlert = alert"
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
                                                    d="M5 13l4 4L19 7"
                                                />
                                            </svg>
                                        </button>
                                        <button
                                            class="icon-btn icon-btn--danger"
                                            title="Delete"
                                            @click="confirmDeleteId = alert.id"
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
                                                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                                />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                                <td v-else></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    class="pagination-bar"
                    v-if="!loading && alerts.length > 0"
                >
                    <span class="pagination-bar__info">
                        Showing {{ pagination.from }} to {{ pagination.to }} of
                        {{ pagination.total }}
                    </span>
                    <div class="pagination-bar__pages">
                        <template
                            v-for="(link, i) in pagination.links"
                            :key="i"
                        >
                            <button
                                v-if="link.url"
                                class="page-btn"
                                :class="{ 'page-btn--active': link.active }"
                                v-html="link.label"
                                @click="load(link.url)"
                            />
                            <span
                                v-else
                                class="page-btn page-btn--disabled"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- DETAIL MODAL -->
        <Teleport to="body">
            <transition name="modal">
                <div
                    v-if="showDetail"
                    class="modal-backdrop"
                    @click.self="closeDetail"
                >
                    <div class="modal-sheet">
                        <div class="modal-sheet__header">
                            <div>
                                <div class="modal-sheet__title">
                                    Alert #{{ selectedAlert?.id }}
                                </div>
                                <div class="modal-sheet__sub">
                                    {{ fmtDateTime(selectedAlert?.created_at) }}
                                </div>
                            </div>
                            <button class="close-btn" @click="closeDetail">
                                <X :size="16" />
                            </button>
                        </div>

                        <div class="modal-sheet__body">
                            <div class="toggle-row">
                                <span
                                    class="type-badge"
                                    :class="
                                        selectedAlert?.is_resolved
                                            ? 'bg-emerald-50 text-emerald-700'
                                            : 'bg-red-50 text-red-600'
                                    "
                                >
                                    {{
                                        selectedAlert?.is_resolved
                                            ? '✓ Resolved'
                                            : '🚨 Active'
                                    }}
                                </span>
                            </div>

                            <div class="toggle-row">
                                <div class="review-info-panel">
                                    <div class="field__label">Sender</div>
                                    <div class="review-info-panel__name">
                                        {{ selectedAlert?.name ?? '—' }}
                                    </div>
                                    <div
                                        v-if="
                                            isRegisteredAddressSource &&
                                            selectedAlert?.unit_number
                                        "
                                        class="ir-unit-badge ir-unit-badge--modal"
                                    >
                                        {{ fmtUnit(selectedAlert.unit_number) }}
                                    </div>
                                    <div class="review-info-panel__sub">
                                        {{ selectedAlert?.phone ?? '' }}
                                    </div>
                                    <div class="review-info-panel__sub">
                                        {{ selectedAlert?.email ?? '' }}
                                    </div>
                                    <div class="review-info-panel__sub">
                                        Channel:
                                        {{
                                            selectedAlert?.channel?.name ?? '—'
                                        }}
                                        · Client:
                                        {{
                                            selectedAlert?.client?.user?.name ??
                                            '—'
                                        }}
                                    </div>
                                </div>
                                <div class="review-info-panel">
                                    <div class="field__label">Location</div>
                                    <template
                                        v-if="
                                            selectedAlert?.latitude &&
                                            selectedAlert?.longitude
                                        "
                                    >
                                        <div
                                            class="review-info-panel__name"
                                            style="
                                                font-family: monospace;
                                                font-size: 12px;
                                            "
                                        >
                                            {{ selectedAlert.latitude }},
                                            {{ selectedAlert.longitude }}
                                        </div>

                                        <a
                                            :href="`https://maps.google.com/?q=${selectedAlert.latitude},${selectedAlert.longitude}`"
                                            target="_blank"
                                            class="map-link"
                                        >
                                            📍 Open in Google Maps
                                        </a>
                                    </template>
                                    <p v-else class="review-info-panel__sub">
                                        No location data
                                    </p>
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
                                                    i < timelineSteps.length - 1
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
                                                        ? fmtDateTime(step.time)
                                                        : 'Pending'
                                                }}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    v-if="selectedAlert?.resolution"
                                    class="detail-grid detail-grid--pad"
                                >
                                    <div>
                                        <div class="field__label">
                                            Responder
                                        </div>
                                        <div class="detail-grid__value">
                                            {{
                                                selectedAlert.resolution
                                                    .responder?.name ?? '—'
                                            }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="field__label">
                                            Response Time
                                        </div>
                                        <div class="detail-grid__value">
                                            {{
                                                fmtDuration(
                                                    selectedAlert.resolution
                                                        .response_duration,
                                                )
                                            }}
                                        </div>
                                    </div>
                                    <div
                                        v-if="
                                            selectedAlert.resolution
                                                .distance_traveled
                                        "
                                    >
                                        <div class="field__label">Distance</div>
                                        <div class="detail-grid__value">
                                            {{
                                                selectedAlert.resolution
                                                    .distance_traveled
                                            }}m
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div
                                v-if="selectedAlert?.resolution?.notes"
                                class="field"
                            >
                                <label class="field__label">Notes</label>
                                <p class="review-description">
                                    {{ selectedAlert.resolution.notes }}
                                </p>
                            </div>

                            <div v-if="isAdmin" class="modal-actions">
                                <button
                                    v-if="!selectedAlert?.is_resolved"
                                    class="btn-primary btn-primary--success"
                                    @click="confirmResolveAlert = selectedAlert"
                                >
                                    ✓ Mark as Resolved
                                </button>
                                <button
                                    class="btn-secondary btn-secondary--danger"
                                    @click="confirmDeleteId = selectedAlert?.id"
                                >
                                    Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>

        <!-- RESOLVE CONFIRM -->
        <Teleport to="body">
            <transition name="modal">
                <div
                    v-if="confirmResolveAlert"
                    class="modal-backdrop"
                    @click.self="confirmResolveAlert = null"
                >
                    <div class="modal-sheet modal-sheet--sm">
                        <div class="confirm-body">
                            <div class="confirm-icon confirm-icon--success">
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-5 w-5"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    stroke-width="2"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M5 13l4 4L19 7"
                                    />
                                </svg>
                            </div>
                            <div>
                                <h3 class="confirm-title">Mark as Resolved</h3>
                                <p class="confirm-sub">
                                    Alert #{{ confirmResolveAlert.id }} ·
                                    {{ confirmResolveAlert.name }}
                                </p>
                            </div>
                        </div>
                        <div class="confirm-note confirm-note--success">
                            This will close the alert and notify all connected
                            patrollers that the emergency has been handled.
                        </div>
                        <div class="confirm-actions">
                            <button
                                class="btn-secondary"
                                @click="confirmResolveAlert = null"
                            >
                                Cancel
                            </button>
                            <button
                                class="btn-primary btn-primary--success"
                                :disabled="isProcessing"
                                @click="proceedResolve"
                            >
                                {{
                                    isProcessing ? 'Resolving…' : 'Yes, Resolve'
                                }}
                            </button>
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>

        <!-- DELETE CONFIRM -->
        <Teleport to="body">
            <transition name="modal">
                <div
                    v-if="confirmDeleteId"
                    class="modal-backdrop"
                    @click.self="confirmDeleteId = null"
                >
                    <div class="modal-sheet modal-sheet--sm">
                        <div class="confirm-body">
                            <div class="confirm-icon confirm-icon--danger">
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-5 w-5"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    stroke-width="2"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                    />
                                </svg>
                            </div>
                            <div>
                                <h3 class="confirm-title">Delete Alert</h3>
                                <p class="confirm-sub">
                                    This action cannot be undone
                                </p>
                            </div>
                        </div>
                        <div class="confirm-note confirm-note--danger">
                            The alert and all associated resolution data will be
                            permanently removed.
                        </div>
                        <div class="confirm-actions">
                            <button
                                class="btn-secondary"
                                @click="confirmDeleteId = null"
                            >
                                Cancel
                            </button>
                            <button
                                class="btn-primary btn-primary--danger"
                                :disabled="isProcessing"
                                @click="proceedDelete"
                            >
                                {{ isProcessing ? 'Deleting…' : 'Yes, Delete' }}
                            </button>
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>

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
.btn-secondary--danger:hover:not(:disabled) {
    border-color: #dc2626;
    color: #dc2626;
    background: #fef2f2;
}

.btn-primary {
    flex: 1;
    border: none;
    border-radius: 12px;
    padding: 12px 18px;
    font-size: 13px;
    font-weight: 700;
    color: #fff;
    cursor: pointer;
    font-family: inherit;
}
.btn-primary--success {
    background: #059669;
}
.btn-primary--success:hover:not(:disabled) {
    background: #047857;
}
.btn-primary--danger {
    background: #dc2626;
    flex: none;
}
.btn-primary--danger:hover:not(:disabled) {
    background: #b91c1c;
}
.btn-primary:disabled {
    opacity: 0.5;
    cursor: default;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
}
.stat-card {
    background: #fff;
    border: 1px solid #e4e8ef;
    border-radius: 16px;
    box-shadow: var(--shadow-sm);
    padding: 16px 18px;
}
.stat-card__value {
    font-size: 24px;
    font-weight: 800;
    color: #1a2332;
}
.stat-card__label {
    font-size: 11px;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    margin-top: 2px;
}
.stat-card--danger .stat-card__value {
    color: #dc2626;
}
.stat-card--success .stat-card__value {
    color: #059669;
}
.stat-card--info .stat-card__value {
    color: #2563eb;
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
.badge--pulse {
    animation: pulse 1.8s ease-in-out infinite;
}
@keyframes pulse {
    0%,
    100% {
        opacity: 1;
    }
    50% {
        opacity: 0.55;
    }
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

.row-actions {
    display: flex;
    gap: 4px;
}
.icon-btn {
    padding: 7px;
    border-radius: 8px;
    border: none;
    background: transparent;
    cursor: pointer;
    display: inline-flex;
}
.icon-btn--success {
    color: #059669;
}
.icon-btn--success:hover {
    background: #ecfdf5;
}
.icon-btn--danger {
    color: #dc2626;
}
.icon-btn--danger:hover {
    background: #fef2f2;
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
    max-width: 620px;
    max-height: 88vh;
    overflow-y: auto;
    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.18);
    border: 1px solid #e4e8ef;
}
.modal-sheet--sm {
    max-width: 420px;
    padding: 24px;
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
.modal-sheet__body {
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.toggle-row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}
.toggle-row .review-info-panel {
    flex: 1;
    min-width: 200px;
}

.review-info-panel {
    background: #f8fafc;
    border: 1.5px solid #e4e8ef;
    border-radius: 10px;
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 3px;
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
    font-style: italic;
}

.map-link {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-top: 6px;
    font-size: 12px;
    font-weight: 700;
    color: #2563eb;
    text-decoration: none;
}
.map-link:hover {
    text-decoration: underline;
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

.detail-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
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

.modal-actions {
    display: flex;
    gap: 12px;
    padding-top: 4px;
}

.confirm-body {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 16px;
}
.confirm-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.confirm-icon--success {
    background: #ecfdf5;
    color: #059669;
}
.confirm-icon--danger {
    background: #fef2f2;
    color: #dc2626;
}
.confirm-title {
    font-size: 15px;
    font-weight: 700;
    color: #1a2332;
    margin: 0;
}
.confirm-sub {
    font-size: 12px;
    color: #94a3b8;
    margin: 2px 0 0;
}
.confirm-note {
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 13px;
    margin-bottom: 18px;
}
.confirm-note--success {
    background: #ecfdf5;
    border: 1px solid #a7f3d0;
    color: #047857;
}
.confirm-note--danger {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #b91c1c;
}
.confirm-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
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
    background: #dc2626;
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

@media (max-width: 640px) {
    .page-root {
        padding: 16px;
    }
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .data-table {
        min-width: 700px;
    }
    .table-card {
        overflow-x: auto;
    }
    .search-input-row--standalone {
        max-width: none;
    }
}

.reporter-cell__name-row {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

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
    margin: 2px 0;
    padding: 5px 14px;
    font-size: 16px;
}
</style>

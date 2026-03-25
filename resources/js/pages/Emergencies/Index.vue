<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';

const alerts = ref<any[]>([]);
const loading = ref(true);
const filter = ref<'all' | 'active' | 'resolved'>('all');
const search = ref('');
const selectedAlert = ref<any>(null);
const showPanel = ref(false);
const isProcessing = ref(false);
const flashMsg = ref('');
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

async function load(url?: string) {
    loading.value = true;
    try {
        const endpoint =
            url || `${import.meta.env.VITE_APP_URL}/api/emergency-alerts`;
        const { data } = await axios.get(endpoint, { headers: authHeaders() });
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
    } finally {
        loading.value = false;
    }
}

onMounted(() => load());

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
                a.user?.name?.toLowerCase().includes(s) ||
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

function openPanel(alert: any) {
    selectedAlert.value = alert;
    showPanel.value = true;
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
        flash('Alert marked as resolved');
        await load();
        if (selectedAlert.value?.id === confirmResolveAlert.value.id) {
            selectedAlert.value = alerts.value.find(
                (a) => a.id === confirmResolveAlert.value.id,
            );
        }
    } catch (e) {
        console.error(e);
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
            { headers: authHeaders() },
        );
        flash('Alert deleted');
        if (selectedAlert.value?.id === confirmDeleteId.value)
            showPanel.value = false;
        await load();
    } catch (e) {
        console.error(e);
    } finally {
        isProcessing.value = false;
        confirmDeleteId.value = null;
    }
}

function flash(msg: string) {
    flashMsg.value = msg;
    setTimeout(() => (flashMsg.value = ''), 3500);
}

function timeAgo(ts: string) {
    if (!ts) return '—';
    const diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
    if (diff < 60) return 'Just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
}

function formatDate(ts: string) {
    if (!ts) return '—';
    return new Date(ts).toLocaleString('en-ZA', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatDuration(seconds: number) {
    if (!seconds) return '—';
    if (seconds < 60) return `${seconds}s`;
    return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
}
</script>

<template>
    <Head title="Emergencies" />
    <AppLayout>
        <div class="flex h-full flex-col">
            <!-- Header -->
            <div class="border-b border-gray-100 bg-white px-6 py-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-lg font-bold text-gray-900">
                            Emergency Alerts
                        </h1>
                        <p class="text-sm text-gray-500">
                            Monitor and manage all panic alerts
                        </p>
                    </div>
                    <button
                        @click="load()"
                        class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"
                    >
                        ↻ Refresh
                    </button>
                </div>

                <!-- Stats -->
                <div class="mt-4 grid grid-cols-4 gap-4">
                    <div class="rounded-xl bg-gray-50 p-4">
                        <div class="text-2xl font-bold text-gray-900">
                            {{ stats.total }}
                        </div>
                        <div
                            class="text-xs font-semibold text-gray-400 uppercase"
                        >
                            Total
                        </div>
                    </div>
                    <div class="rounded-xl bg-red-50 p-4">
                        <div class="text-2xl font-bold text-red-600">
                            {{ stats.active }}
                        </div>
                        <div
                            class="text-xs font-semibold text-red-400 uppercase"
                        >
                            Active
                        </div>
                    </div>
                    <div class="rounded-xl bg-green-50 p-4">
                        <div class="text-2xl font-bold text-green-600">
                            {{ stats.resolved }}
                        </div>
                        <div
                            class="text-xs font-semibold text-green-400 uppercase"
                        >
                            Resolved
                        </div>
                    </div>
                    <div class="rounded-xl bg-blue-50 p-4">
                        <div class="text-2xl font-bold text-blue-600">
                            {{ stats.todayResolved }}
                        </div>
                        <div
                            class="text-xs font-semibold text-blue-400 uppercase"
                        >
                            Today
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div
                class="flex items-center gap-3 border-b border-gray-100 bg-white px-6 py-3"
            >
                <div class="flex gap-2">
                    <button
                        v-for="f in ['all', 'active', 'resolved']"
                        :key="f"
                        @click="filter = f as any"
                        :class="[
                            'rounded-full px-4 py-1.5 text-xs font-bold uppercase transition-all',
                            filter === f
                                ? 'bg-gray-900 text-white'
                                : 'bg-gray-100 text-gray-500 hover:bg-gray-200',
                        ]"
                    >
                        {{ f }}
                    </button>
                </div>
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search by name, channel, client..."
                    class="ml-auto w-64 rounded-lg border border-gray-200 px-3 py-1.5 text-sm outline-none focus:border-blue-400"
                />
            </div>

            <!-- Main content -->
            <div class="flex flex-1 overflow-hidden">
                <!-- Table -->
                <div
                    :class="[
                        'flex flex-col overflow-auto transition-all',
                        showPanel ? 'w-1/2' : 'w-full',
                    ]"
                >
                    <div
                        v-if="loading"
                        class="flex items-center justify-center gap-2 py-20 text-gray-400"
                    >
                        <svg
                            class="h-5 w-5 animate-spin"
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
                        <span class="text-sm">Loading alerts...</span>
                    </div>

                    <div
                        v-else-if="filteredAlerts.length === 0"
                        class="flex flex-col items-center justify-center py-20 text-center"
                    >
                        <span class="text-5xl">🚨</span>
                        <p class="mt-3 font-bold text-gray-900">
                            No alerts found
                        </p>
                        <p class="text-sm text-gray-500">
                            No emergency alerts match your current filter
                        </p>
                    </div>

                    <table v-else class="w-full table-auto text-left">
                        <thead>
                            <tr class="bg-gray-50">
                                <th
                                    class="border-y border-gray-100 p-4 text-xs font-semibold text-gray-500 uppercase"
                                >
                                    Status
                                </th>
                                <th
                                    class="border-y border-gray-100 p-4 text-xs font-semibold text-gray-500 uppercase"
                                >
                                    Sender
                                </th>
                                <th
                                    class="border-y border-gray-100 p-4 text-xs font-semibold text-gray-500 uppercase"
                                >
                                    Channel
                                </th>
                                <th
                                    class="border-y border-gray-100 p-4 text-xs font-semibold text-gray-500 uppercase"
                                >
                                    Client
                                </th>
                                <th
                                    class="border-y border-gray-100 p-4 text-xs font-semibold text-gray-500 uppercase"
                                >
                                    Responder
                                </th>
                                <th
                                    class="border-y border-gray-100 p-4 text-xs font-semibold text-gray-500 uppercase"
                                >
                                    Time
                                </th>
                                <th
                                    class="border-y border-gray-100 p-2 text-xs font-semibold text-gray-500 uppercase"
                                >
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="alert in filteredAlerts"
                                :key="alert.id"
                                @click="openPanel(alert)"
                                :class="[
                                    'cursor-pointer border-b border-gray-50 transition-colors hover:bg-gray-50/50',
                                    selectedAlert?.id === alert.id
                                        ? 'bg-blue-50/50'
                                        : '',
                                ]"
                            >
                                <td class="p-4">
                                    <span
                                        :class="[
                                            'rounded-full px-2.5 py-1 text-xs font-bold uppercase',
                                            alert.is_resolved
                                                ? 'border border-green-200 bg-green-50 text-green-700'
                                                : 'animate-pulse border border-red-200 bg-red-50 text-red-700',
                                        ]"
                                    >
                                        {{
                                            alert.is_resolved
                                                ? 'Resolved'
                                                : 'Active'
                                        }}
                                    </span>
                                </td>
                                <td class="p-4">
                                    <div
                                        class="text-sm font-semibold text-gray-900"
                                    >
                                        {{ alert.user?.name ?? '—' }}
                                    </div>
                                </td>
                                <td class="p-4 text-sm text-gray-600">
                                    {{ alert.channel?.name ?? '—' }}
                                </td>
                                <td class="p-4 text-sm text-gray-600">
                                    {{ alert.client?.user?.name ?? '—' }}
                                </td>
                                <td class="p-4 text-sm text-gray-600">
                                    {{
                                        alert.resolution?.responder?.name ?? '—'
                                    }}
                                </td>
                                <td
                                    class="p-4 text-sm whitespace-nowrap text-gray-400"
                                >
                                    {{ timeAgo(alert.created_at) }}
                                </td>
                                <td class="p-2" @click.stop>
                                    <div class="flex items-center gap-1">
                                        <button
                                            v-if="!alert.is_resolved"
                                            @click="confirmResolveAlert = alert"
                                            class="rounded-lg p-2 text-green-600 hover:bg-green-50"
                                            title="Mark Resolved"
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
                                            @click="confirmDeleteId = alert.id"
                                            class="rounded-lg p-2 text-red-600 hover:bg-red-50"
                                            title="Delete"
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
                            </tr>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <div
                        class="flex items-center justify-between border-t border-gray-100 p-4"
                    >
                        <span class="text-sm text-gray-500"
                            >Showing {{ pagination.from }} to
                            {{ pagination.to }} of {{ pagination.total }}</span
                        >
                        <div class="flex gap-1">
                            <template
                                v-for="(link, i) in pagination.links"
                                :key="i"
                            >
                                <button
                                    v-if="link.url"
                                    @click="load(link.url)"
                                    v-html="link.label"
                                    :class="[
                                        'min-w-[36px] rounded border px-2 py-1 text-xs transition-colors',
                                        link.active
                                            ? 'border-blue-500 bg-blue-500 text-white'
                                            : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50',
                                    ]"
                                />
                                <span
                                    v-else
                                    v-html="link.label"
                                    class="min-w-[36px] cursor-not-allowed rounded border border-gray-200 bg-gray-100 px-2 py-1 text-center text-xs text-gray-400"
                                />
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Detail Panel -->
                <transition name="panel">
                    <div
                        v-if="showPanel && selectedAlert"
                        class="w-1/2 overflow-auto border-l border-gray-100 bg-white"
                    >
                        <div
                            class="sticky top-0 z-10 flex items-center justify-between border-b border-gray-100 bg-white px-6 py-4"
                        >
                            <h2 class="font-bold text-gray-900">
                                Alert #{{ selectedAlert.id }}
                            </h2>
                            <button
                                @click="showPanel = false"
                                class="rounded-lg p-2 text-gray-400 hover:bg-gray-100"
                            >
                                ✕
                            </button>
                        </div>

                        <div class="space-y-6 p-6">
                            <!-- Status badge -->
                            <div class="flex items-center gap-3">
                                <span
                                    :class="[
                                        'rounded-full px-3 py-1.5 text-sm font-bold',
                                        selectedAlert.is_resolved
                                            ? 'bg-green-100 text-green-700'
                                            : 'bg-red-100 text-red-700',
                                    ]"
                                >
                                    {{
                                        selectedAlert.is_resolved
                                            ? '✓ Resolved'
                                            : '🚨 Active'
                                    }}
                                </span>
                                <span class="text-sm text-gray-400">{{
                                    formatDate(selectedAlert.created_at)
                                }}</span>
                            </div>

                            <!-- Sender info -->
                            <div class="rounded-xl bg-gray-50 p-4">
                                <p
                                    class="mb-2 text-xs font-bold text-gray-400 uppercase"
                                >
                                    Sender
                                </p>
                                <p class="font-semibold text-gray-900">
                                    {{ selectedAlert.user?.name ?? '—' }}
                                </p>
                                <p class="text-sm text-gray-500">
                                    {{ selectedAlert.user?.phone ?? '' }}
                                </p>
                                <p class="mt-1 text-xs text-gray-400">
                                    Channel:
                                    {{ selectedAlert.channel?.name ?? '—' }} ·
                                    Client:
                                    {{
                                        selectedAlert.client?.user?.name ?? '—'
                                    }}
                                </p>
                            </div>

                            <!-- Location -->
                            <div class="rounded-xl bg-gray-50 p-4">
                                <p
                                    class="mb-2 text-xs font-bold text-gray-400 uppercase"
                                >
                                    Location
                                </p>
                                <div
                                    v-if="
                                        selectedAlert.latitude &&
                                        selectedAlert.longitude
                                    "
                                >
                                    <p class="font-mono text-sm text-gray-700">
                                        {{ selectedAlert.latitude }},
                                        {{ selectedAlert.longitude }}
                                    </p>
                                    <a
                                        :href="`https://maps.google.com/?q=${selectedAlert.latitude},${selectedAlert.longitude}`"
                                        target="_blank"
                                        class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:underline"
                                    >
                                        📍 Open in Google Maps
                                    </a>
                                </div>
                                <p v-else class="text-sm text-gray-400">
                                    No location data
                                </p>
                            </div>

                            <!-- Resolution / Responder -->
                            <div class="rounded-xl bg-gray-50 p-4">
                                <p
                                    class="mb-2 text-xs font-bold text-gray-400 uppercase"
                                >
                                    Response
                                </p>
                                <div v-if="selectedAlert.resolution">
                                    <p class="font-semibold text-gray-900">
                                        {{
                                            selectedAlert.resolution.responder
                                                ?.name ?? '—'
                                        }}
                                    </p>
                                    <div
                                        class="mt-2 space-y-1 text-sm text-gray-500"
                                    >
                                        <p>
                                            Status:
                                            <span
                                                class="font-medium text-gray-700 capitalize"
                                                >{{
                                                    selectedAlert.resolution
                                                        .status
                                                }}</span
                                            >
                                        </p>
                                        <p>
                                            Accepted:
                                            <span
                                                class="font-medium text-gray-700"
                                                >{{
                                                    formatDate(
                                                        selectedAlert.resolution
                                                            .accepted_at,
                                                    )
                                                }}</span
                                            >
                                        </p>
                                        <p
                                            v-if="
                                                selectedAlert.resolution
                                                    .resolution_time
                                            "
                                        >
                                            Resolved:
                                            <span
                                                class="font-medium text-gray-700"
                                                >{{
                                                    formatDate(
                                                        selectedAlert.resolution
                                                            .resolution_time,
                                                    )
                                                }}</span
                                            >
                                        </p>
                                        <p
                                            v-if="
                                                selectedAlert.resolution
                                                    .response_duration
                                            "
                                        >
                                            Response time:
                                            <span
                                                class="font-medium text-gray-700"
                                                >{{
                                                    formatDuration(
                                                        selectedAlert.resolution
                                                            .response_duration,
                                                    )
                                                }}</span
                                            >
                                        </p>
                                        <p
                                            v-if="
                                                selectedAlert.resolution
                                                    .distance_traveled
                                            "
                                        >
                                            Distance:
                                            <span
                                                class="font-medium text-gray-700"
                                                >{{
                                                    selectedAlert.resolution
                                                        .distance_traveled
                                                }}m</span
                                            >
                                        </p>
                                        <p
                                            v-if="
                                                selectedAlert.resolution.notes
                                            "
                                            class="mt-2 rounded-lg bg-white p-3 text-gray-600 italic"
                                        >
                                            "{{
                                                selectedAlert.resolution.notes
                                            }}"
                                        </p>
                                    </div>
                                </div>
                                <p v-else class="text-sm text-gray-400">
                                    No responder assigned yet
                                </p>
                            </div>

                            <!-- Timeline -->
                            <div>
                                <p
                                    class="mb-3 text-xs font-bold text-gray-400 uppercase"
                                >
                                    Timeline
                                </p>
                                <ol
                                    class="relative space-y-4 border-l border-gray-200 pl-4"
                                >
                                    <li>
                                        <div
                                            class="absolute -left-1.5 h-3 w-3 rounded-full bg-red-500"
                                        ></div>
                                        <p
                                            class="text-sm font-semibold text-gray-900"
                                        >
                                            Alert triggered
                                        </p>
                                        <p class="text-xs text-gray-400">
                                            {{
                                                formatDate(
                                                    selectedAlert.created_at,
                                                )
                                            }}
                                        </p>
                                    </li>
                                    <li
                                        v-if="
                                            selectedAlert.resolution
                                                ?.accepted_at
                                        "
                                    >
                                        <div
                                            class="absolute -left-1.5 h-3 w-3 rounded-full bg-yellow-400"
                                        ></div>
                                        <p
                                            class="text-sm font-semibold text-gray-900"
                                        >
                                            Responder accepted
                                        </p>
                                        <p class="text-xs text-gray-400">
                                            {{
                                                formatDate(
                                                    selectedAlert.resolution
                                                        .accepted_at,
                                                )
                                            }}
                                            ·
                                            {{
                                                selectedAlert.resolution
                                                    .responder?.name
                                            }}
                                        </p>
                                    </li>
                                    <li
                                        v-if="
                                            selectedAlert.resolution
                                                ?.arrival_time
                                        "
                                    >
                                        <div
                                            class="absolute -left-1.5 h-3 w-3 rounded-full bg-blue-400"
                                        ></div>
                                        <p
                                            class="text-sm font-semibold text-gray-900"
                                        >
                                            Arrived on site
                                        </p>
                                        <p class="text-xs text-gray-400">
                                            {{
                                                formatDate(
                                                    selectedAlert.resolution
                                                        .arrival_time,
                                                )
                                            }}
                                        </p>
                                    </li>
                                    <li v-if="selectedAlert.is_resolved">
                                        <div
                                            class="absolute -left-1.5 h-3 w-3 rounded-full bg-green-500"
                                        ></div>
                                        <p
                                            class="text-sm font-semibold text-gray-900"
                                        >
                                            Resolved
                                        </p>
                                        <p class="text-xs text-gray-400">
                                            {{
                                                formatDate(
                                                    selectedAlert.resolved_at,
                                                )
                                            }}
                                            · {{ selectedAlert.resolver?.name }}
                                        </p>
                                    </li>
                                </ol>
                            </div>

                            <!-- Actions -->
                            <div class="flex gap-3 pt-2">
                                <button
                                    v-if="!selectedAlert.is_resolved"
                                    @click="confirmResolveAlert = selectedAlert"
                                    class="flex-1 rounded-xl bg-green-600 py-3 text-sm font-bold text-white hover:bg-green-700"
                                >
                                    ✓ Mark as Resolved
                                </button>
                                <button
                                    @click="confirmDeleteId = selectedAlert.id"
                                    class="rounded-xl border border-red-200 px-4 py-3 text-sm font-bold text-red-600 hover:bg-red-50"
                                >
                                    Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </transition>
            </div>
        </div>

        <!-- Resolve confirmation modal -->
        <div
            v-if="confirmResolveAlert"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
        >
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <div class="mb-4 flex items-center gap-3">
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full bg-green-100"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-5 w-5 text-green-600"
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
                        <h3 class="font-semibold text-gray-900">
                            Mark as Resolved
                        </h3>
                        <p class="text-sm text-gray-500">
                            Alert #{{ confirmResolveAlert.id }} ·
                            {{ confirmResolveAlert.user?.name }}
                        </p>
                    </div>
                </div>
                <div
                    class="mb-5 rounded-lg border border-green-100 bg-green-50 p-4 text-sm text-green-800"
                >
                    This will close the alert and notify all connected
                    patrollers that the emergency has been handled.
                </div>
                <div class="flex justify-end gap-3">
                    <button
                        @click="confirmResolveAlert = null"
                        class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        Cancel
                    </button>
                    <button
                        @click="proceedResolve"
                        :disabled="isProcessing"
                        class="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
                    >
                        {{ isProcessing ? 'Resolving...' : 'Yes, Resolve' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Delete confirmation modal -->
        <div
            v-if="confirmDeleteId"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
        >
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <div class="mb-4 flex items-center gap-3">
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full bg-red-100"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-5 w-5 text-red-600"
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
                        <h3 class="font-semibold text-gray-900">
                            Delete Alert
                        </h3>
                        <p class="text-sm text-gray-500">
                            This action cannot be undone
                        </p>
                    </div>
                </div>
                <div
                    class="mb-5 rounded-lg border border-red-100 bg-red-50 p-4 text-sm text-red-800"
                >
                    The alert and all associated resolution data will be
                    permanently removed.
                </div>
                <div class="flex justify-end gap-3">
                    <button
                        @click="confirmDeleteId = null"
                        class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        Cancel
                    </button>
                    <button
                        @click="proceedDelete"
                        :disabled="isProcessing"
                        class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
                    >
                        {{ isProcessing ? 'Deleting...' : 'Yes, Delete' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Flash toast -->
        <transition name="flash">
            <div
                v-if="flashMsg"
                class="fixed right-8 bottom-8 z-50 rounded-xl border-l-4 border-green-400 bg-gray-900 px-5 py-3 text-sm font-semibold text-white shadow-xl"
            >
                ✓ {{ flashMsg }}
            </div>
        </transition>
    </AppLayout>
</template>

<style scoped>
.panel-enter-active,
.panel-leave-active {
    transition: all 0.25s ease;
}
.panel-enter-from,
.panel-leave-to {
    opacity: 0;
    transform: translateX(20px);
}
.flash-enter-active,
.flash-leave-active {
    transition: all 0.3s ease;
}
.flash-enter-from,
.flash-leave-to {
    opacity: 0;
    transform: translateY(8px);
}
</style>

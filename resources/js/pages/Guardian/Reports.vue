<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Guardian Reports', href: '/guardian-reports' },
];

// ── State ─────────────────────────────────────────────────────
const reports = ref<any[]>([]);
const loading = ref(false);
const reviewingId = ref<number | null>(null);
const reviewNotes = ref('');
const showReviewModal = ref(false);
const selectedReport = ref<any | null>(null);

// ── Filters ───────────────────────────────────────────────────
const filterStatus = ref<
    'all' | 'pending' | 'reviewed' | 'escalated' | 'flagged'
>('all');
const filterSeverity = ref<'all' | 'low' | 'medium' | 'high'>('all');
const filterAlertType = ref<'all' | 'dv' | 'sos'>('all');
const search = ref('');
const currentPage = ref(1);
const lastPage = ref(1);
const total = ref(0);

const token = computed(() => localStorage.getItem('token') ?? '');

// ── Stats ─────────────────────────────────────────────────────
const stats = computed(() => ({
    total: reports.value.length,
    pending: reports.value.filter((r) => r.review_status === 'pending').length,
    escalated: reports.value.filter((r) => r.review_status === 'escalated')
        .length,
    high: reports.value.filter((r) => r.severity === 'high').length,
}));

// ── Filtered reports ──────────────────────────────────────────
const filtered = computed(() => {
    let list = [...reports.value];
    if (filterStatus.value !== 'all')
        list = list.filter((r) => r.review_status === filterStatus.value);
    if (filterSeverity.value !== 'all')
        list = list.filter((r) => r.severity === filterSeverity.value);
    if (filterAlertType.value !== 'all')
        list = list.filter((r) => r.alert_type === filterAlertType.value);
    if (search.value.trim()) {
        const q = search.value.toLowerCase();
        list = list.filter(
            (r) =>
                String(r.alert_id).includes(q) ||
                r.reporting_user?.name?.toLowerCase().includes(q) ||
                r.description?.toLowerCase().includes(q),
        );
    }
    return list;
});

// ── API ───────────────────────────────────────────────────────
async function loadReports() {
    loading.value = true;
    try {
        const params: Record<string, any> = { page: currentPage.value };
        if (filterStatus.value !== 'all')
            params.review_status = filterStatus.value;
        if (filterSeverity.value !== 'all')
            params.severity = filterSeverity.value;
        if (filterAlertType.value !== 'all')
            params.alert_type = filterAlertType.value;

        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/guardian-reports`,
            {
                params,
                headers: { Authorization: `Bearer ${token.value}` },
            },
        );
        reports.value = data.data ?? data ?? [];
        lastPage.value = data.last_page ?? 1;
        total.value = data.total ?? reports.value.length;
    } catch {
        reports.value = [];
    } finally {
        loading.value = false;
    }
}

function openReview(report: any) {
    selectedReport.value = report;
    reviewNotes.value = report.review_notes ?? '';
    showReviewModal.value = true;
}

function closeModal() {
    showReviewModal.value = false;
    selectedReport.value = null;
    reviewNotes.value = '';
}

async function submitReview(action: 'review' | 'escalate') {
    if (!selectedReport.value) return;
    reviewingId.value = selectedReport.value.id;
    try {
        await axios.put(
            `${import.meta.env.VITE_APP_URL}/api/guardian-reports/${selectedReport.value.id}/${action}`,
            { review_notes: reviewNotes.value },
            { headers: { Authorization: `Bearer ${token.value}` } },
        );
        closeModal();
        await loadReports();
    } finally {
        reviewingId.value = null;
    }
}

function viewDetail(id: number) {
    router.visit(`/guardian-reports/${id}`);
}

// ── Helpers ───────────────────────────────────────────────────
function timeAgo(ts: string) {
    const diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
    if (diff < 60) return 'Just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
}

function formatDate(ts: string) {
    return new Date(ts).toLocaleString('en-ZA', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

const severityClass = (s: string) =>
    ({
        high: 'bg-red-50 text-red-700 border border-red-200',
        medium: 'bg-yellow-50 text-yellow-700 border border-yellow-200',
        low: 'bg-green-50 text-green-700 border border-green-200',
    })[s] ?? 'bg-gray-100 text-gray-600';

const statusClass = (s: string) =>
    ({
        pending: 'bg-orange-50 text-orange-700 border border-orange-200',
        reviewed: 'bg-blue-50 text-blue-700 border border-blue-200',
        escalated: 'bg-red-50 text-red-700 border border-red-200',
        flagged: 'bg-purple-50 text-purple-700 border border-purple-200',
    })[s] ?? 'bg-gray-100 text-gray-600';

const alertTypeClass = (t: string) =>
    t === 'dv'
        ? 'bg-pink-50 text-pink-700 border border-pink-200'
        : 'bg-indigo-50 text-indigo-700 border border-indigo-200';

onMounted(loadReports);
</script>

<template>
    <Head title="Guardian Reports" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-6">
            <!-- ── Stats row ──────────────────────────────────── -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div
                    class="rounded-xl border border-gray-100 bg-white p-5 shadow"
                >
                    <p class="text-sm font-medium text-gray-500">
                        Total Reports
                    </p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">
                        {{ total }}
                    </p>
                </div>
                <div
                    class="rounded-xl border border-orange-100 bg-orange-50 p-5 shadow"
                >
                    <p class="text-sm font-medium text-orange-600">
                        Pending Review
                    </p>
                    <p class="mt-1 text-2xl font-bold text-orange-700">
                        {{ stats.pending }}
                    </p>
                </div>
                <div
                    class="rounded-xl border border-red-100 bg-red-50 p-5 shadow"
                >
                    <p class="text-sm font-medium text-red-600">Escalated</p>
                    <p class="mt-1 text-2xl font-bold text-red-700">
                        {{ stats.escalated }}
                    </p>
                </div>
                <div
                    class="rounded-xl border border-gray-100 bg-white p-5 shadow"
                >
                    <p class="text-sm font-medium text-gray-500">
                        High Severity
                    </p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">
                        {{ stats.high }}
                    </p>
                </div>
            </div>

            <!-- ── Filters ────────────────────────────────────── -->
            <div class="rounded-xl border border-gray-100 bg-white p-5 shadow">
                <div class="flex flex-wrap items-center gap-3">
                    <!-- Search -->
                    <div class="relative min-w-[200px] flex-1">
                        <svg
                            class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-gray-400"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M21 21l-4.35-4.35M17 11A6 6 0 111 11a6 6 0 0116 0z"
                            />
                        </svg>
                        <input
                            v-model="search"
                            class="w-full rounded-lg border border-gray-200 py-2 pr-4 pl-9 text-sm focus:border-blue-400 focus:outline-none"
                            placeholder="Search by name, alert ID, description…"
                        />
                    </div>

                    <!-- Status filter -->
                    <select
                        v-model="filterStatus"
                        @change="loadReports"
                        class="rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none"
                    >
                        <option value="all">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="reviewed">Reviewed</option>
                        <option value="escalated">Escalated</option>
                        <option value="flagged">Flagged</option>
                    </select>

                    <!-- Severity filter -->
                    <select
                        v-model="filterSeverity"
                        @change="loadReports"
                        class="rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none"
                    >
                        <option value="all">All Severities</option>
                        <option value="high">High</option>
                        <option value="medium">Medium</option>
                        <option value="low">Low</option>
                    </select>

                    <!-- Alert type filter -->
                    <select
                        v-model="filterAlertType"
                        @change="loadReports"
                        class="rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none"
                    >
                        <option value="all">All Types</option>
                        <option value="dv">DV Alert</option>
                        <option value="sos">SOS Alert</option>
                    </select>

                    <button
                        @click="loadReports"
                        class="ml-auto rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700"
                    >
                        Refresh
                    </button>
                </div>
            </div>

            <!-- ── Table ──────────────────────────────────────── -->
            <div class="rounded-xl border border-gray-100 bg-white shadow">
                <!-- Loading -->
                <div
                    v-if="loading"
                    class="flex items-center justify-center py-20"
                >
                    <div
                        class="h-8 w-8 animate-spin rounded-full border-2 border-blue-600 border-t-transparent"
                    ></div>
                    <span class="ml-3 text-sm text-gray-500"
                        >Loading reports…</span
                    >
                </div>

                <!-- Empty -->
                <div
                    v-else-if="filtered.length === 0"
                    class="flex flex-col items-center justify-center py-20 text-center"
                >
                    <svg
                        class="mb-3 h-10 w-10 text-gray-300"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="1.5"
                            d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                        />
                    </svg>
                    <p class="text-sm font-medium text-gray-500">
                        No reports found
                    </p>
                    <p class="mt-1 text-xs text-gray-400">
                        Try adjusting your filters
                    </p>
                </div>

                <!-- Table -->
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 bg-gray-50">
                                <th
                                    class="px-5 py-3 text-left text-xs font-semibold tracking-wide text-gray-500 uppercase"
                                >
                                    Alert
                                </th>
                                <th
                                    class="px-5 py-3 text-left text-xs font-semibold tracking-wide text-gray-500 uppercase"
                                >
                                    Reporter
                                </th>
                                <th
                                    class="px-5 py-3 text-left text-xs font-semibold tracking-wide text-gray-500 uppercase"
                                >
                                    Description
                                </th>
                                <th
                                    class="px-5 py-3 text-left text-xs font-semibold tracking-wide text-gray-500 uppercase"
                                >
                                    Severity
                                </th>
                                <th
                                    class="px-5 py-3 text-left text-xs font-semibold tracking-wide text-gray-500 uppercase"
                                >
                                    Type
                                </th>
                                <th
                                    class="px-5 py-3 text-left text-xs font-semibold tracking-wide text-gray-500 uppercase"
                                >
                                    Status
                                </th>
                                <th
                                    class="px-5 py-3 text-left text-xs font-semibold tracking-wide text-gray-500 uppercase"
                                >
                                    Submitted
                                </th>
                                <th
                                    class="px-5 py-3 text-left text-xs font-semibold tracking-wide text-gray-500 uppercase"
                                >
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr
                                v-for="report in filtered"
                                :key="report.id"
                                class="group transition-colors hover:bg-gray-50"
                            >
                                <!-- Alert ID -->
                                <td class="px-5 py-4">
                                    <div class="flex flex-col gap-1">
                                        <span
                                            class="font-mono text-xs font-semibold text-gray-800"
                                            >#{{ report.alert_id }}</span
                                        >
                                        <div class="flex gap-1">
                                            <span
                                                v-if="report.seen_perpetrator"
                                                class="rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-semibold text-red-600"
                                                >Saw perp</span
                                            >
                                            <span
                                                v-if="report.heard_disturbance"
                                                class="rounded-full bg-yellow-50 px-2 py-0.5 text-[10px] font-semibold text-yellow-600"
                                                >Heard it</span
                                            >
                                        </div>
                                    </div>
                                </td>

                                <!-- Reporter -->
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-2">
                                        <div
                                            class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-indigo-100 text-xs font-bold text-indigo-600"
                                        >
                                            {{
                                                (
                                                    report.reporting_user
                                                        ?.name || 'U'
                                                )
                                                    .charAt(0)
                                                    .toUpperCase()
                                            }}
                                        </div>
                                        <div>
                                            <p
                                                class="font-medium text-gray-800"
                                            >
                                                {{
                                                    report.reporting_user
                                                        ?.name ?? '—'
                                                }}
                                            </p>
                                            <p class="text-xs text-gray-400">
                                                {{
                                                    report.reporting_user
                                                        ?.address_line_1 ?? ''
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                </td>

                                <!-- Description -->
                                <td class="max-w-xs px-5 py-4">
                                    <p class="line-clamp-2 text-gray-600">
                                        {{ report.description }}
                                    </p>
                                </td>

                                <!-- Severity -->
                                <td class="px-5 py-4">
                                    <span
                                        :class="[
                                            'rounded-full px-2.5 py-1 text-xs font-semibold capitalize',
                                            severityClass(report.severity),
                                        ]"
                                    >
                                        {{ report.severity }}
                                    </span>
                                </td>

                                <!-- Type -->
                                <td class="px-5 py-4">
                                    <span
                                        :class="[
                                            'rounded-full px-2.5 py-1 text-xs font-semibold uppercase',
                                            alertTypeClass(report.alert_type),
                                        ]"
                                    >
                                        {{ report.alert_type }}
                                    </span>
                                </td>

                                <!-- Status -->
                                <td class="px-5 py-4">
                                    <span
                                        :class="[
                                            'rounded-full px-2.5 py-1 text-xs font-semibold capitalize',
                                            statusClass(report.review_status),
                                        ]"
                                    >
                                        {{ report.review_status }}
                                    </span>
                                </td>

                                <!-- Submitted -->
                                <td class="px-5 py-4">
                                    <p class="text-gray-600">
                                        {{ formatDate(report.submitted_at) }}
                                    </p>
                                    <p class="text-xs text-gray-400">
                                        {{ timeAgo(report.submitted_at) }}
                                    </p>
                                </td>

                                <!-- Actions -->
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-2">
                                        <button
                                            @click="viewDetail(report.id)"
                                            class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 transition-colors hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700"
                                        >
                                            View
                                        </button>
                                        <button
                                            v-if="
                                                report.review_status ===
                                                'pending'
                                            "
                                            @click="openReview(report)"
                                            class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-blue-700"
                                        >
                                            Review
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div
                    v-if="!loading && lastPage > 1"
                    class="flex items-center justify-between border-t border-gray-100 px-5 py-3"
                >
                    <p class="text-xs text-gray-500">
                        Page {{ currentPage }} of {{ lastPage }}
                    </p>
                    <div class="flex gap-2">
                        <button
                            :disabled="currentPage === 1"
                            @click="
                                currentPage--;
                                loadReports();
                            "
                            class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 transition-colors hover:border-blue-300 hover:text-blue-600 disabled:opacity-40"
                        >
                            Previous
                        </button>
                        <button
                            :disabled="currentPage === lastPage"
                            @click="
                                currentPage++;
                                loadReports();
                            "
                            class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 transition-colors hover:border-blue-300 hover:text-blue-600 disabled:opacity-40"
                        >
                            Next
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Review Modal ──────────────────────────────────── -->
        <Teleport to="body">
            <Transition name="modal">
                <div
                    v-if="showReviewModal && selectedReport"
                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm"
                    @click.self="closeModal"
                >
                    <div
                        class="w-full max-w-lg rounded-2xl bg-white shadow-2xl"
                    >
                        <!-- Header -->
                        <div
                            class="flex items-center justify-between border-b border-gray-100 px-6 py-5"
                        >
                            <div>
                                <h2 class="text-base font-bold text-gray-900">
                                    Review Report
                                </h2>
                                <p class="mt-0.5 text-sm text-gray-500">
                                    Alert #{{ selectedReport.alert_id }}
                                </p>
                            </div>
                            <button
                                @click="closeModal"
                                class="rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600"
                            >
                                <svg
                                    class="h-5 w-5"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>

                        <!-- Body -->
                        <div class="space-y-4 px-6 py-5">
                            <!-- Reporter -->
                            <div class="rounded-lg bg-gray-50 p-4">
                                <p
                                    class="text-xs font-semibold tracking-wide text-gray-500 uppercase"
                                >
                                    Reporter
                                </p>
                                <p class="mt-1 font-medium text-gray-800">
                                    {{
                                        selectedReport.reporting_user?.name ??
                                        '—'
                                    }}
                                </p>
                                <p class="text-sm text-gray-500">
                                    {{
                                        selectedReport.reporting_user
                                            ?.address_line_1 ?? ''
                                    }}
                                </p>
                            </div>

                            <!-- Flags -->
                            <div class="flex gap-3">
                                <div
                                    class="flex flex-1 items-center gap-2 rounded-lg border p-3"
                                    :class="
                                        selectedReport.seen_perpetrator
                                            ? 'border-red-200 bg-red-50'
                                            : 'border-gray-100'
                                    "
                                >
                                    <svg
                                        class="h-4 w-4"
                                        :class="
                                            selectedReport.seen_perpetrator
                                                ? 'text-red-500'
                                                : 'text-gray-300'
                                        "
                                        fill="currentColor"
                                        viewBox="0 0 20 20"
                                    >
                                        <path
                                            d="M10 12a2 2 0 100-4 2 2 0 000 4z"
                                        />
                                        <path
                                            fill-rule="evenodd"
                                            d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                    <span
                                        class="text-xs font-medium"
                                        :class="
                                            selectedReport.seen_perpetrator
                                                ? 'text-red-700'
                                                : 'text-gray-400'
                                        "
                                        >Saw perpetrator</span
                                    >
                                </div>
                                <div
                                    class="flex flex-1 items-center gap-2 rounded-lg border p-3"
                                    :class="
                                        selectedReport.heard_disturbance
                                            ? 'border-yellow-200 bg-yellow-50'
                                            : 'border-gray-100'
                                    "
                                >
                                    <svg
                                        class="h-4 w-4"
                                        :class="
                                            selectedReport.heard_disturbance
                                                ? 'text-yellow-500'
                                                : 'text-gray-300'
                                        "
                                        fill="currentColor"
                                        viewBox="0 0 20 20"
                                    >
                                        <path
                                            fill-rule="evenodd"
                                            d="M9.383 3.076A1 1 0 0110 4v12a1 1 0 01-1.707.707L4.586 13H2a1 1 0 01-1-1V8a1 1 0 011-1h2.586l3.707-3.707a1 1 0 011.09-.217zM14.657 2.929a1 1 0 011.414 0A9.972 9.972 0 0119 10a9.972 9.972 0 01-2.929 7.071 1 1 0 01-1.414-1.414A7.971 7.971 0 0017 10c0-2.21-.894-4.208-2.343-5.657a1 1 0 010-1.414zm-2.829 2.828a1 1 0 011.415 0A5.983 5.983 0 0115 10a5.984 5.984 0 01-1.757 4.243 1 1 0 01-1.415-1.415A3.984 3.984 0 0013 10a3.983 3.983 0 00-1.172-2.828 1 1 0 010-1.415z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                    <span
                                        class="text-xs font-medium"
                                        :class="
                                            selectedReport.heard_disturbance
                                                ? 'text-yellow-700'
                                                : 'text-gray-400'
                                        "
                                        >Heard disturbance</span
                                    >
                                </div>
                            </div>

                            <!-- Description -->
                            <div>
                                <p
                                    class="mb-1.5 text-xs font-semibold tracking-wide text-gray-500 uppercase"
                                >
                                    Description
                                </p>
                                <p
                                    class="rounded-lg bg-gray-50 p-3 text-sm leading-relaxed text-gray-700"
                                >
                                    {{ selectedReport.description }}
                                </p>
                            </div>

                            <!-- Severity -->
                            <div class="flex items-center gap-3">
                                <p
                                    class="text-xs font-semibold tracking-wide text-gray-500 uppercase"
                                >
                                    Severity
                                </p>
                                <span
                                    :class="[
                                        'rounded-full px-2.5 py-1 text-xs font-semibold capitalize',
                                        severityClass(selectedReport.severity),
                                    ]"
                                >
                                    {{ selectedReport.severity }}
                                </span>
                            </div>

                            <!-- Review notes -->
                            <div>
                                <label
                                    class="mb-1.5 block text-xs font-semibold tracking-wide text-gray-500 uppercase"
                                >
                                    Review Notes
                                </label>
                                <textarea
                                    v-model="reviewNotes"
                                    rows="3"
                                    class="w-full resize-none rounded-lg border border-gray-200 p-3 text-sm focus:border-blue-400 focus:outline-none"
                                    placeholder="Add notes about this report…"
                                ></textarea>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div
                            class="flex items-center justify-end gap-3 border-t border-gray-100 px-6 py-4"
                        >
                            <button
                                @click="closeModal"
                                class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-50"
                            >
                                Cancel
                            </button>
                            <button
                                @click="submitReview('escalate')"
                                :disabled="reviewingId !== null"
                                class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-red-700 disabled:opacity-50"
                            >
                                Escalate to Incident
                            </button>
                            <button
                                @click="submitReview('review')"
                                :disabled="reviewingId !== null"
                                class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700 disabled:opacity-50"
                            >
                                <span v-if="reviewingId">Saving…</span>
                                <span v-else>Mark Reviewed</span>
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AppLayout>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.2s;
}
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
.modal-enter-active .rounded-2xl,
.modal-leave-active .rounded-2xl {
    transition:
        transform 0.2s ease,
        opacity 0.2s;
}
.modal-enter-from .rounded-2xl,
.modal-leave-to .rounded-2xl {
    transform: scale(0.96) translateY(10px);
    opacity: 0;
}
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>

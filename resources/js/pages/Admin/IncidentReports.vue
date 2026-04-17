<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';

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
const exportFormat = ref<'pdf' | 'csv' | 'both'>('pdf');
const exportLoading = ref(false);

const showEmail = ref(false);
const emailInput = ref('');
const emailList = ref<string[]>([]);
const emailFormats = ref<string[]>(['pdf']);
const emailLoading = ref(false);
const emailError = ref('');

const getHeaders = () => ({
    headers: { Authorization: `Bearer ${localStorage.getItem('token')}` },
});

function showFlash(msg: string, type: 'success' | 'error' = 'success') {
    flash.value = { msg, type };
    setTimeout(() => (flash.value = null), 4000);
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
    }
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
        selectedReport.value = data.report;
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

async function doExport(format: 'pdf' | 'csv') {
    if (!validateDates()) return;
    exportLoading.value = true;
    try {
        const response = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/admin/incident-reports/export/${format}?${buildExportParams()}`,
            {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                },
                responseType: 'blob',
            },
        );
        const blob = new Blob([response.data], {
            type: format === 'pdf' ? 'application/pdf' : 'text/csv',
        });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `incident-reports-${dateFrom.value}-to-${dateTo.value}.${format}`;
        a.click();
        URL.revokeObjectURL(url);
        showFlash(`${format.toUpperCase()} downloaded.`);
    } catch {
        showFlash('Export failed. Try again.', 'error');
    } finally {
        exportLoading.value = false;
    }
}

async function handleExport() {
    if (!validateDates()) return;
    if (exportFormat.value === 'both') {
        await doExport('pdf');
        await doExport('csv');
    } else {
        await doExport(exportFormat.value);
    }
    showExport.value = false;
}

function addEmail() {
    const e = emailInput.value.trim();
    if (!e) return;
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e)) {
        emailError.value = 'Invalid email.';
        return;
    }
    if (emailList.value.includes(e)) {
        emailError.value = 'Already added.';
        return;
    }
    if (emailList.value.length >= 10) {
        emailError.value = 'Max 10 recipients.';
        return;
    }
    emailList.value.push(e);
    emailInput.value = '';
    emailError.value = '';
}

function removeEmail(e: string) {
    emailList.value = emailList.value.filter((x) => x !== e);
}

function toggleEmailFormat(f: string) {
    if (emailFormats.value.includes(f)) {
        if (emailFormats.value.length === 1) return;
        emailFormats.value = emailFormats.value.filter((x) => x !== f);
    } else {
        emailFormats.value.push(f);
    }
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
                formats: emailFormats.value,
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
        emailFormats.value = ['pdf'];
    } catch (err: any) {
        showFlash(err.response?.data?.message ?? 'Failed to send.', 'error');
    } finally {
        emailLoading.value = false;
    }
}

function fmtDate(d: string) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-ZA', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}
function fmtDateTime(d: string) {
    if (!d) return '—';
    return new Date(d).toLocaleString('en-ZA', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const statusConfig: Record<string, { label: string; cls: string }> = {
    pending: {
        label: '⏳ Pending',
        cls: 'border-amber-200 bg-amber-50 text-amber-700',
    },
    reviewed: {
        label: '👁 Reviewed',
        cls: 'border-blue-200 bg-blue-50 text-blue-700',
    },
    warned: {
        label: '⚠ Warned',
        cls: 'border-orange-200 bg-orange-50 text-orange-700',
    },
    blocked: {
        label: '🚫 Blocked',
        cls: 'border-red-200 bg-red-50 text-red-700',
    },
    dismissed: {
        label: '✕ Dismissed',
        cls: 'border-gray-200 bg-gray-50 text-gray-500',
    },
};
const outcomeConfig: Record<string, { label: string; cls: string }> = {
    legitimate: {
        label: '✓ Legitimate',
        cls: 'border-green-200 bg-green-50 text-green-700',
    },
    misuse: {
        label: '⚠ Misuse',
        cls: 'border-red-200 bg-red-50 text-red-700',
    },
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
    <AppLayout>
        <div
            class="relative flex h-full w-full flex-col rounded-xl bg-white bg-clip-border text-gray-700 shadow-md"
        >
            <!-- HEADER -->
            <div class="border-b border-gray-100 px-6 py-5">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-lg font-bold text-gray-900">
                            Incident Reports
                        </h1>
                        <p class="mt-0.5 text-sm text-gray-500">
                            SOS alert reports submitted by patrollers
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            @click="showExport = true"
                            class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                        >
                            ⬇ Export
                        </button>
                        <button
                            @click="showEmail = true"
                            class="flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-100"
                        >
                            ✉ Send Report
                        </button>
                        <div
                            v-if="flash"
                            :class="[
                                'rounded-xl px-4 py-2 text-sm font-semibold shadow',
                                flash.type === 'success'
                                    ? 'bg-green-600 text-white'
                                    : 'bg-red-600 text-white',
                            ]"
                        >
                            {{ flash.type === 'success' ? '✓' : '⚠' }}
                            {{ flash.msg }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- DATE RANGE -->
            <div
                class="flex flex-wrap items-end gap-3 border-b border-gray-100 bg-gray-50 px-5 py-3"
            >
                <div>
                    <label
                        class="mb-1 block text-xs font-bold tracking-wide text-gray-500 uppercase"
                        >From</label
                    >
                    <input
                        v-model="dateFrom"
                        type="date"
                        :max="dateTo"
                        class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm focus:border-gray-400 focus:outline-none"
                    />
                </div>
                <div>
                    <label
                        class="mb-1 block text-xs font-bold tracking-wide text-gray-500 uppercase"
                        >To</label
                    >
                    <input
                        v-model="dateTo"
                        type="date"
                        :min="dateFrom"
                        :max="today"
                        class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm focus:border-gray-400 focus:outline-none"
                    />
                </div>
                <button
                    @click="loadReports()"
                    :disabled="loading"
                    class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-bold text-white hover:bg-gray-700 disabled:opacity-50"
                >
                    {{ loading ? 'Loading...' : 'Apply' }}
                </button>
                <p v-if="dateError" class="text-xs text-red-600">
                    {{ dateError }}
                </p>
            </div>

            <!-- STATS -->
            <div class="grid grid-cols-6 gap-3 border-b border-gray-100 p-5">
                <div class="rounded-xl bg-gray-900 p-4 text-center">
                    <div class="text-2xl font-bold text-white">
                        {{ reports.total ?? 0 }}
                    </div>
                    <div class="mt-1 text-xs text-gray-400">Total</div>
                </div>
                <div
                    class="rounded-xl border border-amber-100 bg-amber-50 p-4 text-center"
                >
                    <div class="text-2xl font-bold text-amber-700">
                        {{ stats.pending }}
                    </div>
                    <div class="mt-1 text-xs text-amber-600">Pending</div>
                </div>
                <div
                    class="rounded-xl border border-red-100 bg-red-50 p-4 text-center"
                >
                    <div class="text-2xl font-bold text-red-700">
                        {{ stats.misuse }}
                    </div>
                    <div class="mt-1 text-xs text-red-500">Misuse</div>
                </div>
                <div
                    class="rounded-xl border border-green-100 bg-green-50 p-4 text-center"
                >
                    <div class="text-2xl font-bold text-green-700">
                        {{ stats.legitimate }}
                    </div>
                    <div class="mt-1 text-xs text-green-600">Legitimate</div>
                </div>
                <div
                    class="rounded-xl border border-orange-100 bg-orange-50 p-4 text-center"
                >
                    <div class="text-2xl font-bold text-orange-700">
                        {{ stats.warned }}
                    </div>
                    <div class="mt-1 text-xs text-orange-600">Warned</div>
                </div>
                <div
                    class="rounded-xl border border-rose-100 bg-rose-50 p-4 text-center"
                >
                    <div class="text-2xl font-bold text-rose-700">
                        {{ stats.blocked }}
                    </div>
                    <div class="mt-1 text-xs text-rose-600">Blocked</div>
                </div>
            </div>

            <!-- FILTERS -->
            <div
                class="flex items-center gap-3 border-b border-gray-100 px-5 py-3"
            >
                <div class="relative max-w-sm flex-1">
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="pointer-events-none absolute top-2.5 left-3 h-4 w-4 text-gray-400"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M21 21l-4.35-4.35M17 11A6 6 0 105 11a6 6 0 0012 0z"
                        />
                    </svg>
                    <input
                        v-model="searchQuery"
                        @input="handleSearch"
                        type="text"
                        placeholder="Search by household name..."
                        class="w-full rounded-lg border border-gray-200 bg-gray-50 py-2 pr-4 pl-9 text-sm focus:border-gray-400 focus:bg-white focus:outline-none"
                    />
                </div>
                <select
                    v-model="filterStatus"
                    @change="loadReports()"
                    class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 focus:outline-none"
                >
                    <option value="">All Statuses</option>
                    <option value="pending">Pending</option>
                    <option value="reviewed">Reviewed</option>
                    <option value="warned">Warned</option>
                    <option value="blocked">Blocked</option>
                    <option value="dismissed">Dismissed</option>
                </select>
                <select
                    v-model="filterOutcome"
                    @change="loadReports()"
                    class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 focus:outline-none"
                >
                    <option value="">All Outcomes</option>
                    <option value="misuse">Misuse</option>
                    <option value="legitimate">Legitimate</option>
                </select>
                <button
                    @click="loadReports()"
                    class="rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50"
                >
                    ↻ Refresh
                </button>
            </div>

            <!-- TABLE -->
            <div class="overflow-x-auto">
                <table class="w-full min-w-max table-auto text-left text-sm">
                    <thead>
                        <tr class="bg-gray-50">
                            <th
                                class="border-b border-gray-200 p-4 text-xs font-bold tracking-wide text-gray-500 uppercase"
                            >
                                Household
                            </th>
                            <th
                                class="border-b border-gray-200 p-4 text-xs font-bold tracking-wide text-gray-500 uppercase"
                            >
                                Reporter
                            </th>
                            <th
                                class="border-b border-gray-200 p-4 text-xs font-bold tracking-wide text-gray-500 uppercase"
                            >
                                Outcome
                            </th>
                            <th
                                class="border-b border-gray-200 p-4 text-xs font-bold tracking-wide text-gray-500 uppercase"
                            >
                                Category
                            </th>
                            <th
                                class="border-b border-gray-200 p-4 text-xs font-bold tracking-wide text-gray-500 uppercase"
                            >
                                Status
                            </th>
                            <th
                                class="border-b border-gray-200 p-4 text-xs font-bold tracking-wide text-gray-500 uppercase"
                            >
                                Date
                            </th>
                            <th
                                class="border-b border-gray-200 p-4 text-xs font-bold tracking-wide text-gray-500 uppercase"
                            >
                                Action
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading">
                            <td colspan="7" class="p-12 text-center">
                                <div
                                    class="mx-auto h-6 w-6 animate-spin rounded-full border-2 border-gray-200 border-t-gray-800"
                                ></div>
                            </td>
                        </tr>
                        <tr v-else-if="reportList.length === 0">
                            <td colspan="7" class="p-12 text-center">
                                <div class="mb-2 text-3xl">📋</div>
                                <div class="font-semibold text-gray-600">
                                    No reports found
                                </div>
                                <div class="mt-1 text-xs text-gray-400">
                                    Try adjusting the date range or filters
                                </div>
                            </td>
                        </tr>
                        <tr
                            v-for="report in reportList"
                            :key="report.id"
                            class="cursor-pointer border-b border-gray-100 last:border-0 hover:bg-gray-50/50"
                            @click="openDetail(report)"
                        >
                            <td class="p-4">
                                <p class="font-semibold text-gray-900">
                                    {{ report.household?.name ?? '—' }}
                                </p>
                                <p class="text-xs text-gray-400">
                                    {{ report.household?.email }}
                                </p>
                            </td>
                            <td class="p-4">
                                <p class="text-gray-700">
                                    {{ report.reporter?.name ?? '—' }}
                                </p>
                                <p class="text-xs text-gray-400">
                                    {{ report.reporter?.email }}
                                </p>
                            </td>
                            <td class="p-4">
                                <span
                                    :class="[
                                        'rounded-full border px-2.5 py-1 text-xs font-bold',
                                        outcomeConfig[report.outcome]?.cls,
                                    ]"
                                    >{{
                                        outcomeConfig[report.outcome]?.label ??
                                        report.outcome
                                    }}</span
                                >
                            </td>
                            <td class="p-4 text-xs text-gray-600">
                                {{
                                    report.misuse_category
                                        ? misuseCategoryLabel[
                                              report.misuse_category
                                          ]
                                        : '—'
                                }}
                            </td>
                            <td class="p-4">
                                <span
                                    :class="[
                                        'rounded-full border px-2.5 py-1 text-xs font-bold',
                                        statusConfig[report.status]?.cls,
                                    ]"
                                    >{{
                                        statusConfig[report.status]?.label ??
                                        report.status
                                    }}</span
                                >
                            </td>
                            <td class="p-4 text-xs text-gray-600">
                                {{ fmtDate(report.created_at) }}
                            </td>
                            <td class="p-4">
                                <button
                                    @click.stop="openDetail(report)"
                                    class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-100"
                                >
                                    View →
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- PAGINATION -->
            <div
                class="flex items-center justify-between border-t border-gray-100 p-4"
            >
                <div class="text-sm text-gray-500">
                    Showing {{ reports.from ?? 0 }} to {{ reports.to ?? 0 }} of
                    {{ reports.total ?? 0 }} reports
                </div>
                <div class="flex gap-1.5">
                    <template v-for="(link, i) in reports.links" :key="i">
                        <button
                            v-if="link.url"
                            @click="loadReports(link.url)"
                            v-html="link.label"
                            :class="[
                                'inline-block min-w-[36px] rounded-lg border px-3 py-1.5 text-center text-xs font-semibold transition-all',
                                link.active
                                    ? 'border-gray-900 bg-gray-900 text-white'
                                    : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50',
                            ]"
                        />
                        <span
                            v-else
                            v-html="link.label"
                            class="inline-block min-w-[36px] cursor-not-allowed rounded-lg border border-gray-200 bg-gray-100 px-3 py-1.5 text-center text-xs text-gray-400"
                        />
                    </template>
                </div>
            </div>
        </div>

        <!-- DETAIL MODAL -->
        <div
            v-if="showDetail"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4 backdrop-blur-sm"
        >
            <div
                class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white shadow-2xl"
            >
                <div
                    class="sticky top-0 z-10 flex items-center justify-between border-b border-gray-100 bg-white px-6 py-4"
                >
                    <div>
                        <h2 class="text-base font-bold text-gray-900">
                            Incident Report #{{ selectedReport?.id }}
                        </h2>
                        <p class="text-xs text-gray-500">
                            {{ fmtDateTime(selectedReport?.created_at) }}
                        </p>
                    </div>
                    <button
                        @click="showDetail = false"
                        class="rounded-lg p-2 text-gray-400 hover:bg-gray-100"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-5 w-5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
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
                <div
                    v-if="detailLoading"
                    class="flex items-center justify-center py-16"
                >
                    <div
                        class="h-6 w-6 animate-spin rounded-full border-2 border-gray-200 border-t-gray-800"
                    ></div>
                </div>
                <div v-else class="space-y-6 p-6">
                    <div class="flex items-center gap-3">
                        <span
                            :class="[
                                'rounded-full border px-3 py-1.5 text-xs font-bold',
                                outcomeConfig[selectedReport?.outcome]?.cls,
                            ]"
                            >{{
                                outcomeConfig[selectedReport?.outcome]?.label
                            }}</span
                        >
                        <span
                            :class="[
                                'rounded-full border px-3 py-1.5 text-xs font-bold',
                                statusConfig[selectedReport?.status]?.cls,
                            ]"
                            >{{
                                statusConfig[selectedReport?.status]?.label
                            }}</span
                        >
                        <span
                            v-if="selectedReport?.misuse_category"
                            class="rounded-full border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-semibold text-gray-600"
                            >{{
                                misuseCategoryLabel[
                                    selectedReport.misuse_category
                                ]
                            }}</span
                        >
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div
                            class="rounded-xl border border-gray-100 bg-gray-50 p-4"
                        >
                            <p
                                class="mb-2 text-xs font-bold tracking-wide text-gray-400 uppercase"
                            >
                                Household
                            </p>
                            <p class="font-semibold text-gray-900">
                                {{ selectedReport?.household?.name }}
                            </p>
                            <p class="text-xs text-gray-500">
                                {{ selectedReport?.household?.email }}
                            </p>
                            <p class="text-xs text-gray-500">
                                {{ selectedReport?.household?.phone }}
                            </p>
                        </div>
                        <div
                            class="rounded-xl border border-gray-100 bg-gray-50 p-4"
                        >
                            <p
                                class="mb-2 text-xs font-bold tracking-wide text-gray-400 uppercase"
                            >
                                Patroller
                            </p>
                            <p class="font-semibold text-gray-900">
                                {{ selectedReport?.reporter?.name }}
                            </p>
                            <p class="text-xs text-gray-500">
                                {{ selectedReport?.reporter?.email }}
                            </p>
                            <p class="text-xs text-gray-500">
                                {{ selectedReport?.reporter?.phone }}
                            </p>
                        </div>
                    </div>
                    <div
                        class="grid grid-cols-2 gap-3 rounded-xl border border-gray-100 bg-gray-50 p-4"
                    >
                        <div>
                            <p
                                class="text-xs font-bold tracking-wide text-gray-400 uppercase"
                            >
                                Arrived At
                            </p>
                            <p class="mt-1 text-sm font-semibold text-gray-800">
                                {{ fmtDateTime(selectedReport?.arrived_at) }}
                            </p>
                        </div>
                        <div>
                            <p
                                class="text-xs font-bold tracking-wide text-gray-400 uppercase"
                            >
                                Departed At
                            </p>
                            <p class="mt-1 text-sm font-semibold text-gray-800">
                                {{ fmtDateTime(selectedReport?.departed_at) }}
                            </p>
                        </div>
                        <div>
                            <p
                                class="text-xs font-bold tracking-wide text-gray-400 uppercase"
                            >
                                Injuries
                            </p>
                            <p
                                class="mt-1 text-sm font-semibold"
                                :class="
                                    selectedReport?.injuries_reported
                                        ? 'text-red-600'
                                        : 'text-gray-500'
                                "
                            >
                                {{
                                    selectedReport?.injuries_reported
                                        ? 'Yes'
                                        : 'No'
                                }}
                            </p>
                        </div>
                        <div>
                            <p
                                class="text-xs font-bold tracking-wide text-gray-400 uppercase"
                            >
                                Property Damage
                            </p>
                            <p
                                class="mt-1 text-sm font-semibold"
                                :class="
                                    selectedReport?.property_damage
                                        ? 'text-red-600'
                                        : 'text-gray-500'
                                "
                            >
                                {{
                                    selectedReport?.property_damage
                                        ? 'Yes'
                                        : 'No'
                                }}
                            </p>
                        </div>
                    </div>
                    <div>
                        <p
                            class="mb-2 text-xs font-bold tracking-wide text-gray-400 uppercase"
                        >
                            Patroller's Account
                        </p>
                        <div
                            class="rounded-xl border border-gray-200 bg-white p-4 text-sm leading-relaxed text-gray-700 italic"
                        >
                            "{{ selectedReport?.narrative }}"
                        </div>
                    </div>
                    <div v-if="selectedReport?.additional_notes">
                        <p
                            class="mb-2 text-xs font-bold tracking-wide text-gray-400 uppercase"
                        >
                            Additional Notes
                        </p>
                        <div
                            class="rounded-xl border border-gray-100 bg-gray-50 p-4 text-sm text-gray-600"
                        >
                            {{ selectedReport.additional_notes }}
                        </div>
                    </div>
                    <div
                        v-if="selectedReport?.emergency_alert_id"
                        class="rounded-xl border border-blue-100 bg-blue-50 px-4 py-3"
                    >
                        <p class="text-xs font-semibold text-blue-700">
                            📡 Linked to Emergency Alert #{{
                                selectedReport.emergency_alert_id
                            }}
                        </p>
                    </div>
                    <div
                        v-if="selectedReport?.actioned_by"
                        class="rounded-xl border border-gray-200 bg-gray-50 p-4"
                    >
                        <p
                            class="mb-1 text-xs font-bold tracking-wide text-gray-400 uppercase"
                        >
                            Previous Action
                        </p>
                        <p class="text-sm text-gray-700">
                            By
                            <span class="font-semibold">{{
                                selectedReport.actioned_by?.name
                            }}</span>
                            on {{ fmtDateTime(selectedReport.actioned_at) }}
                        </p>
                        <p
                            v-if="selectedReport.admin_notes"
                            class="mt-2 text-sm text-gray-500 italic"
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
                        class="rounded-xl border border-gray-200 bg-gray-50 p-5"
                    >
                        <p class="mb-3 text-sm font-bold text-gray-900">
                            Take Action
                        </p>
                        <div class="mb-4">
                            <label
                                class="mb-1 block text-xs font-semibold text-gray-600"
                                >Admin Notes (optional)</label
                            ><textarea
                                v-model="actionNotes"
                                rows="2"
                                placeholder="Add internal notes..."
                                class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none"
                            ></textarea>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-if="selectedReport?.status === 'pending'"
                                @click="takeAction('review')"
                                :disabled="actionLoading"
                                class="flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-100 disabled:opacity-50"
                            >
                                <span
                                    v-if="actionLoading"
                                    class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-blue-300 border-t-blue-700"
                                ></span
                                >👁 Mark Reviewed
                            </button>
                            <button
                                v-if="
                                    selectedReport?.outcome === 'misuse' &&
                                    selectedReport?.status !== 'warned'
                                "
                                @click="takeAction('warn')"
                                :disabled="actionLoading"
                                class="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-700 hover:bg-amber-100 disabled:opacity-50"
                            >
                                <span
                                    v-if="actionLoading"
                                    class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-amber-300 border-t-amber-700"
                                ></span
                                >⚠ Send Warning
                            </button>
                            <button
                                v-if="selectedReport?.outcome === 'misuse'"
                                @click="takeAction('block')"
                                :disabled="actionLoading"
                                class="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-100 disabled:opacity-50"
                            >
                                <span
                                    v-if="actionLoading"
                                    class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-red-300 border-t-red-700"
                                ></span
                                >🚫 Block SOS
                            </button>
                            <button
                                @click="takeAction('dismiss')"
                                :disabled="actionLoading"
                                class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-100 disabled:opacity-50"
                            >
                                <span
                                    v-if="actionLoading"
                                    class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-gray-300 border-t-gray-600"
                                ></span
                                >✕ Dismiss
                            </button>
                        </div>
                    </div>
                    <div
                        v-else
                        class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-center text-sm text-gray-500"
                    >
                        Report
                        <span class="font-semibold text-gray-700">{{
                            selectedReport?.status
                        }}</span>
                        — no further action required.
                    </div>
                </div>
            </div>
        </div>

        <!-- EXPORT MODAL -->
        <div
            v-if="showExport"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4 backdrop-blur-sm"
        >
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                <h3 class="mb-1 text-base font-bold text-gray-900">
                    Export Reports
                </h3>
                <p class="mb-5 text-xs text-gray-500">
                    Downloads data for the selected date range and active
                    filters.
                </p>
                <div
                    class="mb-5 rounded-xl border border-gray-100 bg-gray-50 px-4 py-3 text-sm text-gray-700"
                >
                    <span class="font-semibold">Period:</span> {{ dateFrom }} →
                    {{ dateTo }}
                    <span
                        v-if="filterStatus || filterOutcome"
                        class="ml-3 text-gray-400"
                        >· Filters applied</span
                    >
                </div>
                <p
                    class="mb-2 text-xs font-bold tracking-wide text-gray-500 uppercase"
                >
                    Format
                </p>
                <div class="mb-5 flex gap-3">
                    <button
                        v-for="f in [
                            {
                                id: 'pdf',
                                label: '📄 PDF',
                                sub: 'Formatted report',
                            },
                            { id: 'csv', label: '📊 CSV', sub: 'Spreadsheet' },
                            { id: 'both', label: '⬇ Both', sub: 'PDF + CSV' },
                        ]"
                        :key="f.id"
                        @click="exportFormat = f.id as any"
                        :class="[
                            'flex-1 rounded-xl border px-3 py-3 text-center transition-all',
                            exportFormat === f.id
                                ? 'border-gray-900 bg-gray-900 text-white'
                                : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50',
                        ]"
                    >
                        <div class="text-sm font-bold">{{ f.label }}</div>
                        <div class="mt-0.5 text-xs opacity-60">{{ f.sub }}</div>
                    </button>
                </div>
                <div class="flex gap-3">
                    <button
                        @click="showExport = false"
                        class="flex-1 rounded-lg border border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-600 hover:bg-gray-50"
                    >
                        Cancel
                    </button>
                    <button
                        @click="handleExport"
                        :disabled="exportLoading"
                        class="flex-1 rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-gray-700 disabled:opacity-50"
                    >
                        {{ exportLoading ? 'Generating...' : '⬇ Download' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- EMAIL MODAL -->
        <div
            v-if="showEmail"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4 backdrop-blur-sm"
        >
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                <h3 class="mb-1 text-base font-bold text-gray-900">
                    Send Report via Email
                </h3>
                <p class="mb-5 text-xs text-gray-500">
                    Report compiled for the selected date range and sent as
                    attachment.
                </p>
                <div
                    class="mb-5 rounded-xl border border-gray-100 bg-gray-50 px-4 py-3 text-sm text-gray-700"
                >
                    <span class="font-semibold">Period:</span> {{ dateFrom }} →
                    {{ dateTo }}
                </div>
                <p
                    class="mb-2 text-xs font-bold tracking-wide text-gray-500 uppercase"
                >
                    Attach as
                </p>
                <div class="mb-5 flex gap-3">
                    <button
                        v-for="f in [
                            { id: 'pdf', label: '📄 PDF' },
                            { id: 'csv', label: '📊 CSV' },
                        ]"
                        :key="f.id"
                        @click="toggleEmailFormat(f.id)"
                        :class="[
                            'flex-1 rounded-xl border px-4 py-2.5 text-sm font-bold transition-all',
                            emailFormats.includes(f.id)
                                ? 'border-blue-500 bg-blue-500 text-white'
                                : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50',
                        ]"
                    >
                        {{ f.label }}
                        <span v-if="emailFormats.includes(f.id)" class="ml-1"
                            >✓</span
                        >
                    </button>
                </div>
                <p
                    class="mb-2 text-xs font-bold tracking-wide text-gray-500 uppercase"
                >
                    Recipients
                </p>
                <div class="mb-2 flex gap-2">
                    <input
                        v-model="emailInput"
                        type="email"
                        placeholder="email@example.com"
                        @keydown.enter.prevent="addEmail"
                        class="flex-1 rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none"
                    />
                    <button
                        @click="addEmail"
                        class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-bold text-white hover:bg-gray-700"
                    >
                        Add
                    </button>
                </div>
                <p v-if="emailError" class="mb-2 text-xs text-red-600">
                    {{ emailError }}
                </p>
                <div
                    v-if="emailList.length > 0"
                    class="mb-4 flex flex-wrap gap-2"
                >
                    <span
                        v-for="e in emailList"
                        :key="e"
                        class="flex items-center gap-1.5 rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700"
                    >
                        {{ e }}
                        <button
                            @click="removeEmail(e)"
                            class="ml-1 text-blue-400 hover:text-blue-700"
                        >
                            ✕
                        </button>
                    </span>
                </div>
                <p v-else class="mb-4 text-xs text-gray-400">
                    No recipients yet — press Enter or click Add.
                </p>
                <div class="flex gap-3">
                    <button
                        @click="
                            showEmail = false;
                            emailList = [];
                            emailError = '';
                        "
                        class="flex-1 rounded-lg border border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-600 hover:bg-gray-50"
                    >
                        Cancel
                    </button>
                    <button
                        @click="sendEmail"
                        :disabled="emailLoading || emailList.length === 0"
                        class="flex-1 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                    >
                        {{
                            emailLoading
                                ? 'Sending...'
                                : `✉ Send to ${emailList.length} recipient${emailList.length !== 1 ? 's' : ''}`
                        }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

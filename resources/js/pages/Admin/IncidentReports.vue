<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';

// ─── state ────────────────────────────────────────────────────────────────────
const reports = ref<any>({ data: [], total: 0, from: 0, to: 0, links: [] });
const reportList = ref<any[]>([]);
const loading = ref(false);
const searchQuery = ref('');
const filterStatus = ref('');
const filterOutcome = ref('');
let searchTimeout: any = null;

const selectedReport = ref<any>(null);
const showDetail = ref(false);
const detailLoading = ref(false);

const actionLoading = ref(false);
const actionNotes = ref('');
const flash = ref<{ msg: string; type: 'success' | 'error' } | null>(null);

// ─── export state ─────────────────────────────────────────────────────────────
const exportLoading = ref<'pdf' | 'csv' | 'email' | null>(null);
const showEmailModal = ref(false);
const emailTarget = ref('');
const emailScope = ref<'all' | 'single'>('all');
const emailSending = ref(false);

// ─── helpers ──────────────────────────────────────────────────────────────────
const getHeaders = () => ({
    headers: { Authorization: `Bearer ${localStorage.getItem('token')}` },
});

function showFlash(msg: string, type: 'success' | 'error' = 'success') {
    flash.value = { msg, type };
    setTimeout(() => (flash.value = null), 4000);
}

// ─── stats ────────────────────────────────────────────────────────────────────
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

// ─── fetch ────────────────────────────────────────────────────────────────────
async function loadReports(url?: string) {
    loading.value = true;
    try {
        const endpoint =
            url || `${import.meta.env.VITE_APP_URL}/api/admin/incident-reports`;
        const { data } = await axios.get(endpoint, {
            params: {
                search: searchQuery.value || undefined,
                status: filterStatus.value || undefined,
                outcome: filterOutcome.value || undefined,
            },
            ...getHeaders(),
        });
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

// ─── formatting ───────────────────────────────────────────────────────────────
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

// ─── CSV export ───────────────────────────────────────────────────────────────
function buildCsvRows(rows: any[]): string {
    const headers = [
        'ID',
        'Household',
        'Household Email',
        'Reporter',
        'Reporter Email',
        'Outcome',
        'Category',
        'Status',
        'Injuries',
        'Property Damage',
        'Arrived At',
        'Departed At',
        'Narrative',
        'Admin Notes',
        'Created At',
    ];

    const escape = (v: any) => {
        const s = v == null ? '' : String(v);
        return s.includes(',') || s.includes('"') || s.includes('\n')
            ? `"${s.replace(/"/g, '""')}"`
            : s;
    };

    const lines = [headers.join(',')];
    for (const r of rows) {
        lines.push(
            [
                r.id,
                r.household?.name ?? '',
                r.household?.email ?? '',
                r.reporter?.name ?? '',
                r.reporter?.email ?? '',
                r.outcome ?? '',
                r.misuse_category
                    ? (misuseCategoryLabel[r.misuse_category] ??
                      r.misuse_category)
                    : '',
                r.status ?? '',
                r.injuries_reported ? 'Yes' : 'No',
                r.property_damage ? 'Yes' : 'No',
                r.arrived_at ? fmtDateTime(r.arrived_at) : '',
                r.departed_at ? fmtDateTime(r.departed_at) : '',
                r.narrative ?? '',
                r.admin_notes ?? '',
                r.created_at ? fmtDateTime(r.created_at) : '',
            ]
                .map(escape)
                .join(','),
        );
    }
    return lines.join('\n');
}

function downloadCsv(rows: any[], filename: string) {
    const csv = buildCsvRows(rows);
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

async function exportCsv(scope: 'all' | 'single' = 'all') {
    exportLoading.value = 'csv';
    try {
        if (scope === 'single' && selectedReport.value) {
            downloadCsv(
                [selectedReport.value],
                `incident-report-${selectedReport.value.id}.csv`,
            );
        } else {
            // Fetch all (no pagination) for export
            const { data } = await axios.get(
                `${import.meta.env.VITE_APP_URL}/api/admin/incident-reports`,
                {
                    params: {
                        search: searchQuery.value || undefined,
                        status: filterStatus.value || undefined,
                        outcome: filterOutcome.value || undefined,
                        per_page: 9999,
                    },
                    ...getHeaders(),
                },
            );
            const rows = data.data ?? data;
            downloadCsv(
                rows,
                `incident-reports-${new Date().toISOString().slice(0, 10)}.csv`,
            );
        }
        showFlash('CSV downloaded successfully.');
    } catch {
        showFlash('CSV export failed.', 'error');
    } finally {
        exportLoading.value = null;
    }
}

// ─── PDF export ───────────────────────────────────────────────────────────────
async function loadJsPDF(): Promise<any> {
    // Dynamically import jsPDF from CDN
    if ((window as any).jspdf) return (window as any).jspdf.jsPDF;
    await new Promise<void>((resolve, reject) => {
        const s = document.createElement('script');
        s.src =
            'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
        s.onload = () => resolve();
        s.onerror = () => reject(new Error('Failed to load jsPDF'));
        document.head.appendChild(s);
    });
    await new Promise<void>((resolve, reject) => {
        const s = document.createElement('script');
        s.src =
            'https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js';
        s.onload = () => resolve();
        s.onerror = () => reject(new Error('Failed to load autoTable'));
        document.head.appendChild(s);
    });
    return (window as any).jspdf.jsPDF;
}

async function exportPdf(scope: 'all' | 'single' = 'all') {
    exportLoading.value = 'pdf';
    try {
        const JsPDF = await loadJsPDF();
        const doc = new JsPDF({
            orientation: 'landscape',
            unit: 'mm',
            format: 'a4',
        });

        // Header
        doc.setFillColor(17, 24, 39);
        doc.rect(0, 0, 297, 18, 'F');
        doc.setTextColor(255, 255, 255);
        doc.setFontSize(12);
        doc.setFont('helvetica', 'bold');
        doc.text('ECHO LINK - Incident Report', 10, 11);
        doc.setFontSize(8);
        doc.setFont('helvetica', 'normal');
        doc.text(`Generated: ${new Date().toLocaleString('en-ZA')}`, 230, 11);

        let rows: any[] = [];
        if (scope === 'single' && selectedReport.value) {
            rows = [selectedReport.value];
        } else {
            const { data } = await axios.get(
                `${import.meta.env.VITE_APP_URL}/api/admin/incident-reports`,
                {
                    params: {
                        search: searchQuery.value || undefined,
                        status: filterStatus.value || undefined,
                        outcome: filterOutcome.value || undefined,
                        per_page: 9999,
                    },
                    ...getHeaders(),
                },
            );
            rows = data.data ?? data;
        }

        const tableRows = rows.map((r: any) => [
            r.id,
            r.household?.name ?? '—',
            r.reporter?.name ?? '—',
            r.outcome ?? '—',
            r.misuse_category
                ? (misuseCategoryLabel[r.misuse_category] ?? r.misuse_category)
                : '—',
            r.status ?? '—',
            r.injuries_reported ? 'Yes' : 'No',
            r.property_damage ? 'Yes' : 'No',
            r.narrative ?? '-',
            r.admin_notes ?? '-',
            r.created_at ? fmtDate(r.created_at) : '—',
        ]);

        (doc as any).autoTable({
            startY: 22,
            head: [
                [
                    '#',
                    'Household',
                    'Reporter',
                    'Outcome',
                    'Category',
                    'Status',
                    'Injuries',
                    'Damage',
                    'Narrative',
                    'Admin Notes',
                    'Date',
                ],
            ],
            body: tableRows,
            theme: 'grid',
            headStyles: {
                fillColor: [31, 41, 55],
                textColor: 255,
                fontStyle: 'bold',
                fontSize: 8,
            },
            bodyStyles: { fontSize: 7.5, textColor: [31, 41, 55] },
            alternateRowStyles: { fillColor: [249, 250, 251] },
            columnStyles: {
                0: { cellWidth: 12 },
                1: { cellWidth: 48 },
                2: { cellWidth: 48 },
                3: { cellWidth: 24 },
                4: { cellWidth: 36 },
                5: { cellWidth: 24 },
                6: { cellWidth: 18 },
                7: { cellWidth: 18 },
                8: { cellWidth: 28 },
            },
            margin: { left: 10, right: 10 },
        });

        // Single report — add narrative block on next page
        if (scope === 'single' && selectedReport.value) {
            const r = selectedReport.value;
            doc.addPage();
            doc.setFillColor(17, 24, 39);
            doc.rect(0, 0, 297, 18, 'F');
            doc.setTextColor(255, 255, 255);
            doc.setFontSize(11);
            doc.setFont('helvetica', 'bold');
            doc.text(`Report #${r.id} — Full Detail`, 10, 11);

            doc.setTextColor(31, 41, 55);
            doc.setFontSize(9);
            doc.setFont('helvetica', 'bold');
            doc.text("Patroller's Account", 10, 28);
            doc.setFont('helvetica', 'normal');
            const narrative = doc.splitTextToSize(r.narrative ?? '—', 270);
            doc.text(narrative, 10, 35);

            if (r.admin_notes) {
                const yAfter = 35 + narrative.length * 5 + 6;
                doc.setFont('helvetica', 'bold');
                doc.text('Admin Notes', 10, yAfter);
                doc.setFont('helvetica', 'normal');
                doc.text(
                    doc.splitTextToSize(r.admin_notes, 270),
                    10,
                    yAfter + 7,
                );
            }
        }

        const filename =
            scope === 'single'
                ? `incident-report-${selectedReport.value?.id}.pdf`
                : `incident-reports-${new Date().toISOString().slice(0, 10)}.pdf`;
        doc.save(filename);
        showFlash('PDF downloaded successfully.');
    } catch (e: any) {
        showFlash(e?.message ?? 'PDF export failed.', 'error');
    } finally {
        exportLoading.value = null;
    }
}

// ─── Email export ─────────────────────────────────────────────────────────────
function openEmailModal(scope: 'all' | 'single') {
    emailScope.value = scope;
    emailTarget.value = '';
    showEmailModal.value = true;
}

async function sendEmail() {
    if (!emailTarget.value.trim()) {
        showFlash('Please enter an email address.', 'error');
        return;
    }
    emailSending.value = true;
    try {
        const payload: any = {
            email: emailTarget.value.trim(),
            filters: {
                search: searchQuery.value || undefined,
                status: filterStatus.value || undefined,
                outcome: filterOutcome.value || undefined,
            },
        };
        if (emailScope.value === 'single' && selectedReport.value) {
            payload.report_id = selectedReport.value.id;
        }
        await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/admin/incident-reports/send-email`,
            payload,
            getHeaders(),
        );
        showFlash(
            `Report${emailScope.value === 'single' ? '' : 's'} sent to ${emailTarget.value}`,
        );
        showEmailModal.value = false;
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to send email.',
            'error',
        );
    } finally {
        emailSending.value = false;
    }
}

onMounted(() => loadReports());
</script>

<template>
    <Head title="Incident Reports" />
    <AppLayout>
        <div
            class="relative flex h-full w-full flex-col rounded-xl bg-white bg-clip-border text-gray-700 shadow-md"
        >
            <!-- ── HEADER ── -->
            <div class="border-b border-gray-100 px-6 py-5">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-lg font-bold text-gray-900">
                            Incident Reports
                        </h1>
                        <p class="mt-0.5 text-sm text-gray-500">
                            SOS alert reports submitted by patrollers — review
                            and take action
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <!-- Export buttons (list scope) -->
                        <button
                            @click="exportCsv('all')"
                            :disabled="exportLoading === 'csv'"
                            class="flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-600 hover:bg-gray-50 disabled:opacity-50"
                            title="Download all visible reports as CSV"
                        >
                            <span
                                v-if="exportLoading === 'csv'"
                                class="h-3 w-3 animate-spin rounded-full border-2 border-gray-300 border-t-gray-700"
                            ></span>
                            <svg
                                v-else
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-3.5 w-3.5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"
                                />
                            </svg>
                            CSV
                        </button>
                        <button
                            @click="exportPdf('all')"
                            :disabled="exportLoading === 'pdf'"
                            class="flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-600 hover:bg-gray-50 disabled:opacity-50"
                            title="Download all visible reports as PDF"
                        >
                            <span
                                v-if="exportLoading === 'pdf'"
                                class="h-3 w-3 animate-spin rounded-full border-2 border-gray-300 border-t-gray-700"
                            ></span>
                            <svg
                                v-else
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-3.5 w-3.5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"
                                />
                            </svg>
                            PDF
                        </button>
                        <button
                            @click="openEmailModal('all')"
                            class="flex items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-100"
                            title="Send reports to email"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-3.5 w-3.5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
                                />
                            </svg>
                            Email
                        </button>

                        <!-- Flash -->
                        <div
                            v-if="flash"
                            :class="[
                                'rounded-xl px-4 py-2.5 text-sm font-semibold shadow',
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

            <!-- ── STATS ── -->
            <div class="grid grid-cols-6 gap-3 border-b border-gray-100 p-5">
                <div class="rounded-xl bg-gray-900 p-4 text-center">
                    <div class="text-2xl font-bold text-white">
                        {{ reports.total ?? 0 }}
                    </div>
                    <div class="mt-1 text-xs text-gray-400">Total Reports</div>
                </div>
                <div
                    class="rounded-xl border border-amber-100 bg-amber-50 p-4 text-center"
                >
                    <div class="text-2xl font-bold text-amber-700">
                        {{ stats.pending }}
                    </div>
                    <div class="mt-1 text-xs text-amber-600">
                        Pending Review
                    </div>
                </div>
                <div
                    class="rounded-xl border border-red-100 bg-red-50 p-4 text-center"
                >
                    <div class="text-2xl font-bold text-red-700">
                        {{ stats.misuse }}
                    </div>
                    <div class="mt-1 text-xs text-red-500">Misuse Reports</div>
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
                    <div class="mt-1 text-xs text-orange-600">
                        Warnings Sent
                    </div>
                </div>
                <div
                    class="rounded-xl border border-rose-100 bg-rose-50 p-4 text-center"
                >
                    <div class="text-2xl font-bold text-rose-700">
                        {{ stats.blocked }}
                    </div>
                    <div class="mt-1 text-xs text-rose-600">SOS Blocked</div>
                </div>
            </div>

            <!-- ── FILTERS ── -->
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
                    class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 focus:border-gray-400 focus:outline-none"
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
                    class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 focus:border-gray-400 focus:outline-none"
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

            <!-- ── TABLE ── -->
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
                                    Reports submitted by patrollers will appear
                                    here
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
                                >
                                    {{
                                        outcomeConfig[report.outcome]?.label ??
                                        report.outcome
                                    }}
                                </span>
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
                                >
                                    {{
                                        statusConfig[report.status]?.label ??
                                        report.status
                                    }}
                                </span>
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

            <!-- ── PAGINATION ── -->
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

        <!-- ══════════════════════════════════════════ -->
        <!-- DETAIL MODAL                               -->
        <!-- ══════════════════════════════════════════ -->
        <div
            v-if="showDetail"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4 backdrop-blur-sm"
        >
            <div
                class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white shadow-2xl"
            >
                <!-- Header -->
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
                    <div class="flex items-center gap-2">
                        <!-- Per-report export buttons -->
                        <button
                            @click="exportCsv('single')"
                            :disabled="exportLoading === 'csv'"
                            class="flex items-center gap-1.5 rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50 disabled:opacity-50"
                            title="Download this report as CSV"
                        >
                            <span
                                v-if="exportLoading === 'csv'"
                                class="h-3 w-3 animate-spin rounded-full border-2 border-gray-300 border-t-gray-700"
                            ></span>
                            <svg
                                v-else
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-3.5 w-3.5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"
                                />
                            </svg>
                            CSV
                        </button>
                        <button
                            @click="exportPdf('single')"
                            :disabled="exportLoading === 'pdf'"
                            class="flex items-center gap-1.5 rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50 disabled:opacity-50"
                            title="Download this report as PDF"
                        >
                            <span
                                v-if="exportLoading === 'pdf'"
                                class="h-3 w-3 animate-spin rounded-full border-2 border-gray-300 border-t-gray-700"
                            ></span>
                            <svg
                                v-else
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-3.5 w-3.5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"
                                />
                            </svg>
                            PDF
                        </button>
                        <button
                            @click="openEmailModal('single')"
                            class="flex items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-2.5 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-100"
                            title="Send this report by email"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-3.5 w-3.5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
                                />
                            </svg>
                            Email
                        </button>
                        <button
                            @click="showDetail = false"
                            class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
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
                    <!-- Outcome + Status badges -->
                    <div class="flex items-center gap-3">
                        <span
                            :class="[
                                'rounded-full border px-3 py-1.5 text-xs font-bold',
                                outcomeConfig[selectedReport?.outcome]?.cls,
                            ]"
                        >
                            {{ outcomeConfig[selectedReport?.outcome]?.label }}
                        </span>
                        <span
                            :class="[
                                'rounded-full border px-3 py-1.5 text-xs font-bold',
                                statusConfig[selectedReport?.status]?.cls,
                            ]"
                        >
                            {{ statusConfig[selectedReport?.status]?.label }}
                        </span>
                        <span
                            v-if="selectedReport?.misuse_category"
                            class="rounded-full border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-semibold text-gray-600"
                        >
                            {{
                                misuseCategoryLabel[
                                    selectedReport.misuse_category
                                ]
                            }}
                        </span>
                    </div>

                    <!-- Parties -->
                    <div class="grid grid-cols-2 gap-4">
                        <div
                            class="rounded-xl border border-gray-100 bg-gray-50 p-4"
                        >
                            <p
                                class="mb-2 text-xs font-bold tracking-wide text-gray-400 uppercase"
                            >
                                Household (Subject)
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
                                Reporter (Patroller)
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

                    <!-- Incident details -->
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
                                Injuries Reported
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

                    <!-- Narrative -->
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

                    <!-- Additional notes -->
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

                    <!-- Emergency alert link -->
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

                    <!-- Previous admin action -->
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
                            Actioned by
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

                    <!-- ── ACTION PANEL ── -->
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
                            >
                            <textarea
                                v-model="actionNotes"
                                rows="2"
                                placeholder="Add internal notes before actioning..."
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
                                ></span>
                                👁 Mark Reviewed
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
                                ></span>
                                ⚠ Send Warning Email
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
                                ></span>
                                🚫 Block SOS Access
                            </button>
                            <button
                                @click="takeAction('dismiss')"
                                :disabled="actionLoading"
                                class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-100 disabled:opacity-50"
                            >
                                <span
                                    v-if="actionLoading"
                                    class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-gray-300 border-t-gray-600"
                                ></span>
                                ✕ Dismiss Report
                            </button>
                        </div>
                    </div>
                    <div
                        v-else
                        class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-center text-sm text-gray-500"
                    >
                        This report has been
                        <span class="font-semibold text-gray-700">{{
                            selectedReport?.status
                        }}</span>
                        and requires no further action.
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════ -->
        <!-- EMAIL MODAL                                -->
        <!-- ══════════════════════════════════════════ -->
        <div
            v-if="showEmailModal"
            class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 px-4 backdrop-blur-sm"
        >
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div
                    class="flex items-center justify-between border-b border-gray-100 px-6 py-4"
                >
                    <div>
                        <h3 class="text-sm font-bold text-gray-900">
                            Send Report{{ emailScope === 'all' ? 's' : '' }} by
                            Email
                        </h3>
                        <p class="mt-0.5 text-xs text-gray-500">
                            {{
                                emailScope === 'single'
                                    ? `Report #${selectedReport?.id} will be sent as a PDF attachment`
                                    : 'Current filtered reports will be sent as a PDF attachment'
                            }}
                        </p>
                    </div>
                    <button
                        @click="showEmailModal = false"
                        class="rounded-lg p-2 text-gray-400 hover:bg-gray-100"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-4 w-4"
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
                <div class="space-y-4 p-6">
                    <div>
                        <label
                            class="mb-1.5 block text-xs font-semibold text-gray-700"
                            >Recipient Email Address</label
                        >
                        <input
                            v-model="emailTarget"
                            type="email"
                            placeholder="admin@example.com"
                            class="w-full rounded-lg border border-gray-200 px-3 py-2.5 text-sm focus:border-gray-400 focus:outline-none"
                            @keyup.enter="sendEmail"
                        />
                    </div>
                    <div class="flex gap-3">
                        <button
                            @click="showEmailModal = false"
                            class="flex-1 rounded-lg border border-gray-200 py-2.5 text-sm font-semibold text-gray-600 hover:bg-gray-50"
                        >
                            Cancel
                        </button>
                        <button
                            @click="sendEmail"
                            :disabled="emailSending || !emailTarget.trim()"
                            class="flex flex-1 items-center justify-center gap-2 rounded-lg bg-gray-900 py-2.5 text-sm font-semibold text-white hover:bg-gray-800 disabled:opacity-50"
                        >
                            <span
                                v-if="emailSending"
                                class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-gray-600 border-t-white"
                            ></span>
                            <svg
                                v-else
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-3.5 w-3.5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
                                />
                            </svg>
                            {{ emailSending ? 'Sending…' : 'Send Email' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

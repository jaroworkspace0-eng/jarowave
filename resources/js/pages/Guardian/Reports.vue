<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [];

// ── State ─────────────────────────────────────────────────────
const reports = ref<any[]>([]);
const loading = ref(false);
const reviewingId = ref<number | null>(null);
const reviewNotes = ref('');
const showReviewModal = ref(false);
const selectedReport = ref<any | null>(null);

// ── Filters ───────────────────────────────────────────────────
type ReportStatus = 'all' | 'pending' | 'reviewed' | 'escalated' | 'flagged';
const filterStatus = ref<ReportStatus>('all');
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
                String(r.id).includes(q) ||
                r.reporting_household?.name?.toLowerCase().includes(q) ||
                r.description?.toLowerCase().includes(q),
        );
    }
    return list;
});

// ── Filter chip config ───────────────────────────────────────
const statusOptions = [
    { value: 'all', label: 'All' },
    { value: 'pending', label: 'Pending' },
    { value: 'reviewed', label: 'Reviewed' },
    { value: 'escalated', label: 'Escalated' },
    { value: 'flagged', label: 'Flagged' },
] as const;

const severityOptions = [
    { value: 'all', label: 'All' },
    { value: 'high', label: 'High' },
    { value: 'medium', label: 'Medium' },
    { value: 'low', label: 'Low' },
] as const;

const alertTypeOptions = [
    { value: 'all', label: 'All' },
    { value: 'dv', label: 'DV Alert' },
    { value: 'sos', label: 'SOS Alert' },
] as const;

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

function setStatus(v: (typeof statusOptions)[number]['value']) {
    filterStatus.value = v;
    currentPage.value = 1;
    loadReports();
}
function setSeverity(v: (typeof severityOptions)[number]['value']) {
    filterSeverity.value = v;
    currentPage.value = 1;
    loadReports();
}
function setAlertType(v: (typeof alertTypeOptions)[number]['value']) {
    filterAlertType.value = v;
    currentPage.value = 1;
    loadReports();
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

const severityBadge = (s: string) =>
    ({
        high: 'bg-red-50 text-red-600',
        medium: 'bg-amber-50 text-amber-700',
        low: 'bg-emerald-50 text-emerald-700',
    })[s] ?? 'bg-slate-100 text-slate-600';

const statusBadge = (s: string) =>
    ({
        pending: 'bg-orange-50 text-orange-700',
        reviewed: 'bg-blue-50 text-blue-700',
        escalated: 'bg-red-50 text-red-600',
        flagged: 'bg-purple-50 text-purple-700',
    })[s] ?? 'bg-slate-100 text-slate-600';

const alertTypeBadge = (t: string) =>
    t === 'dv' ? 'bg-pink-50 text-pink-700' : 'bg-indigo-50 text-indigo-700';

onMounted(loadReports);
</script>

<template>
    <Head title="Guardian Reports" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="page-root">
            <!-- PAGE HEADER -->
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Safety</div>
                    <h1 class="page-header__title">Guardian Reports</h1>
                </div>
                <div class="page-header__right">
                    <button class="btn-secondary" @click="loadReports">
                        Refresh
                    </button>
                </div>
            </div>

            <!-- STAT CARDS -->
            <div class="stat-row">
                <div class="stat-card">
                    <div class="stat-card__label">Total Reports</div>
                    <div class="stat-card__value">{{ total }}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Pending Review</div>
                    <div class="stat-card__value stat-card__value--orange">
                        {{ stats.pending }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Escalated</div>
                    <div class="stat-card__value stat-card__value--red">
                        {{ stats.escalated }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">High Severity</div>
                    <div class="stat-card__value stat-card__value--red">
                        {{ stats.high }}
                    </div>
                </div>
            </div>

            <!-- FILTER BAR -->
            <div class="filter-card">
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
                        v-model="search"
                        type="text"
                        class="search-input"
                        placeholder="Search by name, report ID, description…"
                    />
                    <span
                        v-if="search"
                        class="search-clear"
                        @click="search = ''"
                        >×</span
                    >
                </div>

                <div class="filter-groups">
                    <div class="filter-group">
                        <span class="filter-group__label">Status</span>
                        <div class="filter-bar__chips">
                            <button
                                v-for="f in statusOptions"
                                :key="f.value"
                                @click="setStatus(f.value)"
                                class="chip"
                                :class="{
                                    'chip--active': filterStatus === f.value,
                                }"
                            >
                                {{ f.label }}
                            </button>
                        </div>
                    </div>

                    <div class="filter-group">
                        <span class="filter-group__label">Severity</span>
                        <div class="filter-bar__chips">
                            <button
                                v-for="f in severityOptions"
                                :key="f.value"
                                @click="setSeverity(f.value)"
                                class="chip"
                                :class="{
                                    'chip--active': filterSeverity === f.value,
                                }"
                            >
                                {{ f.label }}
                            </button>
                        </div>
                    </div>

                    <div class="filter-group">
                        <span class="filter-group__label">Type</span>
                        <div class="filter-bar__chips">
                            <button
                                v-for="f in alertTypeOptions"
                                :key="f.value"
                                @click="setAlertType(f.value)"
                                class="chip"
                                :class="{
                                    'chip--active': filterAlertType === f.value,
                                }"
                            >
                                {{ f.label }}
                            </button>
                        </div>
                    </div>
                </div>

                <span class="filter-bar__count"
                    >{{ filtered.length }} result{{
                        filtered.length !== 1 ? 's' : ''
                    }}</span
                >
            </div>

            <!-- TABLE CARD -->
            <div class="table-card">
                <!-- Loading -->
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

                <!-- Empty -->
                <div v-else-if="filtered.length === 0" class="empty-state">
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
                    <p class="empty-state__sub">Try adjusting your filters</p>
                </div>

                <!-- Table -->
                <table v-else class="data-table">
                    <thead>
                        <tr>
                            <th>Report</th>
                            <th>Reporter</th>
                            <th>Description</th>
                            <th>Flags</th>
                            <th>Severity</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="report in filtered" :key="report.id">
                            <td>
                                <span class="alert-id">#{{ report.id }}</span>
                            </td>
                            <td>
                                <div class="reporter-cell">
                                    <div>
                                        <div class="reporter-cell__name">
                                            {{
                                                report.reporting_household
                                                    ?.name ?? '—'
                                            }}
                                        </div>
                                        <div class="reporter-cell__sub">
                                            {{
                                                report.reporting_household
                                                    ?.address_line_1 ?? ''
                                            }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="td-announce">
                                <div class="td-announce__sub td-clamp-2">
                                    {{ report.description }}
                                </div>
                            </td>
                            <td>
                                <div class="flags-cell">
                                    <span
                                        v-if="report.seen_perpetrator"
                                        class="flag-badge flag-badge--danger"
                                        >Saw Perpetrator</span
                                    >
                                    <span
                                        v-if="report.heard_disturbance"
                                        class="flag-badge flag-badge--warn"
                                        >Heard Disturbance</span
                                    >
                                    <span
                                        v-if="
                                            !report.seen_perpetrator &&
                                            !report.heard_disturbance
                                        "
                                        class="flags-cell__none"
                                        >—</span
                                    >
                                </div>
                            </td>
                            <td>
                                <span
                                    class="type-badge"
                                    :class="severityBadge(report.severity)"
                                >
                                    {{ report.severity }}
                                </span>
                            </td>
                            <td>
                                <span
                                    class="type-badge"
                                    :class="alertTypeBadge(report.alert_type)"
                                >
                                    {{ report.alert_type }}
                                </span>
                            </td>
                            <td>
                                <span
                                    class="type-badge"
                                    :class="statusBadge(report.review_status)"
                                >
                                    {{ report.review_status }}
                                </span>
                            </td>
                            <td class="td-time">
                                <div>{{ formatDate(report.submitted_at) }}</div>
                                <div>{{ timeAgo(report.submitted_at) }}</div>
                            </td>
                            <td>
                                <div style="display: flex; gap: 6px">
                                    <button
                                        class="row-action-btn"
                                        @click="viewDetail(report.id)"
                                    >
                                        View
                                    </button>
                                    <button
                                        v-if="
                                            report.review_status === 'pending'
                                        "
                                        class="row-action-btn row-action-btn--primary"
                                        @click="openReview(report)"
                                    >
                                        Review
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div
                    class="pagination-bar"
                    v-if="!loading && filtered.length > 0 && lastPage > 1"
                >
                    <span class="pagination-bar__info"
                        >Page {{ currentPage }} of {{ lastPage }}</span
                    >
                    <div class="pagination-bar__pages">
                        <button
                            :disabled="currentPage === 1"
                            @click="
                                currentPage--;
                                loadReports();
                            "
                            class="page-btn"
                            :class="{ 'page-btn--disabled': currentPage === 1 }"
                        >
                            Previous
                        </button>
                        <button
                            :disabled="currentPage === lastPage"
                            @click="
                                currentPage++;
                                loadReports();
                            "
                            class="page-btn"
                            :class="{
                                'page-btn--disabled': currentPage === lastPage,
                            }"
                        >
                            Next
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════ REVIEW MODAL ═══════════════ -->
        <Teleport to="body">
            <transition name="modal">
                <div
                    v-if="showReviewModal && selectedReport"
                    class="modal-backdrop"
                    @click.self="closeModal"
                >
                    <div class="modal-sheet">
                        <!-- Header -->
                        <div class="modal-sheet__header">
                            <div class="modal-sheet__header-left">
                                <div>
                                    <div class="modal-sheet__title">
                                        Review Report
                                    </div>
                                    <div class="modal-sheet__sub">
                                        Report #{{ selectedReport.id }}
                                    </div>
                                </div>
                            </div>
                            <button class="close-btn" @click="closeModal">
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

                        <!-- Body -->
                        <div class="modal-sheet__body">
                            <!-- Reporter -->
                            <div class="review-info-panel">
                                <div class="field__label">Reporter</div>
                                <div class="review-info-panel__name">
                                    {{
                                        selectedReport.reporting_household
                                            ?.name ?? '—'
                                    }}
                                </div>
                                <div class="review-info-panel__sub">
                                    {{
                                        selectedReport.reporting_household
                                            ?.address_line_1 ?? ''
                                    }}
                                </div>
                            </div>

                            <!-- Flags -->
                            <div class="field">
                                <label class="field__label">Flags</label>
                                <div class="toggle-row">
                                    <div
                                        class="flag-panel"
                                        :class="{
                                            'flag-panel--danger':
                                                selectedReport.seen_perpetrator,
                                        }"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            class="h-4 w-4"
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
                                        Saw Perpetrator
                                    </div>
                                    <div
                                        class="flag-panel"
                                        :class="{
                                            'flag-panel--warn':
                                                selectedReport.heard_disturbance,
                                        }"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            class="h-4 w-4"
                                            fill="currentColor"
                                            viewBox="0 0 20 20"
                                        >
                                            <path
                                                fill-rule="evenodd"
                                                d="M9.383 3.076A1 1 0 0110 4v12a1 1 0 01-1.707.707L4.586 13H2a1 1 0 01-1-1V8a1 1 0 011-1h2.586l3.707-3.707a1 1 0 011.09-.217zM14.657 2.929a1 1 0 011.414 0A9.972 9.972 0 0119 10a9.972 9.972 0 01-2.929 7.071 1 1 0 01-1.414-1.414A7.971 7.971 0 0017 10c0-2.21-.894-4.208-2.343-5.657a1 1 0 010-1.414zm-2.829 2.828a1 1 0 011.415 0A5.983 5.983 0 0115 10a5.984 5.984 0 01-1.757 4.243 1 1 0 01-1.415-1.415A3.984 3.984 0 0013 10a3.983 3.983 0 00-1.172-2.828 1 1 0 010-1.415z"
                                                clip-rule="evenodd"
                                            />
                                        </svg>
                                        Heard Disturbance
                                    </div>
                                </div>
                            </div>

                            <!-- Description -->
                            <div class="field">
                                <label class="field__label">Description</label>
                                <p class="review-description">
                                    {{ selectedReport.description }}
                                </p>
                            </div>

                            <!-- Severity -->
                            <div class="field">
                                <label class="field__label">Severity</label>
                                <div>
                                    <span
                                        class="type-badge"
                                        :class="
                                            severityBadge(
                                                selectedReport.severity,
                                            )
                                        "
                                    >
                                        {{ selectedReport.severity }}
                                    </span>
                                </div>
                            </div>

                            <!-- Review notes -->
                            <div class="field">
                                <label class="field__label">Review Notes</label>
                                <textarea
                                    v-model="reviewNotes"
                                    class="field__input field__textarea"
                                    rows="3"
                                    placeholder="Add notes about this report…"
                                ></textarea>
                            </div>

                            <!-- Actions -->
                            <div class="modal-actions">
                                <button
                                    type="button"
                                    class="btn-ghost"
                                    @click="closeModal"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    class="btn-danger"
                                    :disabled="reviewingId !== null"
                                    @click="submitReview('escalate')"
                                >
                                    Escalate to Incident
                                </button>
                                <button
                                    type="button"
                                    class="btn-primary"
                                    :disabled="reviewingId !== null"
                                    @click="submitReview('review')"
                                >
                                    <svg
                                        v-if="reviewingId"
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
                                        reviewingId
                                            ? 'Saving…'
                                            : 'Mark Reviewed'
                                    }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>
    </AppLayout>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap');

.page-root,
.modal-backdrop {
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
.btn-secondary:hover {
    border-color: #ea580c;
    color: #ea580c;
    background: #fff7ed;
}

/* STAT ROW */
.stat-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
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
.search-input-row--standalone {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 14px;
    border-radius: 10px;
    background: #f8fafc;
    border: 1.5px solid #e4e8ef;
    transition: border-color 0.15s;
    max-width: 420px;
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

.filter-groups {
    display: flex;
    flex-wrap: wrap;
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

/* FILTER BAR / CHIPS */
.filter-bar__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.filter-bar__count {
    font-size: 12px;
    font-weight: 500;
    color: #94a3b8;
    white-space: nowrap;
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
.data-table tbody tr:hover {
    background: #fafbfc;
}
.data-table td {
    padding: 13px 16px;
    vertical-align: middle;
}

.alert-id {
    font-family: ui-monospace, SFMono-Regular, monospace;
    font-size: 11px;
    font-weight: 700;
    color: #1a2332;
}
.flag-badge {
    display: inline-block;
    border-radius: 20px;
    padding: 2px 8px;
    font-size: 10px;
    font-weight: 700;
    white-space: nowrap;
}
.flag-badge--danger {
    background: #fef2f2;
    color: #dc2626;
}
.flag-badge--warn {
    background: #fffbeb;
    color: #b45309;
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
.reporter-cell__sub {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 1px;
}

.td-announce {
    max-width: 280px;
}
.td-announce__sub {
    font-size: 12px;
    color: #64748b;
    line-height: 1.5;
}
.td-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.td-time {
    color: #94a3b8;
    white-space: nowrap;
    font-size: 12px;
    line-height: 1.5;
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
.row-action-btn--primary {
    background: #ea580c;
    border-color: #ea580c;
    color: #fff;
}
.row-action-btn--primary:hover {
    background: #c2410c;
    border-color: #c2410c;
    color: #fff;
}

/* PAGINATION */
.pagination-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-top: 1px solid #e4e8ef;
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
.btn-ghost:hover {
    background: #e2e8f0;
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
.modal-sheet__body {
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 18px;
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
.flag-panel svg {
    color: #cbd5e1;
    flex-shrink: 0;
}
.flag-panel--danger {
    border-color: #fca5a5;
    background: #fef2f2;
    color: #dc2626;
}
.flag-panel--danger svg {
    color: #dc2626;
}
.flag-panel--warn {
    border-color: #fcd34d;
    background: #fffbeb;
    color: #b45309;
}
.flag-panel--warn svg {
    color: #f59e0b;
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
.field__textarea {
    resize: vertical;
    min-height: 80px;
    line-height: 1.6;
}

/* TOGGLE ROW (reused for flag panels) */
.toggle-row {
    display: flex;
    gap: 8px;
}

/* MODAL ACTIONS */
.modal-actions {
    display: flex;
    gap: 10px;
    padding-top: 4px;
    flex-wrap: wrap;
}
.modal-actions .btn-ghost {
    flex: 1;
    justify-content: center;
    min-width: 90px;
}
.modal-actions .btn-danger {
    flex: 1.4;
    justify-content: center;
    min-width: 140px;
}
.modal-actions .btn-primary {
    flex: 1.4;
    justify-content: center;
    min-width: 140px;
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
@media (max-width: 768px) {
    .stat-row {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }
    .filter-groups {
        gap: 14px;
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
        min-width: 720px;
    }
    .table-card {
        overflow-x: auto;
    }
    .search-input-row--standalone {
        max-width: none;
    }
}
</style>

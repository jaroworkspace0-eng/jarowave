<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { onMounted, ref } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [];

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
const flash = ref<{ msg: string; type: 'success' | 'error' } | null>(null);

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
    dateError.value = '';
    return true;
}

async function loadReports(url?: string) {
    if (!validateDates()) return;
    loading.value = true;
    try {
        const { data } = await axios.get(
            url ||
                `${import.meta.env.VITE_APP_URL}/api/estate/incident-reports`,
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
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/estate/incident-reports/${report.id}`,
            getHeaders(),
        );
        selectedReport.value = data;
    } catch {
        showFlash('Failed to load report detail.', 'error');
    } finally {
        detailLoading.value = false;
    }
}
function closeDetail() {
    showDetail.value = false;
    selectedReport.value = null;
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
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Safety</div>
                    <h1 class="page-header__title">Incident Reports</h1>
                    <p class="page-header__sub">
                        SOS incident reports logged by your guards
                    </p>
                </div>
            </div>

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
                            placeholder="Search by household name…"
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
                <table v-else class="data-table">
                    <thead>
                        <tr>
                            <th>Household</th>
                            <th>Guard</th>
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
                                        <div class="reporter-cell__name">
                                            {{ report.household?.name ?? '—' }}
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
                            </td>
                            <td>
                                <span
                                    class="type-badge"
                                    :class="outcomeConfig[report.outcome]?.cls"
                                >
                                    {{
                                        outcomeConfig[report.outcome]?.label ??
                                        report.outcome
                                    }}
                                </span>
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
                                >
                                    {{
                                        statusConfig[report.status]?.label ??
                                        report.status
                                    }}
                                </span>
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

        <!-- DETAIL MODAL (read-only, no action buttons) -->
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
                                    Incident Report #{{ selectedReport?.id }}
                                </div>
                                <div class="modal-sheet__sub">
                                    {{
                                        fmtDateTime(selectedReport?.created_at)
                                    }}
                                </div>
                            </div>
                            <button class="close-btn" @click="closeDetail">
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

                        <div v-if="detailLoading" class="empty-state">
                            <span class="text-sm text-slate-400">Loading…</span>
                        </div>

                        <div v-else class="modal-sheet__body">
                            <div class="toggle-row">
                                <span
                                    class="type-badge"
                                    :class="
                                        outcomeConfig[selectedReport?.outcome]
                                            ?.cls
                                    "
                                    >{{
                                        outcomeConfig[selectedReport?.outcome]
                                            ?.label
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

                            <div class="toggle-row">
                                <div class="review-info-panel">
                                    <div class="field__label">Household</div>
                                    <div class="review-info-panel__name">
                                        {{ selectedReport?.household?.name }}
                                    </div>
                                    <div
                                        class="review-info-panel__sub"
                                        v-if="
                                            selectedReport?.household
                                                ?.unit_number
                                        "
                                    >
                                        Unit
                                        {{
                                            selectedReport.household.unit_number
                                        }}
                                    </div>
                                    <div class="review-info-panel__sub">
                                        {{ selectedReport?.household?.email }}
                                    </div>
                                    <div class="review-info-panel__sub">
                                        {{ selectedReport?.household?.phone }}
                                    </div>
                                </div>
                                <div class="review-info-panel">
                                    <div class="field__label">Guard</div>
                                    <div class="review-info-panel__name">
                                        {{ selectedReport?.reporter?.name }}
                                    </div>
                                    <div class="review-info-panel__sub">
                                        {{ selectedReport?.reporter?.email }}
                                    </div>
                                    <div class="review-info-panel__sub">
                                        {{ selectedReport?.reporter?.phone }}
                                    </div>
                                </div>
                            </div>

                            <div class="review-info-panel">
                                <div class="field__label">
                                    Response Timeline
                                </div>
                                <div class="detail-grid">
                                    <div>
                                        <div class="field__label">
                                            Alert Sent
                                        </div>
                                        <div class="detail-grid__value">
                                            {{
                                                fmtDateTime(
                                                    selectedReport?.alert
                                                        ?.created_at,
                                                )
                                            }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="field__label">
                                            Guard Accepted
                                        </div>
                                        <div class="detail-grid__value">
                                            {{
                                                fmtDateTime(
                                                    selectedReport?.resolution
                                                        ?.accepted_at,
                                                )
                                            }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="field__label">
                                            System Arrival
                                        </div>
                                        <div class="detail-grid__value">
                                            {{
                                                fmtDateTime(
                                                    selectedReport?.resolution
                                                        ?.arrival_time,
                                                )
                                            }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="field__label">
                                            System Resolved
                                        </div>
                                        <div class="detail-grid__value">
                                            {{
                                                fmtDateTime(
                                                    selectedReport?.resolution
                                                        ?.resolution_time,
                                                )
                                            }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="field__label">
                                            Response Duration
                                        </div>
                                        <div class="detail-grid__value">
                                            {{
                                                selectedReport?.resolution
                                                    ?.response_duration ?? '—'
                                            }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="field__label">
                                            Distance Traveled
                                        </div>
                                        <div class="detail-grid__value">
                                            {{
                                                selectedReport?.resolution
                                                    ?.distance_traveled ?? '—'
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
                                        <div class="field__label">Injuries</div>
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
                                    >Guard's Account</label
                                >
                                <p class="review-description">
                                    {{ selectedReport?.narrative }}
                                </p>
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
                                v-if="selectedReport?.actioned_by"
                                class="review-info-panel"
                            >
                                <div class="field__label">Admin Action</div>
                                <p class="review-info-panel__name">
                                    By {{ selectedReport.actioned_by?.name }} on
                                    {{
                                        fmtDateTime(selectedReport.actioned_at)
                                    }}
                                </p>
                                <p
                                    v-if="selectedReport.admin_notes"
                                    class="review-info-panel__sub"
                                >
                                    {{ selectedReport.admin_notes }}
                                </p>
                            </div>
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
.btn-secondary--compact {
    padding: 8px 14px;
    font-size: 12px;
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

.toggle-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
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

@media (max-width: 640px) {
    .page-root {
        padding: 16px;
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
</style>

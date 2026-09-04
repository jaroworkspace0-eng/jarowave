<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import {
    Banknote,
    Bell,
    Building2,
    Download,
    Eye,
    History,
    RefreshCw,
    X,
} from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';

const auth = useAuthStore();

onMounted(() => {
    if (auth.user?.role !== 'admin') {
        router.visit('/dashboard');
    }
});

// ── Types ─────────────────────────────────────────────────────────────────
interface BankDetails {
    bank_name: string;
    account_holder: string;
    account_number: string;
    account_type: string;
    branch_code: string;
}
interface Client {
    client_id: number;
    name: string;
    email: string;
    organisation: string;
    pending_amount: number;
    paid_amount: number;
    total_amount: number;
    earning_count: number;
    pending_count: number;
    earliest_period: string | null;
    latest_period: string | null;
    has_bank_details: boolean;
    bank_details: BankDetails | null;
}
interface Totals {
    total_pending: number;
    total_paid: number;
    total_clients: number;
    clients_no_bank: number;
}
interface Earning {
    id: number;
    period_start: string | null;
    period_end: string | null;
    amount: number;
    status: string;
}
interface HistoryClient {
    organisation: string;
    amount: number;
}
interface HistoryEntry {
    eft_reference: string;
    processed_at: string;
    client_count: number;
    total_amount: number;
    clients: HistoryClient[];
}
interface ProcessError {
    client_id: number;
    name: string;
    message: string;
}

// ── State ─────────────────────────────────────────────────────────────────
const allClients = ref<Client[]>([]);
const totals = ref<Totals | null>(null);
const isLoading = ref(true);
const isProcessing = ref(false);
const flash = ref<{ msg: string; type: 'success' | 'error' } | null>(null);

// Period filters (drive the API fetch)
const filterMonth = ref(new Date().getMonth() + 1);
const filterYear = ref(new Date().getFullYear());

// Client-side filters (instant, no refetch)
const quickFilter = ref<'all' | 'ready' | 'no_bank'>('all');
const search = ref('');

// Pagination
const currentPage = ref(1);
const perPage = 15;

// Selection
const selectedClients = ref<Set<number>>(new Set());

// Process modal
const showProcessModal = ref(false);
const eftReference = ref('');
const generatedPayoutRef = ref('');
const copiedRef = ref(false);
const copiedAccount = ref<number | null>(null);
const processErrors = ref<ProcessError[]>([]);

// Earnings detail modal
const showDetailModal = ref(false);
const detailClient = ref<Client | null>(null);
const detailEarnings = ref<Earning[]>([]);
const isDetailLoading = ref(false);

// History modal
const showHistoryModal = ref(false);
const historyEntries = ref<HistoryEntry[]>([]);
const isHistoryLoading = ref(false);
const expandedHistoryRef = ref<string | null>(null);

// Export
const isExporting = ref(false);

// Notify loading per client
const notifying = ref<number | null>(null);

// ── Helpers ───────────────────────────────────────────────────────────────
const getHeaders = () => ({
    headers: { Authorization: `Bearer ${localStorage.getItem('token')}` },
});

function showFlash(msg: string, type: 'success' | 'error' = 'success') {
    flash.value = { msg, type };
    setTimeout(() => (flash.value = null), 6000);
}

const fmt = (val: number | null | undefined) =>
    val != null
        ? `R${Number(val).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
        : '—';

function fmtDate(d: string | null) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-ZA', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}
function fmtDateTime(d: string | null) {
    if (!d) return '—';
    return new Date(d).toLocaleString('en-ZA', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const months = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];
const years = computed(() => {
    const y = new Date().getFullYear();
    return [y - 1, y, y + 1];
});

function generatePayoutRef() {
    const now = new Date();
    const yyyy = now.getFullYear();
    const mm = String(now.getMonth() + 1).padStart(2, '0');
    const seq = String(Math.floor(Math.random() * 900) + 100);
    return `PAY-${yyyy}-${mm}-${seq}`;
}

async function copyToClipboard(text: string, cb: () => void) {
    try {
        await navigator.clipboard.writeText(text);
        cb();
    } catch {
        const el = document.createElement('textarea');
        el.value = text;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
        cb();
    }
}
function copyPayoutRef() {
    copyToClipboard(generatedPayoutRef.value, () => {
        copiedRef.value = true;
        setTimeout(() => (copiedRef.value = false), 2000);
    });
}
function copyAccountNumber(clientId: number, accountNumber: string) {
    copyToClipboard(accountNumber, () => {
        copiedAccount.value = clientId;
        setTimeout(() => (copiedAccount.value = null), 2000);
    });
}

function isEligible(c: Client) {
    return c.has_bank_details && c.pending_count > 0;
}

function bankStatus(c: Client) {
    if (!c.has_bank_details) {
        return { label: 'Missing Bank Details', cls: 'bg-red-50 text-red-600' };
    }
    if (c.pending_count === 0) {
        return {
            label: 'No Pending Earnings',
            cls: 'bg-slate-100 text-slate-500',
        };
    }
    return { label: 'Ready to Pay', cls: 'bg-emerald-50 text-emerald-700' };
}

// ── Computed ──────────────────────────────────────────────────────────────
const filterOptions = [
    { value: 'all', label: 'All Clients' },
    { value: 'ready', label: 'Ready to Pay' },
    { value: 'no_bank', label: 'Missing Bank Details' },
] as const;

const filtered = computed(() => {
    let result = [...allClients.value];

    if (quickFilter.value === 'ready') {
        result = result.filter((c) => isEligible(c));
    } else if (quickFilter.value === 'no_bank') {
        result = result.filter((c) => !c.has_bank_details);
    }

    if (search.value.trim()) {
        const q = search.value.toLowerCase();
        result = result.filter(
            (c) =>
                c.organisation?.toLowerCase().includes(q) ||
                c.name?.toLowerCase().includes(q) ||
                c.email?.toLowerCase().includes(q),
        );
    }

    return result;
});

const totalPages = computed(() =>
    Math.max(1, Math.ceil(filtered.value.length / perPage)),
);
const paginated = computed(() => {
    const start = (currentPage.value - 1) * perPage;
    return filtered.value.slice(start, start + perPage);
});

watch([quickFilter, search], () => {
    currentPage.value = 1;
});

const eligibleInView = computed(() =>
    filtered.value.filter((c) => isEligible(c)),
);

const selectedList = computed(() =>
    allClients.value.filter((c) => selectedClients.value.has(c.client_id)),
);
const selectedTotal = computed(() =>
    selectedList.value.reduce((sum, c) => sum + Number(c.pending_amount), 0),
);
const selectedEarningCount = computed(() =>
    selectedList.value.reduce((sum, c) => sum + c.pending_count, 0),
);
const allEligibleSelected = computed(
    () =>
        eligibleInView.value.length > 0 &&
        eligibleInView.value.every((c) =>
            selectedClients.value.has(c.client_id),
        ),
);

// ── Data ──────────────────────────────────────────────────────────────────
async function fetchClients(resetSelection = true) {
    isLoading.value = true;
    if (resetSelection) selectedClients.value = new Set();
    try {
        const params: Record<string, any> = {
            status: 'all', // always pull the full list — filtering happens client-side
            month: filterMonth.value,
            year: filterYear.value,
        };
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/admin/payouts/clients`,
            { ...getHeaders(), params },
        );
        allClients.value = data.clients;
        totals.value = data.totals;
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to load clients.',
            'error',
        );
    } finally {
        isLoading.value = false;
    }
}
onMounted(() => fetchClients());

// ── Selection ─────────────────────────────────────────────────────────────
function toggleClient(clientId: number) {
    const s = new Set(selectedClients.value);
    s.has(clientId) ? s.delete(clientId) : s.add(clientId);
    selectedClients.value = s;
}
function toggleSelectAll() {
    if (allEligibleSelected.value) {
        const s = new Set(selectedClients.value);
        eligibleInView.value.forEach((c) => s.delete(c.client_id));
        selectedClients.value = s;
    } else {
        const s = new Set(selectedClients.value);
        eligibleInView.value.forEach((c) => s.add(c.client_id));
        selectedClients.value = s;
    }
}

// ── Earnings detail ───────────────────────────────────────────────────────
async function viewEarnings(client: Client) {
    detailClient.value = client;
    detailEarnings.value = [];
    showDetailModal.value = true;
    isDetailLoading.value = true;
    try {
        const params = {
            status: 'all',
            month: filterMonth.value,
            year: filterYear.value,
        };
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/admin/payouts/clients/${client.client_id}/earnings`,
            { ...getHeaders(), params },
        );
        detailEarnings.value = data.earnings;
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to load earnings.',
            'error',
        );
    } finally {
        isDetailLoading.value = false;
    }
}
function closeDetail() {
    showDetailModal.value = false;
    detailClient.value = null;
}

// ── Process ───────────────────────────────────────────────────────────────
function openProcessModal() {
    generatedPayoutRef.value = generatePayoutRef();
    eftReference.value = generatedPayoutRef.value;
    copiedRef.value = false;
    copiedAccount.value = null;
    processErrors.value = [];
    showProcessModal.value = true;
}

async function confirmProcess() {
    if (!eftReference.value.trim() || !selectedList.value.length) return;
    isProcessing.value = true;
    processErrors.value = [];
    const succeeded: number[] = [];

    for (const client of selectedList.value) {
        try {
            const params = {
                status: 'pending',
                month: filterMonth.value,
                year: filterYear.value,
            };
            const { data } = await axios.get(
                `${import.meta.env.VITE_APP_URL}/api/admin/payouts/clients/${client.client_id}/earnings`,
                { ...getHeaders(), params },
            );
            const earningIds = data.earnings
                .filter((e: any) => e.status === 'pending')
                .map((e: any) => e.id);

            if (!earningIds.length) continue;

            await axios.post(
                `${import.meta.env.VITE_APP_URL}/api/admin/payouts/process`,
                {
                    client_id: client.client_id,
                    earning_ids: earningIds,
                    eft_reference: eftReference.value.trim(),
                },
                getHeaders(),
            );
            succeeded.push(client.client_id);
        } catch (err: any) {
            processErrors.value.push({
                client_id: client.client_id,
                name: client.organisation,
                message: err.response?.data?.message ?? 'Failed to process.',
            });
        }
    }

    if (processErrors.value.length === 0) {
        showFlash(
            `Payout processed for ${succeeded.length} client(s). Confirmation emails sent.`,
        );
        showProcessModal.value = false;
        selectedClients.value = new Set();
        await fetchClients();
    } else {
        showFlash(
            `${succeeded.length} processed, ${processErrors.value.length} failed — review below and retry.`,
            'error',
        );
        // Keep the modal open with just the failed clients selected, so the admin can retry.
        selectedClients.value = new Set(
            processErrors.value.map((e) => e.client_id),
        );
        await fetchClients(false);
    }
    isProcessing.value = false;
}

// ── Notify no bank details ────────────────────────────────────────────────
async function notifyNoBankDetails(client: Client) {
    notifying.value = client.client_id;
    try {
        await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/admin/payouts/notify-bank-details`,
            { client_id: client.client_id },
            getHeaders(),
        );
        showFlash(`Notification sent to ${client.email}`);
    } catch {
        showFlash('Failed to send notification.', 'error');
    } finally {
        notifying.value = null;
    }
}

// ── History ───────────────────────────────────────────────────────────────
async function openHistory() {
    showHistoryModal.value = true;
    expandedHistoryRef.value = null;
    isHistoryLoading.value = true;
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/admin/payouts/history`,
            getHeaders(),
        );
        historyEntries.value = data.history;
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to load payout history.',
            'error',
        );
    } finally {
        isHistoryLoading.value = false;
    }
}
function toggleHistoryRow(ref: string) {
    expandedHistoryRef.value = expandedHistoryRef.value === ref ? null : ref;
}

// ── Export ────────────────────────────────────────────────────────────────
async function exportCsv() {
    isExporting.value = true;
    try {
        const params = {
            status: 'ready',
            month: filterMonth.value,
            year: filterYear.value,
        };
        const res = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/admin/payouts/export`,
            { ...getHeaders(), params, responseType: 'blob' },
        );
        const url = URL.createObjectURL(
            new Blob([res.data], { type: 'text/csv' }),
        );
        const a = document.createElement('a');
        a.href = url;
        a.download = `payouts-${filterYear.value}-${String(filterMonth.value).padStart(2, '0')}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    } catch {
        showFlash('Failed to export CSV.', 'error');
    } finally {
        isExporting.value = false;
    }
}
</script>

<template>
    <Head title="Process Payouts" />
    <AppLayout>
        <div class="page-root">
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Finance</div>
                    <h1 class="page-header__title">Process Payouts</h1>
                    <p class="page-header__sub">
                        Review partner earnings and process EFT disbursements ·
                        paid on the 1st of each month
                    </p>
                </div>
                <div class="page-header__actions">
                    <button class="btn-secondary" @click="openHistory">
                        <History :size="14" /> History
                    </button>
                    <button
                        class="btn-secondary"
                        :disabled="isExporting"
                        @click="exportCsv"
                    >
                        <Download :size="14" />
                        {{ isExporting ? 'Exporting…' : 'Export CSV' }}
                    </button>
                    <button class="btn-secondary" @click="fetchClients()">
                        <RefreshCw :size="14" /> Refresh
                    </button>
                </div>
            </div>

            <div class="stats-grid">
                <div
                    class="stat-card stat-card--clickable"
                    :class="{ 'stat-card--active': quickFilter === 'ready' }"
                    @click="quickFilter = 'ready'"
                >
                    <div class="stat-card__value" style="color: #059669">
                        {{ allClients.filter((c) => isEligible(c)).length }}
                    </div>
                    <div class="stat-card__label">Ready to Pay</div>
                </div>
                <div
                    class="stat-card stat-card--clickable stat-card--danger"
                    :class="{ 'stat-card--active': quickFilter === 'no_bank' }"
                    @click="quickFilter = 'no_bank'"
                >
                    <div class="stat-card__value">
                        {{ totals?.clients_no_bank ?? 0 }}
                    </div>
                    <div class="stat-card__label">Missing Bank Details</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__value" style="color: #ea580c">
                        {{ fmt(totals?.total_pending) }}
                    </div>
                    <div class="stat-card__label">Total Pending</div>
                </div>
                <div class="stat-card stat-card--info">
                    <div class="stat-card__value">
                        {{ fmt(totals?.total_paid) }}
                    </div>
                    <div class="stat-card__label">Total Paid Out</div>
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
                            type="text"
                            class="search-input"
                            placeholder="Search client, organisation, email…"
                        />
                        <span
                            v-if="search"
                            class="search-clear"
                            @click="search = ''"
                            >×</span
                        >
                    </div>

                    <div class="date-range">
                        <div class="date-field">
                            <label class="date-field__label">Month</label>
                            <select class="field__input" v-model="filterMonth">
                                <option
                                    v-for="(m, i) in months"
                                    :key="i"
                                    :value="i + 1"
                                >
                                    {{ m }}
                                </option>
                            </select>
                        </div>
                        <div class="date-field">
                            <label class="date-field__label">Year</label>
                            <select class="field__input" v-model="filterYear">
                                <option v-for="y in years" :key="y" :value="y">
                                    {{ y }}
                                </option>
                            </select>
                        </div>
                        <button class="btn-secondary" @click="fetchClients()">
                            Apply
                        </button>
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
                                :class="{
                                    'chip--active': quickFilter === f.value,
                                }"
                                @click="quickFilter = f.value"
                            >
                                {{ f.label }}
                            </button>
                        </div>
                    </div>

                    <div class="selection-summary ml-auto">
                        <span
                            v-if="selectedClients.size > 0"
                            class="filter-count"
                        >
                            {{ selectedClients.size }} selected ·
                            <strong>{{ fmt(selectedTotal) }}</strong> ·
                            {{ selectedEarningCount }} earning{{
                                selectedEarningCount !== 1 ? 's' : ''
                            }}
                        </span>
                        <span v-else class="filter-count">
                            {{ filtered.length }} result{{
                                filtered.length !== 1 ? 's' : ''
                            }}
                        </span>
                        <button
                            v-if="selectedClients.size > 0"
                            class="btn-primary btn-primary--success"
                            @click="openProcessModal"
                        >
                            Process {{ selectedClients.size }} Payout{{
                                selectedClients.size !== 1 ? 's' : ''
                            }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="table-card">
                <div v-if="isLoading" class="empty-state">
                    <span class="text-sm text-slate-400"
                        >Loading payout data…</span
                    >
                </div>
                <div v-else-if="paginated.length === 0" class="empty-state">
                    <p class="empty-state__title">No clients match this view</p>
                    <p class="empty-state__sub">
                        Try adjusting your filters, search, or period.
                    </p>
                </div>
                <table v-else class="data-table">
                    <thead>
                        <tr>
                            <th>
                                <input
                                    type="checkbox"
                                    class="chk"
                                    :checked="allEligibleSelected"
                                    :disabled="eligibleInView.length === 0"
                                    @change="toggleSelectAll"
                                />
                            </th>
                            <th>Partner</th>
                            <th>Period</th>
                            <th>Pending</th>
                            <th>Paid to Date</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="client in paginated"
                            :key="client.client_id"
                            class="clickable-row"
                            @click="viewEarnings(client)"
                        >
                            <td @click.stop>
                                <input
                                    v-if="isEligible(client)"
                                    type="checkbox"
                                    class="chk"
                                    :checked="
                                        selectedClients.has(client.client_id)
                                    "
                                    @change="toggleClient(client.client_id)"
                                />
                                <span v-else class="chk-disabled"></span>
                            </td>
                            <td>
                                <div class="reporter-cell">
                                    <div class="reporter-cell__avatar">
                                        {{
                                            client.organisation
                                                .charAt(0)
                                                .toUpperCase()
                                        }}
                                    </div>
                                    <div>
                                        <div class="reporter-cell__name">
                                            {{ client.organisation }}
                                        </div>
                                        <div class="reporter-cell__sub">
                                            {{ client.email }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="td-time">
                                <template v-if="client.earliest_period">
                                    {{ fmtDate(client.earliest_period) }} →
                                    {{ fmtDate(client.latest_period) }}
                                </template>
                                <template v-else>—</template>
                            </td>
                            <td class="amount-cell">
                                {{ fmt(client.pending_amount) }}
                                <div class="reporter-cell__sub">
                                    {{ client.pending_count }} earning{{
                                        client.pending_count !== 1 ? 's' : ''
                                    }}
                                </div>
                            </td>
                            <td class="td-time">
                                {{ fmt(client.paid_amount) }}
                            </td>
                            <td>
                                <span
                                    class="type-badge"
                                    :class="bankStatus(client).cls"
                                >
                                    {{ bankStatus(client).label }}
                                </span>
                            </td>
                            <td @click.stop>
                                <div class="row-actions">
                                    <button
                                        class="icon-btn"
                                        title="View earnings"
                                        @click="viewEarnings(client)"
                                    >
                                        <Eye :size="15" />
                                    </button>
                                    <button
                                        v-if="!client.has_bank_details"
                                        class="icon-btn icon-btn--danger"
                                        title="Notify client"
                                        :disabled="
                                            notifying === client.client_id
                                        "
                                        @click="notifyNoBankDetails(client)"
                                    >
                                        <Bell :size="15" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="pagination-bar" v-if="!isLoading && totalPages > 1">
                    <span class="pagination-bar__info"
                        >Page {{ currentPage }} of {{ totalPages }}</span
                    >
                    <div class="pagination-bar__pages">
                        <button
                            class="page-btn"
                            :disabled="currentPage === 1"
                            @click="currentPage--"
                        >
                            ← Prev
                        </button>
                        <button
                            class="page-btn"
                            :disabled="currentPage === totalPages"
                            @click="currentPage++"
                        >
                            Next →
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- EARNINGS DETAIL MODAL -->
        <Teleport to="body">
            <transition name="modal">
                <div
                    v-if="showDetailModal"
                    class="modal-backdrop"
                    @click.self="closeDetail"
                >
                    <div class="modal-sheet">
                        <div class="modal-sheet__header">
                            <div>
                                <div class="modal-sheet__title">
                                    <Building2
                                        :size="15"
                                        style="
                                            display: inline;
                                            vertical-align: -2px;
                                            margin-right: 4px;
                                        "
                                    />
                                    {{ detailClient?.organisation }}
                                </div>
                                <div class="modal-sheet__sub">
                                    {{ detailClient?.email }}
                                </div>
                            </div>
                            <button class="close-btn" @click="closeDetail">
                                <X :size="16" />
                            </button>
                        </div>

                        <div class="modal-sheet__body">
                            <div class="toggle-row" v-if="detailClient">
                                <div class="review-info-panel">
                                    <div class="field__label">Pending</div>
                                    <div class="review-info-panel__name">
                                        {{ fmt(detailClient.pending_amount) }}
                                    </div>
                                </div>
                                <div class="review-info-panel">
                                    <div class="field__label">Paid to Date</div>
                                    <div class="review-info-panel__name">
                                        {{ fmt(detailClient.paid_amount) }}
                                    </div>
                                </div>
                                <div class="review-info-panel">
                                    <div class="field__label">Bank Details</div>
                                    <div
                                        v-if="detailClient.has_bank_details"
                                        class="review-info-panel__name"
                                        style="font-size: 13px"
                                    >
                                        {{
                                            detailClient.bank_details?.bank_name
                                        }}
                                        ···{{
                                            detailClient.bank_details?.account_number.slice(
                                                -4,
                                            )
                                        }}
                                    </div>
                                    <div
                                        v-else
                                        class="review-info-panel__sub"
                                        style="color: #dc2626; font-weight: 700"
                                    >
                                        Not on file
                                    </div>
                                </div>
                            </div>

                            <div class="field">
                                <label class="field__label"
                                    >Earnings this period</label
                                >
                                <div
                                    v-if="isDetailLoading"
                                    class="empty-state"
                                    style="padding: 32px"
                                >
                                    <span class="text-sm text-slate-400"
                                        >Loading earnings…</span
                                    >
                                </div>
                                <div
                                    v-else-if="!detailEarnings.length"
                                    class="empty-state"
                                    style="padding: 32px"
                                >
                                    <span class="text-sm text-slate-400"
                                        >No earnings recorded for this
                                        period.</span
                                    >
                                </div>
                                <table
                                    v-else
                                    class="data-table"
                                    style="margin-top: 4px"
                                >
                                    <thead>
                                        <tr>
                                            <th>Period</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="e in detailEarnings"
                                            :key="e.id"
                                        >
                                            <td class="td-time">
                                                {{ fmtDate(e.period_start) }} →
                                                {{ fmtDate(e.period_end) }}
                                            </td>
                                            <td class="amount-cell">
                                                {{ fmt(e.amount) }}
                                            </td>
                                            <td>
                                                <span
                                                    class="type-badge"
                                                    :class="
                                                        e.status === 'paid'
                                                            ? 'bg-emerald-50 text-emerald-700'
                                                            : 'bg-orange-50 text-orange-700'
                                                    "
                                                >
                                                    {{
                                                        e.status === 'paid'
                                                            ? 'Paid'
                                                            : 'Pending'
                                                    }}
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="modal-actions">
                                <button
                                    v-if="
                                        detailClient && isEligible(detailClient)
                                    "
                                    class="btn-primary btn-primary--success"
                                    @click="
                                        toggleClient(detailClient.client_id);
                                        closeDetail();
                                    "
                                >
                                    {{
                                        selectedClients.has(
                                            detailClient.client_id,
                                        )
                                            ? 'Remove from selection'
                                            : 'Add to selection'
                                    }}
                                </button>
                                <button
                                    v-if="
                                        detailClient &&
                                        !detailClient.has_bank_details
                                    "
                                    class="btn-secondary btn-secondary--danger"
                                    :disabled="
                                        notifying === detailClient.client_id
                                    "
                                    @click="notifyNoBankDetails(detailClient)"
                                >
                                    <Bell :size="14" /> Notify Client
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>

        <!-- PROCESS MODAL -->
        <Teleport to="body">
            <transition name="modal">
                <div
                    v-if="showProcessModal"
                    class="modal-backdrop"
                    @click.self="showProcessModal = false"
                >
                    <div class="modal-sheet">
                        <div class="modal-sheet__header">
                            <div>
                                <div class="modal-sheet__title">
                                    Confirm Payouts
                                </div>
                                <div class="modal-sheet__sub">
                                    {{ selectedList.length }} client{{
                                        selectedList.length !== 1 ? 's' : ''
                                    }}
                                    · {{ fmt(selectedTotal) }}
                                </div>
                            </div>
                            <button
                                class="close-btn"
                                @click="showProcessModal = false"
                            >
                                <X :size="16" />
                            </button>
                        </div>

                        <div class="modal-sheet__body">
                            <p
                                class="review-description"
                                style="font-style: normal"
                            >
                                Use the reference below as the Beneficiary
                                Reference when making each transfer in your
                                banking app.
                            </p>

                            <div class="payout-ref-banner">
                                <div class="prb-top">
                                    <span class="prb-label"
                                        >Payout Reference</span
                                    >
                                    <span class="prb-hint"
                                        >Used as the Beneficiary Reference for
                                        every transfer</span
                                    >
                                </div>
                                <div class="prb-row">
                                    <span class="prb-value">{{
                                        generatedPayoutRef
                                    }}</span>
                                    <button
                                        class="btn-secondary"
                                        @click="copyPayoutRef"
                                    >
                                        {{ copiedRef ? 'Copied!' : 'Copy' }}
                                    </button>
                                </div>
                            </div>

                            <div
                                v-if="processErrors.length"
                                class="review-description"
                                style="
                                    border-color: #fecaca;
                                    background: #fef2f2;
                                    color: #b91c1c;
                                    font-style: normal;
                                "
                            >
                                <strong
                                    >{{ processErrors.length }} client(s) failed
                                    to process:</strong
                                >
                                <div
                                    v-for="e in processErrors"
                                    :key="e.client_id"
                                >
                                    {{ e.name }} — {{ e.message }}
                                </div>
                                Only the failed clients remain selected below.
                                Fix the issue and try again.
                            </div>

                            <div class="field" style="gap: 8px">
                                <label class="field__label"
                                    >Clients in this batch</label
                                >
                                <div
                                    v-for="c in selectedList"
                                    :key="c.client_id"
                                    class="review-info-panel"
                                    style="
                                        flex-direction: row;
                                        align-items: center;
                                        justify-content: space-between;
                                        gap: 12px;
                                    "
                                >
                                    <div>
                                        <div class="review-info-panel__name">
                                            {{ c.organisation }}
                                        </div>
                                        <div class="review-info-panel__sub">
                                            {{ c.bank_details?.bank_name }} ·
                                            {{ c.bank_details?.account_number }}
                                            ·
                                            {{ c.bank_details?.account_type }} ·
                                            Branch
                                            {{ c.bank_details?.branch_code }}
                                            <button
                                                class="icon-btn"
                                                style="
                                                    display: inline-flex;
                                                    padding: 2px;
                                                "
                                                title="Copy account number"
                                                @click.stop="
                                                    copyAccountNumber(
                                                        c.client_id,
                                                        c.bank_details
                                                            ?.account_number ??
                                                            '',
                                                    )
                                                "
                                            >
                                                <Banknote :size="12" />
                                                {{
                                                    copiedAccount ===
                                                    c.client_id
                                                        ? 'Copied!'
                                                        : ''
                                                }}
                                            </button>
                                        </div>
                                    </div>
                                    <div class="amount-cell">
                                        {{ fmt(c.pending_amount) }}
                                    </div>
                                </div>
                            </div>

                            <div class="field">
                                <label class="field__label"
                                    >Beneficiary Reference</label
                                >
                                <input
                                    class="text-field"
                                    type="text"
                                    v-model="eftReference"
                                    placeholder="e.g. PAY-2026-06-001"
                                />
                            </div>

                            <div class="modal-actions">
                                <button
                                    class="btn-secondary"
                                    @click="showProcessModal = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    class="btn-primary btn-primary--success"
                                    :disabled="
                                        isProcessing || !eftReference.trim()
                                    "
                                    @click="confirmProcess"
                                >
                                    {{
                                        isProcessing
                                            ? 'Processing…'
                                            : 'Confirm & Mark Paid'
                                    }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>

        <!-- HISTORY MODAL -->
        <Teleport to="body">
            <transition name="modal">
                <div
                    v-if="showHistoryModal"
                    class="modal-backdrop"
                    @click.self="showHistoryModal = false"
                >
                    <div class="modal-sheet modal-sheet--lg">
                        <div class="modal-sheet__header">
                            <div class="modal-sheet__title">Payout History</div>
                            <button
                                class="close-btn"
                                @click="showHistoryModal = false"
                            >
                                <X :size="16" />
                            </button>
                        </div>
                        <div class="modal-sheet__body">
                            <div v-if="isHistoryLoading" class="empty-state">
                                <span class="text-sm text-slate-400"
                                    >Loading history…</span
                                >
                            </div>
                            <div
                                v-else-if="!historyEntries.length"
                                class="empty-state"
                            >
                                <p class="empty-state__title">
                                    No payouts processed yet
                                </p>
                            </div>
                            <div
                                v-else
                                style="
                                    display: flex;
                                    flex-direction: column;
                                    gap: 10px;
                                "
                            >
                                <div
                                    v-for="h in historyEntries"
                                    :key="h.eft_reference"
                                    class="review-info-panel"
                                    style="cursor: pointer"
                                    @click="toggleHistoryRow(h.eft_reference)"
                                >
                                    <div
                                        class="toggle-row"
                                        style="align-items: center"
                                    >
                                        <div>
                                            <div
                                                class="review-info-panel__name mono"
                                            >
                                                {{ h.eft_reference }}
                                            </div>
                                            <div class="review-info-panel__sub">
                                                {{
                                                    fmtDateTime(h.processed_at)
                                                }}
                                                · {{ h.client_count }} client{{
                                                    h.client_count !== 1
                                                        ? 's'
                                                        : ''
                                                }}
                                            </div>
                                        </div>
                                        <span class="amount-pill">{{
                                            fmt(h.total_amount)
                                        }}</span>
                                    </div>
                                    <div
                                        v-if="
                                            expandedHistoryRef ===
                                            h.eft_reference
                                        "
                                        style="
                                            margin-top: 10px;
                                            border-top: 1px dashed #e4e8ef;
                                            padding-top: 10px;
                                        "
                                    >
                                        <div
                                            v-for="c in h.clients"
                                            :key="c.organisation"
                                            class="toggle-row"
                                            style="
                                                justify-content: space-between;
                                                padding: 2px 0;
                                            "
                                        >
                                            <span
                                                style="
                                                    font-size: 12.5px;
                                                    color: #475569;
                                                "
                                                >{{ c.organisation }}</span
                                            >
                                            <span
                                                style="
                                                    font-size: 12.5px;
                                                    font-weight: 700;
                                                "
                                                >{{ fmt(c.amount) }}</span
                                            >
                                        </div>
                                    </div>
                                </div>
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
.page-header__actions {
    display: flex;
    gap: 8px;
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
.btn-secondary:disabled {
    opacity: 0.5;
    cursor: default;
}

.btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border: none;
    border-radius: 12px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 700;
    color: #fff;
    cursor: pointer;
    font-family: inherit;
    white-space: nowrap;
}
.btn-primary--success {
    background: #059669;
}
.btn-primary--success:hover:not(:disabled) {
    background: #047857;
}
.btn-primary--danger {
    background: #dc2626;
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
.stat-card--clickable {
    cursor: pointer;
    transition: border-color 0.15s;
}
.stat-card--clickable:hover {
    border-color: #ea580c;
}
.stat-card--active {
    border-color: #ea580c;
    background: #fff7ed;
}
.stat-card__value {
    font-size: 22px;
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
.stat-card--info .stat-card__value {
    color: #2563eb;
    font-size: 19px;
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
    align-items: flex-end;
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
.field__input {
    padding: 7px 10px;
    border-radius: 8px;
    border: 1.5px solid #e4e8ef;
    font-size: 12px;
    font-family: inherit;
    color: #1a2332;
    background: #f8fafc;
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
.ml-auto {
    margin-left: auto;
}
.selection-summary {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
.filter-count {
    font-size: 12px;
    color: #94a3b8;
    font-weight: 600;
    white-space: nowrap;
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
.mono {
    font-family: 'Courier New', monospace;
}
.amount-cell {
    font-weight: 800;
    color: #ea580c;
    white-space: nowrap;
}

.row-actions {
    display: flex;
    gap: 2px;
}
.icon-btn {
    padding: 7px;
    border-radius: 8px;
    border: none;
    background: transparent;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: #64748b;
    font-family: inherit;
    font-size: 11px;
}
.icon-btn:hover:not(:disabled) {
    background: #f1f5f9;
}
.icon-btn:disabled {
    opacity: 0.35;
    cursor: default;
}
.icon-btn--danger {
    color: #dc2626;
}
.icon-btn--danger:hover:not(:disabled) {
    background: #fef2f2;
}

.chk {
    width: 15px;
    height: 15px;
    accent-color: #ea580c;
    cursor: pointer;
}
.chk-disabled {
    display: inline-block;
    width: 15px;
    height: 15px;
    border: 1.5px solid #e2e8f0;
    border-radius: 4px;
    background: #f8fafc;
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
    gap: 8px;
}
.page-btn {
    padding: 6px 14px;
    border: 1px solid #e4e8ef;
    border-radius: 8px;
    background: #fff;
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    cursor: pointer;
}
.page-btn:hover:not(:disabled) {
    border-color: #ea580c;
    color: #ea580c;
}
.page-btn:disabled {
    opacity: 0.4;
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
.modal-sheet--lg {
    max-width: 780px;
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
    align-items: center;
}
.toggle-row .review-info-panel {
    flex: 1;
    min-width: 200px;
}
.amount-pill {
    font-size: 16px;
    font-weight: 800;
    color: #ea580c;
    margin-left: auto;
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

.text-field {
    padding: 10px 14px;
    border: 1.5px solid #e4e8ef;
    border-radius: 10px;
    font-size: 14px;
    font-family: inherit;
    color: #1a2332;
    outline: none;
}
.text-field:focus {
    border-color: #ea580c;
    box-shadow: 0 0 0 3px rgba(234, 88, 12, 0.1);
}

.modal-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    padding-top: 4px;
}

.payout-ref-banner {
    background: #fff7ed;
    border: 1.5px solid #fed7aa;
    border-radius: 12px;
    padding: 14px 16px;
}
.prb-top {
    display: flex;
    align-items: baseline;
    gap: 8px;
    margin-bottom: 8px;
    flex-wrap: wrap;
}
.prb-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #c2410c;
}
.prb-hint {
    font-size: 11px;
    color: #9a3412;
    opacity: 0.75;
}
.prb-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.prb-value {
    font-size: 17px;
    font-weight: 800;
    color: #c2410c;
    letter-spacing: 0.5px;
    font-family: 'Courier New', monospace;
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

@media (max-width: 900px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
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
    .filter-card__top {
        flex-direction: column;
        align-items: stretch;
    }
}
</style>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import Chart from 'chart.js/auto';
import {
    AlertTriangle,
    CheckCircle,
    Clock,
    FileText,
    TrendingUp,
    Users,
    Wallet,
    X,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

const auth = useAuthStore();

onMounted(() => {
    if (auth.user?.role !== 'admin') {
        router.visit('/dashboard');
    }
});

const getHeaders = () => ({
    headers: { Authorization: `Bearer ${localStorage.getItem('token')}` },
});
const apiUrl = (path: string) => `${import.meta.env.VITE_APP_URL}${path}`;

const flash = ref<{ msg: string; type: 'success' | 'error' } | null>(null);
function showFlash(msg: string, type: 'success' | 'error' = 'success') {
    flash.value = { msg, type };
    setTimeout(() => (flash.value = null), 5000);
}

const fmtAmount = (val: number | null | undefined) =>
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

/* ---------------- Tabs ---------------- */
const tabs = [
    { key: 'overview', label: 'Overview' },
    { key: 'transactions', label: 'Transactions' },
    { key: 'payfast', label: 'Payfast vs EFT' },
    { key: 'projections', label: 'Projections' },
    { key: 'lifetime', label: 'Lifetime' },
] as const;
type TabKey = (typeof tabs)[number]['key'];
const activeTab = ref<TabKey>('overview');

/* ---------------- Date range ---------------- */
const preset = ref<'30d' | 'thisMonth' | '6m' | '12m' | 'custom'>('30d');
const dateFrom = ref('');
const dateTo = ref('');

function applyPreset() {
    const now = new Date();
    const toDate = now.toISOString().slice(0, 10);
    let fromDate: string;

    if (preset.value === '30d') {
        fromDate = new Date(new Date().setDate(now.getDate() - 30))
            .toISOString()
            .slice(0, 10);
    } else if (preset.value === 'thisMonth') {
        fromDate = new Date(now.getFullYear(), now.getMonth(), 1)
            .toISOString()
            .slice(0, 10);
    } else if (preset.value === '6m') {
        fromDate = new Date(new Date().setMonth(now.getMonth() - 6))
            .toISOString()
            .slice(0, 10);
    } else if (preset.value === '12m') {
        fromDate = new Date(new Date().setMonth(now.getMonth() - 12))
            .toISOString()
            .slice(0, 10);
    } else {
        return;
    }

    dateFrom.value = fromDate;
    dateTo.value = toDate;
    reloadAll();
}

function onCustomDateChange() {
    preset.value = 'custom';
    reloadAll();
}

function rangeParams() {
    return { from: dateFrom.value || undefined, to: dateTo.value || undefined };
}

/* ---------------- Overview ---------------- */
interface OverviewData {
    mrr: number;
    revenue_in_range: number;
    active_subscriptions: number;
    past_due: number;
    monthly_series: { month: string; total: number }[];
}
const overview = ref<OverviewData | null>(null);
const loadingOverview = ref(false);
const revenueChartEl = ref<HTMLCanvasElement | null>(null);
let revenueChart: Chart | null = null;

async function loadOverview() {
    loadingOverview.value = true;
    try {
        const res = await axios.get(apiUrl('/api/admin/finance/overview'), {
            ...getHeaders(),
            params: rangeParams(),
        });
        overview.value = res.data;
        // Flip the loading flag BEFORE drawing: the canvas only exists in the
        // DOM once loadingOverview is false (v-else-if="overview" branch).
        loadingOverview.value = false;
        await nextTick();
        drawRevenueChart();
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to load overview.',
            'error',
        );
        loadingOverview.value = false;
    }
}

function drawRevenueChart() {
    if (!revenueChartEl.value || !overview.value) return;
    revenueChart?.destroy();
    const points = overview.value.monthly_series.length;
    revenueChart = new Chart(revenueChartEl.value, {
        type: 'line',
        data: {
            labels: overview.value.monthly_series.map((r) => r.month),
            datasets: [
                {
                    data: overview.value.monthly_series.map((r) => r.total),
                    borderColor: '#ea580c',
                    backgroundColor: 'rgba(234,88,12,0.08)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: points > 1 ? 0 : 5,
                    pointBackgroundColor: '#ea580c',
                    borderWidth: 2,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    beginAtZero: points <= 1,
                    ticks: { callback: (v) => 'R' + v },
                },
            },
        },
    });
}

/* ---------------- Transactions ---------------- */
interface LinkedAccount {
    id: number;
    name: string;
}

interface AccountLinkInfo {
    is_primary: boolean | null;
    linked_accounts?: { id: number; name: string; status: string }[];
    primary_name?: string;
    primary_id?: number;
    status?: string;
}
interface LinkedAccount {
    id: number;
    name: string;
    account_link?: AccountLinkInfo | null;
}

interface TransactionRow {
    id: number;
    source: 'individual' | 'estate';
    household_name: string | null;
    linked_accounts?: LinkedAccount[];
    proof_of_payment_url?: string | null;
    amount: number;
    status: string;
    payment_method: string | null;
    paid_at: string | null;
    created_at: string;
    account_link?: AccountLinkInfo | null;
}
const transactions = ref<TransactionRow[]>([]);
const txMeta = ref({ current_page: 1, last_page: 1 });
const loadingTx = ref(false);

const txStatus = ref<'all' | 'paid' | 'pending_review' | 'failed'>('all');
const txMethod = ref<'all' | 'payfast' | 'eft'>('all');
const txSource = ref<'all' | 'individual' | 'estate'>('all');
const txSearch = ref('');

const txStatusOptions = [
    { value: 'all', label: 'All' },
    { value: 'paid', label: 'Paid' },
    { value: 'pending_review', label: 'Pending' },
    { value: 'failed', label: 'Failed' },
] as const;

async function loadTransactions(page = 1) {
    loadingTx.value = true;
    try {
        const res = await axios.get(apiUrl('/api/admin/finance/transactions'), {
            ...getHeaders(),
            params: {
                ...rangeParams(),
                page,
                status: txStatus.value === 'all' ? undefined : txStatus.value,
                payment_method:
                    txMethod.value === 'all' ? undefined : txMethod.value,
                source: txSource.value === 'all' ? undefined : txSource.value,
            },
        });
        transactions.value = res.data.data;
        txMeta.value = {
            current_page: res.data.current_page,
            last_page: res.data.last_page,
        };
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to load transactions.',
            'error',
        );
    } finally {
        loadingTx.value = false;
    }
}

const filteredTransactions = computed(() => {
    if (!txSearch.value.trim()) return transactions.value;
    const q = txSearch.value.toLowerCase();
    return transactions.value.filter(
        (t) =>
            (t.payment_method ?? '').toLowerCase().includes(q) ||
            t.status.toLowerCase().includes(q) ||
            (t.household_name ?? '').toLowerCase().includes(q) ||
            (t.linked_accounts ?? []).some((l) =>
                l.name.toLowerCase().includes(q),
            ),
    );
});

watch([txStatus, txMethod, txSource], () => loadTransactions(1));

function statusConfig(status: string) {
    switch (status) {
        case 'pending_review':
            return {
                label: 'Pending Review',
                cls: 'bg-orange-50 text-orange-700',
                icon: Clock,
            };
        case 'paid':
        case 'complete':
            return {
                label: 'Paid',
                cls: 'bg-emerald-50 text-emerald-700',
                icon: CheckCircle,
            };
        case 'failed':
        case 'rejected':
            return {
                label: 'Failed',
                cls: 'bg-red-50 text-red-600',
                icon: AlertTriangle,
            };
        default:
            return {
                label: status,
                cls: 'bg-slate-100 text-slate-500',
                icon: Clock,
            };
    }
}

/* ---------------- Payfast vs EFT ---------------- */
interface SplitRow {
    payment_method: string | null;
    total: number;
    count: number;
}
interface PendingEft {
    id: number;
    channel_subscription_id: number;
    household_name: string | null;
    amount: number;
    created_at: string;
    proof_of_payment_url: string | null;
}
const split = ref<SplitRow[]>([]);
const pendingEft = ref<PendingEft[]>([]);
const loadingPayfast = ref(false);
const splitChartEl = ref<HTMLCanvasElement | null>(null);
let splitChart: Chart | null = null;
const processingEftId = ref<number | null>(null);

async function loadPayfast() {
    loadingPayfast.value = true;
    try {
        const res = await axios.get(
            apiUrl('/api/admin/finance/payfast-vs-eft'),
            {
                ...getHeaders(),
                params: rangeParams(),
            },
        );
        split.value = res.data.split;
        pendingEft.value = res.data.pending_eft_review;
        loadingPayfast.value = false;
        await nextTick();
        drawSplitChart();
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to load payment split.',
            'error',
        );
        loadingPayfast.value = false;
    }
}

function drawSplitChart() {
    if (!splitChartEl.value || split.value.length === 0) return;
    splitChart?.destroy();
    splitChart = new Chart(splitChartEl.value, {
        type: 'doughnut',
        data: {
            labels: split.value.map((r) =>
                (r.payment_method ?? 'Unknown').toUpperCase(),
            ),
            datasets: [
                {
                    data: split.value.map((r) => r.total),
                    backgroundColor: ['#ea580c', '#2563eb', '#059669'],
                    borderWidth: 0,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
        },
    });
}

async function approveEft(row: PendingEft) {
    processingEftId.value = row.id;
    try {
        await axios.post(
            apiUrl(`/api/admin/channel-payments/${row.id}/approve`),
            {},
            getHeaders(),
        );
        showFlash('EFT approved. Opted-in households activated.');
        await loadPayfast();
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to approve EFT.',
            'error',
        );
    } finally {
        processingEftId.value = null;
    }
}

/* ---------------- Proof of payment preview ---------------- */
const proofPreviewUrl = ref<string | null>(null);
const proofZoomed = ref(false);
function openProof(url: string | null | undefined) {
    if (!url) return;
    proofZoomed.value = false;
    proofPreviewUrl.value = url;
}
function closeProof() {
    proofPreviewUrl.value = null;
    proofZoomed.value = false;
}
function toggleProofZoom() {
    proofZoomed.value = !proofZoomed.value;
}
function isImageProof(url: string) {
    return /\.(png|jpe?g|gif|webp)$/i.test(url);
}

/* ---------------- Projections ---------------- */
interface ProjectionData {
    growth_rate: number;
    next_month: number;
    six_month: number;
    annualized: number;
    series: { month: string; projected: number }[];
}
const projections = ref<ProjectionData | null>(null);
const loadingProjections = ref(false);
const projChartEl = ref<HTMLCanvasElement | null>(null);
let projChart: Chart | null = null;

async function loadProjections() {
    loadingProjections.value = true;
    try {
        const res = await axios.get(apiUrl('/api/admin/finance/projections'), {
            ...getHeaders(),
            params: rangeParams(),
        });
        projections.value = res.data;
        loadingProjections.value = false;
        await nextTick();
        drawProjChart();
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to load projections.',
            'error',
        );
        loadingProjections.value = false;
    }
}

function drawProjChart() {
    if (!projChartEl.value || !projections.value) return;
    projChart?.destroy();
    projChart = new Chart(projChartEl.value, {
        type: 'line',
        data: {
            labels: projections.value.series.map((r) => r.month),
            datasets: [
                {
                    data: projections.value.series.map((r) => r.projected),
                    borderColor: '#2563eb',
                    borderDash: [6, 4],
                    tension: 0.35,
                    pointRadius: 0,
                    borderWidth: 2,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { ticks: { callback: (v) => 'R' + v } } },
        },
    });
}

/* ---------------- Lifetime ---------------- */
interface LifetimeData {
    total_revenue: number;
    individual_revenue: number;
    estate_revenue: number;
    total_paid_transactions: number;
    first_payment_at: string | null;
    monthly_series: { month: string; total: number }[];
}
const lifetime = ref<LifetimeData | null>(null);
const loadingLifetime = ref(false);
const lifetimeChartEl = ref<HTMLCanvasElement | null>(null);
let lifetimeChart: Chart | null = null;

async function loadLifetime() {
    loadingLifetime.value = true;
    try {
        const res = await axios.get(apiUrl('/api/admin/finance/lifetime'), {
            ...getHeaders(),
        });
        lifetime.value = res.data;
        loadingLifetime.value = false;
        await nextTick();
        drawLifetimeChart();
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to load lifetime revenue.',
            'error',
        );
        loadingLifetime.value = false;
    }
}

function drawLifetimeChart() {
    if (!lifetimeChartEl.value || !lifetime.value) return;
    lifetimeChart?.destroy();
    lifetimeChart = new Chart(lifetimeChartEl.value, {
        type: 'line',
        data: {
            labels: lifetime.value.monthly_series.map((r) => r.month),
            datasets: [
                {
                    data: lifetime.value.monthly_series.map((r) => r.total),
                    borderColor: '#059669',
                    backgroundColor: 'rgba(5,150,105,0.08)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 0,
                    borderWidth: 2,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { ticks: { callback: (v) => 'R' + v } },
            },
        },
    });
}

/* ---------------- Orchestration ---------------- */
function reloadAll() {
    loadOverview();
    loadTransactions(1);
    loadPayfast();
    loadProjections();
    loadLifetime();
}

// FIX: previously this only reloaded a tab's data if that data array was
// still empty — but reloadAll() on mount already fires all four loads in
// parallel, so by the time you clicked into "Payfast" or "Projections" the
// data was already populated and the chart draw call never ran (the canvas
// for that tab doesn't exist in the DOM until the tab is active, and
// switching tabs destroys/recreates the <canvas> element via v-if, which
// orphans the old Chart.js instance). Now every tab activation either loads
// fresh data (which draws once loaded) or, if data is already there,
// redraws straight onto the newly-mounted canvas.
watch(activeTab, async (tab) => {
    await nextTick();
    if (tab === 'overview') {
        if (!overview.value) loadOverview();
        else drawRevenueChart();
    } else if (tab === 'transactions') {
        if (transactions.value.length === 0) loadTransactions(1);
    } else if (tab === 'payfast') {
        if (split.value.length === 0) loadPayfast();
        else drawSplitChart();
    } else if (tab === 'projections') {
        if (!projections.value) loadProjections();
        else drawProjChart();
    } else if (tab === 'lifetime') {
        if (!lifetime.value) loadLifetime();
        else drawLifetimeChart();
    }
});

onMounted(() => {
    applyPreset();
});
</script>

<template>
    <Head title="Finance" />
    <AppLayout>
        <div class="page-root">
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Finance</div>
                    <h1 class="page-header__title">Finance Dashboard</h1>
                    <p class="page-header__sub">
                        Revenue, transactions, and projections across all
                        channels
                    </p>
                </div>
                <button class="btn-secondary" @click="reloadAll">
                    ↻ Refresh
                </button>
            </div>

            <!-- Tab bar -->
            <div class="tab-bar">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    class="tab-bar__item"
                    :class="{ 'tab-bar__item--active': activeTab === tab.key }"
                    @click="activeTab = tab.key"
                >
                    {{ tab.label }}
                </button>
            </div>

            <!-- Date range -->
            <div class="filter-card">
                <div class="filter-card__top">
                    <div class="filter-group">
                        <span class="filter-group__label">Range</span>
                        <div class="filter-bar__chips">
                            <button
                                v-for="opt in [
                                    { v: '30d', l: '30 Days' },
                                    { v: 'thisMonth', l: 'This Month' },
                                    { v: '6m', l: '6 Months' },
                                    { v: '12m', l: '12 Months' },
                                ]"
                                :key="opt.v"
                                class="chip"
                                :class="{ 'chip--active': preset === opt.v }"
                                @click="
                                    preset = opt.v as any;
                                    applyPreset();
                                "
                            >
                                {{ opt.l }}
                            </button>
                        </div>
                    </div>

                    <div class="date-range">
                        <div class="date-field">
                            <label class="date-field__label">From</label>
                            <input
                                v-model="dateFrom"
                                type="date"
                                class="field__input field__input--date"
                                @change="onCustomDateChange"
                            />
                        </div>
                        <div class="date-field">
                            <label class="date-field__label">To</label>
                            <input
                                v-model="dateTo"
                                type="date"
                                class="field__input field__input--date"
                                @change="onCustomDateChange"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===================== OVERVIEW ===================== -->
            <template v-if="activeTab === 'overview'">
                <div v-if="loadingOverview" class="empty-state">
                    <span class="text-sm text-slate-400">Loading…</span>
                </div>
                <template v-else-if="overview">
                    <div class="stats-grid">
                        <div class="stat-card stat-card--info">
                            <div class="stat-card__value">
                                {{ fmtAmount(overview.mrr) }}
                            </div>
                            <div class="stat-card__label">
                                <Wallet
                                    :size="11"
                                    style="
                                        display: inline;
                                        vertical-align: -1px;
                                        margin-right: 3px;
                                    "
                                />MRR
                            </div>
                        </div>
                        <div class="stat-card stat-card--success">
                            <div class="stat-card__value">
                                {{ fmtAmount(overview.revenue_in_range) }}
                            </div>
                            <div class="stat-card__label">
                                <TrendingUp
                                    :size="11"
                                    style="
                                        display: inline;
                                        vertical-align: -1px;
                                        margin-right: 3px;
                                    "
                                />Revenue in Range
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-card__value">
                                {{ overview.active_subscriptions }}
                            </div>
                            <div class="stat-card__label">
                                <Users
                                    :size="11"
                                    style="
                                        display: inline;
                                        vertical-align: -1px;
                                        margin-right: 3px;
                                    "
                                />Active Subscriptions
                            </div>
                        </div>
                        <div class="stat-card stat-card--danger">
                            <div class="stat-card__value">
                                {{ overview.past_due }}
                            </div>
                            <div class="stat-card__label">
                                <AlertTriangle
                                    :size="11"
                                    style="
                                        display: inline;
                                        vertical-align: -1px;
                                        margin-right: 3px;
                                    "
                                />Past Due
                            </div>
                        </div>
                    </div>

                    <div class="table-card chart-card">
                        <div
                            v-if="overview.monthly_series.length === 0"
                            class="empty-state"
                        >
                            <p class="empty-state__title">
                                No revenue recorded for this range
                            </p>
                            <p class="empty-state__sub">
                                Try a wider range, or confirm payments in this
                                window are marked paid/complete
                            </p>
                        </div>
                        <div v-else class="chart-card__inner">
                            <canvas ref="revenueChartEl"></canvas>
                        </div>
                    </div>
                </template>
            </template>

            <!-- ===================== TRANSACTIONS ===================== -->
            <template v-if="activeTab === 'transactions'">
                <div class="filter-card">
                    <div class="filter-card__top">
                        <div class="search-input-row--standalone">
                            <input
                                v-model="txSearch"
                                type="text"
                                class="search-input"
                                placeholder="Search name, method, status…"
                            />
                            <span
                                v-if="txSearch"
                                class="search-clear"
                                @click="txSearch = ''"
                                >×</span
                            >
                        </div>
                    </div>
                    <div class="filter-groups">
                        <div class="filter-group">
                            <span class="filter-group__label">Status</span>
                            <div class="filter-bar__chips">
                                <button
                                    v-for="f in txStatusOptions"
                                    :key="f.value"
                                    class="chip"
                                    :class="{
                                        'chip--active': txStatus === f.value,
                                    }"
                                    @click="txStatus = f.value as any"
                                >
                                    {{ f.label }}
                                </button>
                            </div>
                        </div>
                        <div class="filter-group">
                            <span class="filter-group__label">Method</span>
                            <div class="filter-bar__chips">
                                <button
                                    v-for="f in [
                                        { v: 'all', l: 'All' },
                                        { v: 'payfast', l: 'PayFast' },
                                        { v: 'eft', l: 'EFT' },
                                    ]"
                                    :key="f.v"
                                    class="chip"
                                    :class="{
                                        'chip--active': txMethod === f.v,
                                    }"
                                    @click="txMethod = f.v as any"
                                >
                                    {{ f.l }}
                                </button>
                            </div>
                        </div>
                        <div class="filter-group">
                            <span class="filter-group__label">Source</span>
                            <div class="filter-bar__chips">
                                <button
                                    v-for="f in [
                                        { v: 'all', l: 'All' },
                                        { v: 'individual', l: 'Individual' },
                                        { v: 'estate', l: 'Estate' },
                                    ]"
                                    :key="f.v"
                                    class="chip"
                                    :class="{
                                        'chip--active': txSource === f.v,
                                    }"
                                    @click="txSource = f.v as any"
                                >
                                    {{ f.l }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="table-card">
                    <div v-if="loadingTx" class="empty-state">
                        <span class="text-sm text-slate-400">Loading…</span>
                    </div>
                    <div
                        v-else-if="filteredTransactions.length === 0"
                        class="empty-state"
                    >
                        <p class="empty-state__title">No transactions found</p>
                        <p class="empty-state__sub">
                            Try adjusting your filters or date range
                        </p>
                    </div>
                    <table v-else class="data-table">
                        <thead>
                            <tr>
                                <th>Household</th>
                                <th>Source</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th>Paid At</th>
                                <th>Amount</th>
                                <th>Proof</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in filteredTransactions"
                                :key="row.source + '-' + row.id"
                            >
                                <td class="household-cell">
                                    <div class="household-cell__name">
                                        {{ row.household_name ?? '—' }}
                                        <span
                                            v-if="row.account_link?.is_primary"
                                            class="primary-badge"
                                            >Primary</span
                                        >
                                    </div>

                                    <!-- Thread: this account is primary, show its linked accounts -->
                                    <div
                                        v-if="
                                            row.account_link?.is_primary &&
                                            row.account_link.linked_accounts
                                                ?.length
                                        "
                                        class="thread"
                                    >
                                        <div
                                            v-for="acc in row.account_link
                                                .linked_accounts"
                                            :key="acc.id"
                                            class="thread__branch"
                                        >
                                            <span class="thread__line"></span>
                                            {{ acc.name }}
                                        </div>
                                    </div>

                                    <!-- Thread: this account is linked, show who it's under -->
                                    <div
                                        v-else-if="
                                            row.account_link?.is_primary ===
                                            false
                                        "
                                        class="thread thread--up"
                                    >
                                        <span
                                            class="thread__line thread__line--up"
                                        ></span>
                                        linked to
                                        <strong>{{
                                            row.account_link.primary_name
                                        }}</strong>
                                    </div>
                                </td>
                                <td
                                    class="td-time"
                                    style="text-transform: capitalize"
                                >
                                    {{ row.source }}
                                </td>
                                <td class="td-time mono">
                                    {{
                                        (
                                            row.payment_method ?? '—'
                                        ).toUpperCase()
                                    }}
                                </td>
                                <td>
                                    <span
                                        class="type-badge"
                                        :class="statusConfig(row.status).cls"
                                    >
                                        {{ statusConfig(row.status).label }}
                                    </span>
                                </td>
                                <td class="td-time">
                                    {{ fmtDate(row.paid_at) }}
                                </td>
                                <td class="amount-cell">
                                    {{ fmtAmount(row.amount) }}
                                </td>
                                <td>
                                    <button
                                        v-if="
                                            row.payment_method === 'eft' &&
                                            row.proof_of_payment_url
                                        "
                                        class="proof-link"
                                        @click="
                                            openProof(row.proof_of_payment_url)
                                        "
                                    >
                                        <FileText :size="13" />
                                        View
                                    </button>
                                    <span
                                        v-else-if="row.payment_method === 'eft'"
                                        class="proof-missing"
                                        >Not uploaded</span
                                    >
                                    <span v-else class="proof-missing">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div
                        class="pagination-bar"
                        v-if="!loadingTx && txMeta.last_page > 1"
                    >
                        <span class="pagination-bar__info"
                            >Page {{ txMeta.current_page }} of
                            {{ txMeta.last_page }}</span
                        >
                        <div class="pagination-bar__pages">
                            <button
                                class="page-btn"
                                :disabled="txMeta.current_page === 1"
                                @click="
                                    loadTransactions(txMeta.current_page - 1)
                                "
                            >
                                ← Prev
                            </button>
                            <button
                                class="page-btn"
                                :disabled="
                                    txMeta.current_page === txMeta.last_page
                                "
                                @click="
                                    loadTransactions(txMeta.current_page + 1)
                                "
                            >
                                Next →
                            </button>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ===================== PAYFAST VS EFT ===================== -->
            <template v-if="activeTab === 'payfast'">
                <div v-if="loadingPayfast" class="empty-state">
                    <span class="text-sm text-slate-400">Loading…</span>
                </div>
                <template v-else>
                    <div
                        v-if="split.length === 0"
                        class="table-card empty-state"
                    >
                        <p class="empty-state__title">
                            No payment data for this range
                        </p>
                        <p class="empty-state__sub">
                            Try widening the date range — nothing paid or
                            completed in the selected period
                        </p>
                    </div>
                    <div v-else class="split-grid">
                        <div class="table-card chart-card">
                            <div
                                class="chart-card__inner chart-card__inner--donut"
                            >
                                <canvas ref="splitChartEl"></canvas>
                            </div>
                        </div>
                        <div class="table-card split-legend">
                            <div
                                v-for="row in split"
                                :key="row.payment_method ?? 'unknown'"
                                class="split-legend__row"
                            >
                                <span class="split-legend__label">{{
                                    (
                                        row.payment_method ?? 'Unknown'
                                    ).toUpperCase()
                                }}</span>
                                <span class="split-legend__value">{{
                                    fmtAmount(row.total)
                                }}</span>
                                <span class="split-legend__count"
                                    >{{ row.count }} txns</span
                                >
                            </div>
                        </div>
                    </div>

                    <div class="table-card" style="margin-top: 20px">
                        <div
                            class="empty-state"
                            v-if="pendingEft.length === 0"
                            style="padding: 32px"
                        >
                            <p class="empty-state__title">
                                No EFT proofs pending review
                            </p>
                        </div>
                        <table v-else class="data-table">
                            <thead>
                                <tr>
                                    <th>Household</th>
                                    <th>Channel Subscription</th>
                                    <th>Amount</th>
                                    <th>Submitted</th>
                                    <th>Proof</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in pendingEft" :key="row.id">
                                    <td class="td-time">
                                        {{ row.household_name ?? '—' }}
                                    </td>
                                    <td class="mono td-time">
                                        #{{ row.channel_subscription_id }}
                                    </td>
                                    <td class="amount-cell">
                                        {{ fmtAmount(row.amount) }}
                                    </td>
                                    <td class="td-time">
                                        {{ fmtDate(row.created_at) }}
                                    </td>
                                    <td>
                                        <button
                                            v-if="row.proof_of_payment_url"
                                            class="proof-link"
                                            @click="
                                                openProof(
                                                    row.proof_of_payment_url,
                                                )
                                            "
                                        >
                                            <FileText :size="13" />
                                            View proof
                                        </button>
                                        <span v-else class="proof-missing"
                                            >Not uploaded</span
                                        >
                                    </td>
                                    <td>
                                        <div class="row-actions">
                                            <button
                                                class="icon-btn icon-btn--success"
                                                title="Approve"
                                                :disabled="
                                                    processingEftId === row.id
                                                "
                                                @click="approveEft(row)"
                                            >
                                                <CheckCircle :size="15" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </template>

            <!-- ===================== PROJECTIONS ===================== -->
            <template v-if="activeTab === 'projections'">
                <div v-if="loadingProjections" class="empty-state">
                    <span class="text-sm text-slate-400">Loading…</span>
                </div>
                <template v-else-if="projections">
                    <div class="table-card chart-card">
                        <div class="chart-card__inner">
                            <canvas ref="projChartEl"></canvas>
                        </div>
                    </div>

                    <div
                        class="stats-grid"
                        style="
                            margin-top: 20px;
                            grid-template-columns: repeat(3, 1fr);
                        "
                    >
                        <div class="stat-card stat-card--info">
                            <div class="stat-card__value">
                                {{ fmtAmount(projections.next_month) }}
                            </div>
                            <div class="stat-card__label">Next Month</div>
                        </div>
                        <div class="stat-card stat-card--info">
                            <div class="stat-card__value">
                                {{ fmtAmount(projections.six_month) }}
                            </div>
                            <div class="stat-card__label">6 Months</div>
                        </div>
                        <div class="stat-card stat-card--info">
                            <div class="stat-card__value">
                                {{ fmtAmount(projections.annualized) }}
                            </div>
                            <div class="stat-card__label">Annualized</div>
                        </div>
                    </div>
                </template>
            </template>

            <!-- ===================== LIFETIME ===================== -->
            <template v-if="activeTab === 'lifetime'">
                <div v-if="loadingLifetime" class="empty-state">
                    <span class="text-sm text-slate-400">Loading…</span>
                </div>
                <template v-else-if="lifetime">
                    <div class="stats-grid">
                        <div class="stat-card stat-card--success">
                            <div class="stat-card__value">
                                {{ fmtAmount(lifetime.total_revenue) }}
                            </div>
                            <div class="stat-card__label">
                                <TrendingUp
                                    :size="11"
                                    style="
                                        display: inline;
                                        vertical-align: -1px;
                                        margin-right: 3px;
                                    "
                                />Total Revenue (all time)
                            </div>
                        </div>
                        <div class="stat-card stat-card--info">
                            <div class="stat-card__value">
                                {{ fmtAmount(lifetime.individual_revenue) }}
                            </div>
                            <div class="stat-card__label">Individual</div>
                        </div>
                        <div class="stat-card stat-card--info">
                            <div class="stat-card__value">
                                {{ fmtAmount(lifetime.estate_revenue) }}
                            </div>
                            <div class="stat-card__label">Estate</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-card__value">
                                {{ lifetime.total_paid_transactions }}
                            </div>
                            <div class="stat-card__label">
                                Paid Transactions
                            </div>
                        </div>
                    </div>

                    <p
                        v-if="lifetime.first_payment_at"
                        style="
                            font-size: 12px;
                            color: #94a3b8;
                            margin: -8px 0 0;
                        "
                    >
                        Since first payment on
                        {{ fmtDate(lifetime.first_payment_at) }}
                    </p>

                    <div class="table-card chart-card">
                        <div
                            v-if="lifetime.monthly_series.length === 0"
                            class="empty-state"
                        >
                            <p class="empty-state__title">
                                No paid revenue on record yet
                            </p>
                        </div>
                        <div v-else class="chart-card__inner">
                            <canvas ref="lifetimeChartEl"></canvas>
                        </div>
                    </div>
                </template>
            </template>
        </div>

        <!-- Proof of payment preview modal -->
        <Teleport to="body">
            <transition name="modal">
                <div
                    v-if="proofPreviewUrl"
                    class="proof-modal-backdrop"
                    @click.self="closeProof"
                >
                    <div class="proof-modal">
                        <div class="proof-modal__header">
                            <span>Proof of Payment</span>
                            <div
                                style="
                                    display: flex;
                                    align-items: center;
                                    gap: 4px;
                                "
                            >
                                <button
                                    v-if="isImageProof(proofPreviewUrl)"
                                    class="icon-btn"
                                    :title="
                                        proofZoomed ? 'Zoom out' : 'Zoom in'
                                    "
                                    @click="toggleProofZoom"
                                >
                                    {{ proofZoomed ? '−' : '+' }}
                                </button>
                                <a
                                    :href="proofPreviewUrl"
                                    target="_blank"
                                    rel="noopener"
                                    class="icon-btn"
                                    title="Open in new tab"
                                    >⤢</a
                                >
                                <button class="icon-btn" @click="closeProof">
                                    <X :size="16" />
                                </button>
                            </div>
                        </div>
                        <div
                            class="proof-modal__body"
                            :class="{
                                'proof-modal__body--zoomed': proofZoomed,
                            }"
                        >
                            <img
                                v-if="isImageProof(proofPreviewUrl)"
                                :src="proofPreviewUrl"
                                alt="Proof of payment"
                                :class="{ 'is-zoomed': proofZoomed }"
                                @click="toggleProofZoom"
                            />
                            <a
                                v-else
                                :href="proofPreviewUrl"
                                target="_blank"
                                rel="noopener"
                                class="proof-modal__filelink"
                            >
                                <FileText :size="18" />
                                Open document
                            </a>
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
.toast,
.proof-modal-backdrop {
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

.tab-bar {
    display: flex;
    gap: 4px;
    border-bottom: 1.5px solid #e4e8ef;
}
.tab-bar__item {
    padding: 10px 16px;
    font-size: 13px;
    font-weight: 700;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    color: #94a3b8;
    cursor: pointer;
    font-family: inherit;
    white-space: nowrap;
}
.tab-bar__item:hover {
    color: #ea580c;
}
.tab-bar__item--active {
    color: #ea580c;
    border-bottom-color: #ea580c;
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
.stat-card--success .stat-card__value {
    color: #059669;
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
.field__input--date {
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

.table-card {
    background: #fff;
    border: 1px solid #e4e8ef;
    border-radius: 16px;
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}
.chart-card__inner {
    padding: 20px;
    height: 280px;
    position: relative;
}
.chart-card__inner--donut {
    height: 240px;
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

.household-cell {
    min-width: 160px;
}
.household-cell__name {
    font-weight: 700;
    color: #1a2332;
    font-size: 13px;
}
.household-cell__linked {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    color: #94a3b8;
    margin-top: 2px;
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

.proof-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #fff7ed;
    color: #ea580c;
    border: 1px solid #fed7aa;
    border-radius: 8px;
    padding: 5px 10px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    font-family: inherit;
}
.proof-link:hover {
    background: #ffedd5;
}
.proof-missing {
    font-size: 12px;
    color: #cbd5e1;
    font-style: italic;
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
    color: #64748b;
}
.icon-btn:hover:not(:disabled) {
    background: #f1f5f9;
}
.icon-btn:disabled {
    opacity: 0.35;
    cursor: default;
}
.icon-btn--success {
    color: #059669;
}
.icon-btn--success:hover:not(:disabled) {
    background: #ecfdf5;
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

.split-grid {
    display: grid;
    grid-template-columns: 1.2fr 1fr;
    gap: 16px;
}
.split-legend {
    padding: 20px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 14px;
}
.split-legend__row {
    display: flex;
    align-items: baseline;
    gap: 10px;
    padding-bottom: 10px;
    border-bottom: 1px dashed #e4e8ef;
}
.split-legend__label {
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    width: 80px;
}
.split-legend__value {
    font-size: 16px;
    font-weight: 800;
    color: #1a2332;
}
.split-legend__count {
    font-size: 11px;
    color: #94a3b8;
    margin-left: auto;
}

.proof-modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 10001;
    background: rgba(15, 23, 42, 0.55);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
}
.proof-modal {
    background: #fff;
    border-radius: 16px;
    box-shadow: var(--shadow-lg);
    width: min(92vw, 1100px);
    max-height: 92vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.proof-modal__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    border-bottom: 1px solid #e4e8ef;
    font-size: 13px;
    font-weight: 700;
    color: #1a2332;
}
.proof-modal__body {
    padding: 18px;
    overflow: auto;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 60vh;
}
.proof-modal__body img {
    max-width: 100%;
    max-height: 84vh;
    border-radius: 10px;
    display: block;
    cursor: zoom-in;
    transition: transform 0.15s ease;
}
.proof-modal__body img.is-zoomed {
    max-width: none;
    max-height: none;
    width: auto;
    height: auto;
    transform: scale(2);
    transform-origin: center;
    cursor: zoom-out;
}
.proof-modal__body--zoomed {
    align-items: flex-start;
    justify-content: flex-start;
    cursor: zoom-out;
}
.proof-modal__filelink {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 10px;
    background: #fff7ed;
    color: #ea580c;
    border: 1px solid #fed7aa;
    font-weight: 700;
    font-size: 13px;
    text-decoration: none;
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
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}

@media (max-width: 900px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .split-grid {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 640px) {
    .page-root {
        padding: 16px;
    }
    .data-table {
        min-width: 700px;
    }
    .table-card {
        overflow-x: auto;
    }
    .filter-card__top {
        flex-direction: column;
        align-items: stretch;
    }
}

.primary-badge {
    font-size: 9px;
    font-weight: 700;
    color: #ea580c;
    background: #fff7ed;
    border: 1px solid #fed7aa;
    border-radius: 10px;
    padding: 1px 6px;
    margin-left: 6px;
    vertical-align: 1px;
}
.thread {
    margin-top: 4px;
    padding-left: 10px;
}
.thread__branch {
    position: relative;
    font-size: 11px;
    color: #94a3b8;
    padding-left: 12px;
    line-height: 18px;
}
.thread__line {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 8px;
    border-left: 1.5px solid #e4e8ef;
    border-bottom: 1.5px solid #e4e8ef;
    border-bottom-left-radius: 4px;
    height: 9px;
}
.thread--up {
    font-size: 11px;
    color: #94a3b8;
    padding-left: 12px;
    position: relative;
}
.thread__line--up {
    position: absolute;
    left: 0;
    top: -2px;
    width: 8px;
    height: 8px;
    border-left: 1.5px solid #e4e8ef;
    border-top: 1.5px solid #e4e8ef;
    border-top-left-radius: 4px;
}
</style>

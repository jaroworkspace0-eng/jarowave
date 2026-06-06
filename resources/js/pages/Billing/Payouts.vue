<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import {
    BadgeCheck,
    Banknote,
    Calendar,
    ChevronLeft,
    ChevronRight,
    CircleDollarSign,
    Download,
    FileWarning,
    Filter,
    Hourglass,
    House,
    HouseIcon,
    Percent,
    TrendingUp,
    X,
} from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';

// ── Types ──────────────────────────────────────────────────────────────────
interface Summary {
    pending_amount: number;
    paid_amount: number;
    withheld_amount: number;
    total_earned: number;
    platform_collected: number;
    pending_count: number;
    paid_count: number;
    total_count: number;
    total_residents: number;
    commission_rate: number;
    alltime_earned: number;
    alltime_paid: number;
    alltime_pending: number;
}

interface Earning {
    id: number;
    household_name: string;
    household_address: string;
    resident_amount: number;
    earned_amount: number;
    platform_amount: number;
    commission_percentage: number;
    status: string;
    period_start: string | null;
    period_end: string | null;
    payout_at: string | null;
    payout_reference: string | null;
    created_at: string | null;
}

interface Payout {
    id: number;
    reference: string | null;
    period_start: string | null;
    period_end: string | null;
    household_count: number;
    gross_amount: number;
    platform_fee: number;
    net_amount: number;
    status: string;
    paid_at: string | null;
    transfer_reference: string | null;
}

interface PaginatedResponse<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
}

// ── State ──────────────────────────────────────────────────────────────────
const summary = ref<Summary | null>(null);
const earnings = ref<PaginatedResponse<Earning> | null>(null);
const payouts = ref<PaginatedResponse<Payout> | null>(null);
const households = ref<any[]>([]);
const bankDetails = ref<any>(null);

const isLoadingInit = ref(true);
const isLoadingEarnings = ref(false);
const isLoadingPayouts = ref(false);
const isSavingBank = ref(false);
const isExporting = ref(false);

const showBankModal = ref(false);
const activeTab = ref<'earnings' | 'payouts'>('earnings');
const flash = ref<{ msg: string; type: 'success' | 'error' } | null>(null);

// ── Filters ────────────────────────────────────────────────────────────────
const now = new Date();

const earningsFilter = reactive({
    month: String(now.getMonth() + 1),
    year: String(now.getFullYear()),
    status: '',
    resident_id: '',
    per_page: 20,
    page: 1,
});

const payoutsFilter = reactive({
    month: String(now.getMonth() + 1),
    year: String(now.getFullYear()),
    status: '',
    per_page: 20,
    page: 1,
});

const bankForm = ref({
    bank_name: '',
    account_holder: '',
    account_number: '',
    account_type: '',
    branch_code: '',
});

// ── Helpers ────────────────────────────────────────────────────────────────
const API = import.meta.env.VITE_APP_URL;

const getHeaders = () => ({
    headers: { Authorization: `Bearer ${localStorage.getItem('token')}` },
});

const showFlash = (msg: string, type: 'success' | 'error' = 'success') => {
    flash.value = { msg, type };
    setTimeout(() => (flash.value = null), 5000);
};

const fmt = (val: number | string | null | undefined) =>
    val != null
        ? `R\u202f${Number(val).toLocaleString('en-ZA', {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
          })}`
        : '—';

const fmtDate = (d: string | null | undefined) =>
    d
        ? new Date(d).toLocaleDateString('en-ZA', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '—';

const MONTHS = [
    { v: '', l: 'All months' },
    { v: '1', l: 'January' },
    { v: '2', l: 'February' },
    { v: '3', l: 'March' },
    { v: '4', l: 'April' },
    { v: '5', l: 'May' },
    { v: '6', l: 'June' },
    { v: '7', l: 'July' },
    { v: '8', l: 'August' },
    { v: '9', l: 'September' },
    { v: '10', l: 'October' },
    { v: '11', l: 'November' },
    { v: '12', l: 'December' },
];

const YEARS = Array.from({ length: 5 }, (_, i) =>
    String(now.getFullYear() - i),
);

const statusColour = (s: string) =>
    ({
        paid: 'green',
        active: 'green',
        pending: 'orange',
        trialing: 'orange',
        failed: 'red',
        withheld: 'red',
        cancelled: 'grey',
    })[s] ?? 'grey';

// ── Computed ───────────────────────────────────────────────────────────────
const nextPayoutDate = computed(() => {
    const next = new Date(now.getFullYear(), now.getMonth() + 1, 1);
    return next.toLocaleDateString('en-ZA', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
});

const commissionRate = computed(() => summary.value?.commission_rate ?? 60);
const perHouseholdEarning = computed(() =>
    ((80 * commissionRate.value) / 100).toFixed(2),
);

const activeHouseholds = computed(() =>
    households.value.filter((h) => h.status === 'active'),
);
const pendingHouseholds = computed(() =>
    households.value.filter((h) => h.status === 'pending'),
);
const failedHouseholds = computed(() =>
    households.value.filter((h) => h.status === 'failed'),
);

const earningsFilterLabel = computed(() => {
    const m = MONTHS.find((x) => x.v === earningsFilter.month)?.l ?? '';
    return [m, earningsFilter.year].filter(Boolean).join(' ');
});

const hasActiveEarningsFilter = computed(
    () =>
        earningsFilter.month !== String(now.getMonth() + 1) ||
        earningsFilter.year !== String(now.getFullYear()) ||
        earningsFilter.status !== '' ||
        earningsFilter.resident_id !== '',
);

// ── Data Fetching ──────────────────────────────────────────────────────────
const buildParams = (f: Record<string, any>) => {
    const p: Record<string, any> = {};
    Object.entries(f).forEach(([k, v]) => {
        if (v !== '') p[k] = v;
    });
    return p;
};

const fetchSummary = async () => {
    const params = buildParams({
        month: earningsFilter.month,
        year: earningsFilter.year,
        status: earningsFilter.status,
        resident_id: earningsFilter.resident_id,
    });
    const { data } = await axios.get(`${API}/api/earnings/summary`, {
        ...getHeaders(),
        params,
    });
    summary.value = data.summary;
};

const fetchEarnings = async () => {
    isLoadingEarnings.value = true;
    try {
        const params = buildParams({ ...earningsFilter });
        const { data } = await axios.get(`${API}/api/earnings`, {
            ...getHeaders(),
            params,
        });
        earnings.value = data.earnings;
    } finally {
        isLoadingEarnings.value = false;
    }
};

const fetchPayouts = async () => {
    isLoadingPayouts.value = true;
    try {
        const params = buildParams({ ...payoutsFilter });
        const { data } = await axios.get(`${API}/api/payouts`, {
            ...getHeaders(),
            params,
        });
        payouts.value = data.payouts;
    } finally {
        isLoadingPayouts.value = false;
    }
};

onMounted(async () => {
    try {
        const [householdsRes, bankRes] = await Promise.all([
            axios.get(`${API}/api/households`, getHeaders()),
            axios.get(`${API}/api/bank-details`, getHeaders()),
        ]);
        households.value = householdsRes.data.households?.data ?? [];
        bankDetails.value = bankRes.data.bank_details ?? null;

        if (bankDetails.value) {
            bankForm.value = {
                bank_name: bankDetails.value.bank_name ?? '',
                account_holder: bankDetails.value.account_holder ?? '',
                account_number: bankDetails.value.account_number ?? '',
                account_type: bankDetails.value.account_type ?? '',
                branch_code: bankDetails.value.branch_code ?? '',
            };
        }

        await Promise.all([fetchSummary(), fetchEarnings(), fetchPayouts()]);
    } catch (err: any) {
        if (err.response?.status === 401) {
            router.visit('/login');
            return;
        }
        console.error('Failed to load payout data', err);
    } finally {
        isLoadingInit.value = false;
    }
});

// ── Filter watchers ────────────────────────────────────────────────────────
watch(
    () => ({ ...earningsFilter }),
    () => {
        earningsFilter.page = 1;
        fetchSummary();
        fetchEarnings();
    },
    { deep: true },
);

watch(
    () => ({ ...payoutsFilter }),
    () => {
        payoutsFilter.page = 1;
        fetchPayouts();
    },
    { deep: true },
);

// Pause deep watcher on page change to avoid double-fetch
const goEarningsPage = (p: number) => {
    earningsFilter.page = p;
    fetchEarnings();
};
const goPayoutsPage = (p: number) => {
    payoutsFilter.page = p;
    fetchPayouts();
};

const resetEarningsFilters = () => {
    Object.assign(earningsFilter, {
        month: String(now.getMonth() + 1),
        year: String(now.getFullYear()),
        status: '',
        resident_id: '',
        page: 1,
    });
};

// ── Export ─────────────────────────────────────────────────────────────────
const exportEarnings = async () => {
    isExporting.value = true;
    try {
        const params = buildParams({
            month: earningsFilter.month,
            year: earningsFilter.year,
            status: earningsFilter.status,
            resident_id: earningsFilter.resident_id,
        });
        const qs = new URLSearchParams(params).toString();
        const token = localStorage.getItem('token');
        const res = await fetch(`${API}/api/earnings/export?${qs}`, {
            headers: { Authorization: `Bearer ${token}` },
        });
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `earnings-${earningsFilter.year}${earningsFilter.month ? '-' + earningsFilter.month.padStart(2, '0') : ''}.csv`;
        a.click();
        URL.revokeObjectURL(url);
    } catch {
        showFlash('Failed to export CSV.', 'error');
    } finally {
        isExporting.value = false;
    }
};

const exportPayouts = async () => {
    isExporting.value = true;
    try {
        const params = buildParams({
            month: payoutsFilter.month,
            year: payoutsFilter.year,
            status: payoutsFilter.status,
        });
        const qs = new URLSearchParams(params).toString();
        const token = localStorage.getItem('token');
        const res = await fetch(`${API}/api/payouts/export?${qs}`, {
            headers: { Authorization: `Bearer ${token}` },
        });
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `payouts-${payoutsFilter.year}.csv`;
        a.click();
        URL.revokeObjectURL(url);
    } catch {
        showFlash('Failed to export CSV.', 'error');
    } finally {
        isExporting.value = false;
    }
};

// ── Save Bank Details ──────────────────────────────────────────────────────
const saveBankDetails = async () => {
    isSavingBank.value = true;
    try {
        const { data } = await axios.post(
            `${API}/api/bank-details`,
            bankForm.value,
            getHeaders(),
        );
        bankDetails.value = data.bank_details;
        showBankModal.value = false;
        showFlash('Bank details saved successfully.');
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to save bank details.',
            'error',
        );
    } finally {
        isSavingBank.value = false;
    }
};
</script>

<template>
    <Head title="Payouts" />
    <AppLayout>
        <div class="po-root">
            <!-- LOADING -->
            <div v-if="isLoadingInit" class="po-loading">
                <div class="spinner"></div>
                <p>Loading payout data…</p>
            </div>

            <template v-else>
                <!-- PAGE HEADER -->
                <div class="po-header">
                    <div>
                        <h1 class="po-title">Payouts</h1>
                        <p class="po-sub">
                            Monthly earnings from active households · disbursed
                            on the 1st
                        </p>
                    </div>
                </div>

                <!-- FLASH -->
                <div v-if="flash" :class="['flash', flash.type]">
                    {{ flash.type === 'success' ? '✓' : '⚠' }} {{ flash.msg }}
                </div>

                <!-- ── HERO BANNER ─────────────────────────────────────── -->
                <div class="hero-banner">
                    <div class="hero-left">
                        <div class="hero-eyebrow">
                            <span class="dot-pulse"></span>
                            Pending Payout
                        </div>
                        <div class="hero-amount">
                            {{
                                summary?.pending_amount
                                    ? fmt(summary.pending_amount)
                                    : 'R\u202f0.00'
                            }}
                        </div>
                        <div class="hero-sub">
                            Disbursed on <strong>{{ nextPayoutDate }}</strong> ·
                            {{ summary?.pending_count ?? 0 }} earning{{
                                summary?.pending_count !== 1 ? 's' : ''
                            }}
                            pending
                        </div>
                        <div class="hero-formula">
                            <span class="formula-pill">R80 / household</span>
                            <span class="formula-op">×</span>
                            <span class="formula-pill accent"
                                >{{ commissionRate }}% your share</span
                            >
                            <span class="formula-op">=</span>
                            <span class="formula-pill green"
                                >R{{ perHouseholdEarning }} each</span
                            >
                        </div>
                    </div>
                    <div class="hero-right">
                        <div class="hero-stat">
                            <div class="hs-val">
                                {{ fmt(summary?.alltime_earned) }}
                            </div>
                            <div class="hs-lbl">Lifetime earned</div>
                        </div>
                        <div class="hero-divider"></div>
                        <div class="hero-stat">
                            <div class="hs-val">
                                {{ fmt(summary?.alltime_paid) }}
                            </div>
                            <div class="hs-lbl">Total paid out</div>
                        </div>
                        <div class="hero-divider"></div>
                        <div class="hero-stat">
                            <div class="hs-val">
                                {{ activeHouseholds.length }}
                            </div>
                            <div class="hs-lbl">Active households</div>
                        </div>
                    </div>
                </div>

                <!-- ── STAT CARDS ──────────────────────────────────────── -->
                <div class="stat-row">
                    <div class="stat-card">
                        <div class="sc-top">
                            <House :size="18" stroke-width="2" /><span
                                class="sc-badge green"
                                >active</span
                            >
                        </div>
                        <div class="sc-val">{{ activeHouseholds.length }}</div>
                        <div class="sc-lbl">Active Households</div>
                    </div>
                    <div class="stat-card">
                        <div class="sc-top">
                            <Hourglass :size="18" stroke-width="2" /><span
                                class="sc-badge orange"
                                >trialing</span
                            >
                        </div>
                        <div class="sc-val">{{ pendingHouseholds.length }}</div>
                        <div class="sc-lbl">Pending Payment</div>
                    </div>
                    <div class="stat-card">
                        <div class="sc-top">
                            <FileWarning :size="18" stroke-width="2" /><span
                                class="sc-badge red"
                                >failed</span
                            >
                        </div>
                        <div class="sc-val">{{ failedHouseholds.length }}</div>
                        <div class="sc-lbl">Failed Payment</div>
                    </div>
                    <div class="stat-card">
                        <div class="sc-top">
                            <Percent :size="18" stroke-width="2" />
                        </div>
                        <div class="sc-val">{{ commissionRate }}%</div>
                        <div class="sc-lbl">Your Commission</div>
                    </div>
                    <div class="stat-card">
                        <div class="sc-top">
                            <TrendingUp :size="18" stroke-width="2" />
                        </div>
                        <div class="sc-val">
                            {{ fmt(summary?.platform_collected) }}
                        </div>
                        <div class="sc-lbl">Platform Fee Collected</div>
                    </div>
                    <div class="stat-card">
                        <div class="sc-top">
                            <Calendar :size="18" stroke-width="2" />
                        </div>
                        <div class="sc-val next-date">{{ nextPayoutDate }}</div>
                        <div class="sc-lbl">Next Payout Date</div>
                    </div>
                </div>

                <!-- ── TABS ────────────────────────────────────────────── -->
                <div class="tabs">
                    <button
                        :class="['tab', { active: activeTab === 'earnings' }]"
                        @click="activeTab = 'earnings'"
                    >
                        <CircleDollarSign :size="14" stroke-width="2" />
                        Earnings
                        <span v-if="earnings?.total" class="tab-count">{{
                            earnings.total
                        }}</span>
                    </button>
                    <button
                        :class="['tab', { active: activeTab === 'payouts' }]"
                        @click="activeTab = 'payouts'"
                    >
                        <Banknote :size="14" stroke-width="2" />
                        Payout History
                        <span v-if="payouts?.total" class="tab-count">{{
                            payouts.total
                        }}</span>
                    </button>
                </div>

                <!-- ══════════════════════════════════════════════════════ -->
                <!-- EARNINGS TAB                                           -->
                <!-- ══════════════════════════════════════════════════════ -->
                <div v-if="activeTab === 'earnings'" class="card">
                    <!-- Filter bar -->
                    <div class="filter-bar">
                        <div class="filter-bar-left">
                            <Filter
                                :size="14"
                                stroke-width="2"
                                class="filter-icon"
                            />
                            <select class="fsel" v-model="earningsFilter.month">
                                <option
                                    v-for="m in MONTHS"
                                    :key="m.v"
                                    :value="m.v"
                                >
                                    {{ m.l }}
                                </option>
                            </select>
                            <select class="fsel" v-model="earningsFilter.year">
                                <option v-for="y in YEARS" :key="y" :value="y">
                                    {{ y }}
                                </option>
                            </select>
                            <select
                                class="fsel"
                                v-model="earningsFilter.status"
                            >
                                <option value="">All statuses</option>
                                <option value="pending">Pending</option>
                                <option value="paid">Paid</option>
                                <option value="withheld">Withheld</option>
                            </select>
                            <select
                                class="fsel"
                                v-model="earningsFilter.resident_id"
                            >
                                <option value="">All households</option>
                                <option
                                    v-for="hh in households"
                                    :key="hh.id"
                                    :value="hh.id"
                                >
                                    {{ hh.name }}
                                </option>
                            </select>
                            <button
                                v-if="hasActiveEarningsFilter"
                                class="btn-reset"
                                @click="resetEarningsFilters"
                            >
                                <X :size="12" stroke-width="2.5" /> Reset
                            </button>
                        </div>
                        <div class="filter-bar-right">
                            <!-- Filtered summary pills -->
                            <span class="sum-pill">
                                <span class="sum-dot orange"></span>
                                Pending:
                                <strong>{{
                                    fmt(summary?.pending_amount)
                                }}</strong>
                            </span>
                            <span class="sum-pill">
                                <span class="sum-dot green"></span>
                                Paid:
                                <strong>{{ fmt(summary?.paid_amount) }}</strong>
                            </span>
                            <button
                                class="btn-export"
                                @click="exportEarnings"
                                :disabled="isExporting"
                            >
                                <Download :size="13" stroke-width="2" />
                                {{ isExporting ? 'Exporting…' : 'CSV' }}
                            </button>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="table-wrap">
                        <div v-if="isLoadingEarnings" class="table-loading">
                            <div class="spinner sm"></div>
                        </div>
                        <table v-else class="data-table">
                            <thead>
                                <tr>
                                    <th>Household</th>
                                    <th>Address</th>
                                    <th>Period</th>
                                    <th>Resident Paid</th>
                                    <th>Your Share ({{ commissionRate }}%)</th>
                                    <th>Platform Fee</th>
                                    <th>Status</th>
                                    <th>Paid On</th>
                                    <th>Payout Ref</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!earnings?.data?.length">
                                    <td colspan="9" class="empty-row">
                                        No earnings found for
                                        <strong>{{
                                            earningsFilterLabel || 'this period'
                                        }}</strong>
                                    </td>
                                </tr>
                                <tr v-for="e in earnings?.data" :key="e.id">
                                    <td class="fw6">{{ e.household_name }}</td>
                                    <td class="muted small">
                                        {{ e.household_address || '—' }}
                                    </td>
                                    <td class="small muted nowrap">
                                        {{ fmtDate(e.period_start) }}
                                        <span class="arrow">→</span>
                                        {{ fmtDate(e.period_end) }}
                                    </td>
                                    <td class="muted">
                                        {{ fmt(e.resident_amount) }}
                                    </td>
                                    <td class="fw7 green-text">
                                        {{ fmt(e.earned_amount) }}
                                    </td>
                                    <td class="red-text small">
                                        {{ fmt(e.platform_amount) }}
                                    </td>
                                    <td>
                                        <span
                                            :class="[
                                                'badge',
                                                statusColour(e.status),
                                            ]"
                                            >{{ e.status }}</span
                                        >
                                    </td>
                                    <td class="muted small">
                                        {{ fmtDate(e.payout_at) }}
                                    </td>
                                    <td class="mono small muted">
                                        {{ e.payout_reference ?? '—' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div
                        v-if="earnings && earnings.last_page > 1"
                        class="pagination"
                    >
                        <span class="page-info">
                            Showing {{ earnings.from }}–{{ earnings.to }} of
                            {{ earnings.total }}
                        </span>
                        <div class="page-btns">
                            <button
                                class="pg-btn"
                                :disabled="earnings.current_page === 1"
                                @click="
                                    goEarningsPage(earnings!.current_page - 1)
                                "
                            >
                                <ChevronLeft :size="14" />
                            </button>
                            <template v-for="p in earnings.last_page" :key="p">
                                <button
                                    v-if="
                                        p <= 3 ||
                                        p > earnings.last_page - 2 ||
                                        Math.abs(p - earnings.current_page) <= 1
                                    "
                                    :class="[
                                        'pg-btn',
                                        { active: p === earnings.current_page },
                                    ]"
                                    @click="goEarningsPage(p)"
                                >
                                    {{ p }}
                                </button>
                                <span
                                    v-else-if="
                                        p === 4 && earnings.current_page > 5
                                    "
                                    class="pg-ellipsis"
                                    >…</span
                                >
                            </template>
                            <button
                                class="pg-btn"
                                :disabled="
                                    earnings.current_page === earnings.last_page
                                "
                                @click="
                                    goEarningsPage(earnings!.current_page + 1)
                                "
                            >
                                <ChevronRight :size="14" />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════════════════════════════ -->
                <!-- PAYOUTS TAB                                            -->
                <!-- ══════════════════════════════════════════════════════ -->
                <div v-if="activeTab === 'payouts'" class="card">
                    <!-- Filter bar -->
                    <div class="filter-bar">
                        <div class="filter-bar-left">
                            <Filter
                                :size="14"
                                stroke-width="2"
                                class="filter-icon"
                            />
                            <select class="fsel" v-model="payoutsFilter.month">
                                <option
                                    v-for="m in MONTHS"
                                    :key="m.v"
                                    :value="m.v"
                                >
                                    {{ m.l }}
                                </option>
                            </select>
                            <select class="fsel" v-model="payoutsFilter.year">
                                <option v-for="y in YEARS" :key="y" :value="y">
                                    {{ y }}
                                </option>
                            </select>
                            <select class="fsel" v-model="payoutsFilter.status">
                                <option value="">All statuses</option>
                                <option value="pending">Pending</option>
                                <option value="paid">Paid</option>
                                <option value="failed">Failed</option>
                            </select>
                        </div>
                        <div class="filter-bar-right">
                            <button
                                class="btn-export"
                                @click="exportPayouts"
                                :disabled="isExporting"
                            >
                                <Download :size="13" stroke-width="2" />
                                {{ isExporting ? 'Exporting…' : 'CSV' }}
                            </button>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="table-wrap">
                        <div v-if="isLoadingPayouts" class="table-loading">
                            <div class="spinner sm"></div>
                        </div>
                        <table v-else class="data-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Period</th>
                                    <th>Households</th>
                                    <th>Gross</th>
                                    <th>Platform Fee</th>
                                    <th>Your Payout</th>
                                    <th>Paid On</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!payouts?.data?.length">
                                    <td colspan="8" class="empty-row">
                                        No payout records found for this period.
                                    </td>
                                </tr>
                                <tr v-for="p in payouts?.data" :key="p.id">
                                    <td class="mono small">
                                        {{ p.reference ?? '—' }}
                                    </td>
                                    <td class="muted small nowrap">
                                        {{ fmtDate(p.period_start)
                                        }}<span class="arrow">→</span
                                        >{{ fmtDate(p.period_end) }}
                                    </td>
                                    <td>{{ p.household_count ?? '—' }}</td>
                                    <td class="muted">
                                        {{ fmt(p.gross_amount) }}
                                    </td>
                                    <td class="red-text small">
                                        {{ fmt(p.platform_fee) }}
                                    </td>
                                    <td class="fw7 green-text">
                                        {{ fmt(p.net_amount) }}
                                    </td>
                                    <td class="muted small">
                                        {{ fmtDate(p.paid_at) }}
                                    </td>
                                    <td>
                                        <span
                                            :class="[
                                                'badge',
                                                statusColour(p.status),
                                            ]"
                                            >{{ p.status }}</span
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div
                        v-if="payouts && payouts.last_page > 1"
                        class="pagination"
                    >
                        <span class="page-info"
                            >Showing {{ payouts.from }}–{{ payouts.to }} of
                            {{ payouts.total }}</span
                        >
                        <div class="page-btns">
                            <button
                                class="pg-btn"
                                :disabled="payouts.current_page === 1"
                                @click="
                                    goPayoutsPage(payouts!.current_page - 1)
                                "
                            >
                                <ChevronLeft :size="14" />
                            </button>
                            <template v-for="p in payouts.last_page" :key="p">
                                <button
                                    v-if="
                                        p <= 3 ||
                                        p > payouts.last_page - 2 ||
                                        Math.abs(p - payouts.current_page) <= 1
                                    "
                                    :class="[
                                        'pg-btn',
                                        { active: p === payouts.current_page },
                                    ]"
                                    @click="goPayoutsPage(p)"
                                >
                                    {{ p }}
                                </button>
                                <span
                                    v-else-if="
                                        p === 4 && payouts.current_page > 5
                                    "
                                    class="pg-ellipsis"
                                    >…</span
                                >
                            </template>
                            <button
                                class="pg-btn"
                                :disabled="
                                    payouts.current_page === payouts.last_page
                                "
                                @click="
                                    goPayoutsPage(payouts!.current_page + 1)
                                "
                            >
                                <ChevronRight :size="14" />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── HOUSEHOLD BREAKDOWN ─────────────────────────────── -->
                <div class="card" v-if="households.length">
                    <div class="card-head">
                        <div class="card-title">Household Breakdown</div>
                        <div class="legend">
                            <span class="leg green">● Active</span>
                            <span class="leg orange">● Trialing</span>
                            <span class="leg red">● Failed</span>
                            <span class="leg grey">● Cancelled</span>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Household</th>
                                    <th>Address</th>
                                    <th>Monthly Fee</th>
                                    <th>Your Share ({{ commissionRate }}%)</th>
                                    <th>Joined</th>
                                    <th>Subscription</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="hh in households" :key="hh.id">
                                    <td class="fw6">{{ hh.name }}</td>
                                    <td class="muted small">
                                        {{ hh.address || '—' }}
                                    </td>
                                    <td>R 80.00</td>
                                    <td class="fw7 green-text">
                                        R {{ perHouseholdEarning }}
                                    </td>
                                    <td class="muted small">
                                        {{ fmtDate(hh.created_at) }}
                                    </td>
                                    <td>
                                        <span
                                            :class="[
                                                'badge',
                                                statusColour(
                                                    hh.status === 'pending'
                                                        ? 'trialing'
                                                        : hh.status,
                                                ),
                                            ]"
                                        >
                                            {{
                                                hh.status === 'pending'
                                                    ? 'trialing'
                                                    : hh.status
                                            }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-else class="card empty-card">
                    <HouseIcon :size="28" stroke-width="1.5" color="#bbb" />
                    <div class="empty-title">No households yet</div>
                    <div class="empty-desc">
                        Share your invite link to start earning from households
                        in your area.
                    </div>
                </div>

                <!-- ── BANK DETAILS ────────────────────────────────────── -->
                <div class="card">
                    <div class="card-head">
                        <div class="card-title">
                            <Banknote :size="16" stroke-width="2" />
                            Bank Details
                        </div>
                        <button
                            class="btn-secondary"
                            @click="showBankModal = true"
                        >
                            {{
                                bankDetails
                                    ? 'Update Details'
                                    : 'Add Bank Details'
                            }}
                        </button>
                    </div>

                    <div v-if="bankDetails" class="bank-grid">
                        <div
                            class="bank-item"
                            v-for="(val, lbl) in {
                                Bank: bankDetails.bank_name,
                                'Account Holder': bankDetails.account_holder,
                                'Account Number': bankDetails.account_number,
                                'Account Type': bankDetails.account_type,
                                'Branch Code': bankDetails.branch_code,
                            }"
                            :key="lbl"
                        >
                            <div class="bank-lbl">{{ lbl }}</div>
                            <div
                                class="bank-val"
                                :class="{
                                    mono:
                                        lbl === 'Account Number' ||
                                        lbl === 'Branch Code',
                                }"
                            >
                                {{ val }}
                            </div>
                        </div>
                        <div class="bank-item verified">
                            <BadgeCheck :size="16" color="#16a34a" />
                            <span>Banking details on file</span>
                        </div>
                    </div>

                    <div v-else class="bank-missing">
                        <div>
                            <div class="bm-title">No bank details on file</div>
                            <div class="bm-desc">
                                Add your banking details to receive monthly
                                payouts. Payouts are held until details are
                                provided.
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ── BANK MODAL ──────────────────────────────────────────── -->
            <div
                v-if="showBankModal"
                class="modal-overlay"
                @click.self="showBankModal = false"
            >
                <div class="modal">
                    <div class="modal-title">
                        {{ bankDetails ? 'Update' : 'Add' }} Bank Details
                    </div>
                    <p class="modal-note">
                        Your payout will be transferred to this account on the
                        1st of each month.
                    </p>

                    <div class="mf">
                        <label class="ml">Bank Name</label>
                        <select class="mi" v-model="bankForm.bank_name">
                            <option value="">Select bank</option>
                            <option
                                v-for="b in [
                                    'ABSA',
                                    'Capitec',
                                    'FNB',
                                    'Nedbank',
                                    'Standard Bank',
                                    'TymeBank',
                                    'African Bank',
                                    'Investec',
                                    'Discovery Bank',
                                    'Other',
                                ]"
                                :key="b"
                            >
                                {{ b }}
                            </option>
                        </select>
                    </div>
                    <div class="mf">
                        <label class="ml">Account Holder Name</label>
                        <input
                            class="mi"
                            type="text"
                            v-model="bankForm.account_holder"
                            placeholder="Full name as on account"
                        />
                    </div>
                    <div class="mr">
                        <div class="mf">
                            <label class="ml">Account Number</label>
                            <input
                                class="mi mono"
                                type="text"
                                v-model="bankForm.account_number"
                                placeholder="e.g. 1234567890"
                            />
                        </div>
                        <div class="mf">
                            <label class="ml">Branch Code</label>
                            <input
                                class="mi mono"
                                type="text"
                                v-model="bankForm.branch_code"
                                placeholder="e.g. 632005"
                            />
                        </div>
                    </div>
                    <div class="mf">
                        <label class="ml">Account Type</label>
                        <div class="type-toggle">
                            <button
                                v-for="t in [
                                    'Cheque',
                                    'Savings',
                                    'Transmission',
                                ]"
                                :key="t"
                                :class="[
                                    'type-btn',
                                    { active: bankForm.account_type === t },
                                ]"
                                @click="bankForm.account_type = t"
                                type="button"
                            >
                                {{ t }}
                            </button>
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button
                            class="btn-ghost"
                            @click="showBankModal = false"
                        >
                            Cancel
                        </button>
                        <button
                            class="btn-primary"
                            :disabled="isSavingBank"
                            @click="saveBankDetails"
                        >
                            <span
                                v-if="isSavingBank"
                                class="btn-spinner"
                            ></span>
                            <span v-else>Save Details</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* ── Root ─────────────────────────────────────────────────────────────────── */
.po-root {
    max-width: 1100px;
    margin: 0 auto;
    padding: 36px 24px 80px;
    font-family: 'Segoe UI', sans-serif;
    color: #111;
}
.po-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    padding: 80px 0;
    color: #999;
}
.po-header {
    margin-bottom: 24px;
}
.po-title {
    font-size: 22px;
    font-weight: 800;
    letter-spacing: -0.5px;
    margin: 0 0 4px;
}
.po-sub {
    font-size: 13px;
    color: #888;
    margin: 0;
}

/* ── Flash ────────────────────────────────────────────────────────────────── */
.flash {
    padding: 11px 16px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 18px;
}
.flash.success {
    background: #dcfce7;
    border: 1.5px solid #86efac;
    color: #16a34a;
}
.flash.error {
    background: #fef2f2;
    border: 1.5px solid #fecaca;
    color: #dc2626;
}

/* ── Hero Banner ──────────────────────────────────────────────────────────── */
.hero-banner {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 60%, #0f172a 100%);
    border-radius: 20px;
    padding: 32px 36px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 32px;
    margin-bottom: 20px;
    flex-wrap: wrap;
    border: 1px solid rgba(255, 255, 255, 0.06);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.18);
}
.hero-eyebrow {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    font-weight: 700;
    color: #f97316;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    margin-bottom: 10px;
}
.dot-pulse {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #f97316;
    box-shadow: 0 0 0 0 rgba(249, 115, 22, 0.6);
    animation: pulse 1.8s ease-out infinite;
}
@keyframes pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(249, 115, 22, 0.6);
    }
    70% {
        box-shadow: 0 0 0 8px rgba(249, 115, 22, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(249, 115, 22, 0);
    }
}
.hero-amount {
    font-size: 52px;
    font-weight: 800;
    color: #fff;
    letter-spacing: -2.5px;
    line-height: 1;
    margin-bottom: 8px;
}
.hero-sub {
    font-size: 13px;
    color: #64748b;
    margin-bottom: 18px;
}
.hero-sub strong {
    color: #94a3b8;
}
.hero-formula {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.formula-pill {
    padding: 4px 10px;
    border-radius: 100px;
    background: rgba(255, 255, 255, 0.07);
    color: #cbd5e1;
    font-size: 12px;
    font-weight: 600;
}
.formula-pill.accent {
    background: rgba(249, 115, 22, 0.15);
    color: #fb923c;
}
.formula-pill.green {
    background: rgba(22, 163, 74, 0.15);
    color: #4ade80;
}
.formula-op {
    color: #334155;
    font-size: 13px;
    font-weight: 700;
}
.hero-right {
    display: flex;
    align-items: center;
    gap: 28px;
    flex-wrap: wrap;
}
.hero-stat {
    text-align: center;
}
.hs-val {
    font-size: 22px;
    font-weight: 800;
    color: #fff;
    margin-bottom: 4px;
    letter-spacing: -0.5px;
}
.hs-lbl {
    font-size: 11px;
    color: #475569;
    font-weight: 500;
    white-space: nowrap;
}
.hero-divider {
    width: 1px;
    height: 44px;
    background: rgba(255, 255, 255, 0.07);
}

/* ── Stat Row ─────────────────────────────────────────────────────────────── */
.stat-row {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}
.stat-card {
    background: #fff;
    border: 1.5px solid #ebebeb;
    border-radius: 14px;
    padding: 16px 18px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.sc-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: #999;
    margin-bottom: 4px;
}
.sc-badge {
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 100px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.sc-badge.green {
    background: rgba(22, 163, 74, 0.1);
    color: #16a34a;
}
.sc-badge.orange {
    background: rgba(249, 115, 22, 0.1);
    color: #f97316;
}
.sc-badge.red {
    background: rgba(220, 38, 38, 0.1);
    color: #dc2626;
}
.sc-val {
    font-size: 20px;
    font-weight: 800;
    color: #111;
    letter-spacing: -0.5px;
}
.sc-val.next-date {
    font-size: 13px;
    font-weight: 700;
}
.sc-lbl {
    font-size: 11px;
    color: #aaa;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* ── Tabs ─────────────────────────────────────────────────────────────────── */
.tabs {
    display: flex;
    gap: 4px;
    margin-bottom: 0;
    border-bottom: 2px solid #f0f0f0;
    margin-bottom: -2px; /* overlap card top border */
    position: relative;
    z-index: 1;
}
.tab {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 700;
    color: #888;
    border: none;
    background: none;
    cursor: pointer;
    border-bottom: 2.5px solid transparent;
    margin-bottom: -2px;
    transition:
        color 0.15s,
        border-color 0.15s;
    font-family: 'Segoe UI', sans-serif;
}
.tab:hover {
    color: #111;
}
.tab.active {
    color: #111;
    border-bottom-color: #f97316;
}
.tab-count {
    background: #f0f0f0;
    color: #666;
    font-size: 10px;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 100px;
    min-width: 18px;
    text-align: center;
}
.tab.active .tab-count {
    background: rgba(249, 115, 22, 0.1);
    color: #f97316;
}

/* ── Cards ────────────────────────────────────────────────────────────────── */
.card {
    background: #fff;
    border: 1.5px solid #ebebeb;
    border-radius: 16px;
    padding: 20px 24px;
    margin-bottom: 16px;
}
.card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 18px;
    flex-wrap: wrap;
    gap: 10px;
}
.card-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    font-weight: 800;
    color: #111;
}
.legend {
    display: flex;
    gap: 14px;
    font-size: 12px;
}
.leg.green {
    color: #16a34a;
}
.leg.orange {
    color: #f97316;
}
.leg.red {
    color: #dc2626;
}
.leg.grey {
    color: #888888;
}

/* ── Filter Bar ───────────────────────────────────────────────────────────── */
.filter-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 16px;
    flex-wrap: wrap;
    padding-bottom: 16px;
    border-bottom: 1.5px solid #f3f3f3;
}
.filter-bar-left {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.filter-bar-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}
.filter-icon {
    color: #bbb;
    flex-shrink: 0;
}
.fsel {
    padding: 7px 10px;
    border: 1.5px solid #e8e8e8;
    border-radius: 9px;
    font-size: 13px;
    font-weight: 500;
    color: #333;
    background: #fff;
    cursor: pointer;
    outline: none;
    font-family: 'Segoe UI', sans-serif;
    transition: border-color 0.15s;
}
.fsel:hover,
.fsel:focus {
    border-color: #f97316;
}

.btn-reset {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 7px 11px;
    border-radius: 9px;
    border: 1.5px solid #ffd0b5;
    background: rgba(249, 115, 22, 0.05);
    color: #f97316;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    font-family: 'Segoe UI', sans-serif;
    transition: all 0.15s;
}
.btn-reset:hover {
    background: rgba(249, 115, 22, 0.1);
}

.sum-pill {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #555;
    white-space: nowrap;
}
.sum-pill strong {
    color: #111;
}
.sum-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
}
.sum-dot.green {
    background: #16a34a;
}
.sum-dot.orange {
    background: #f97316;
}

.btn-export {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 9px;
    border: 1.5px solid #e0e0e0;
    background: #fafafa;
    color: #444;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    font-family: 'Segoe UI', sans-serif;
    transition: all 0.15s;
}
.btn-export:hover:not(:disabled) {
    border-color: #f97316;
    color: #f97316;
    background: rgba(249, 115, 22, 0.04);
}
.btn-export:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* ── Tables ───────────────────────────────────────────────────────────────── */
.table-wrap {
    overflow-x: auto;
    position: relative;
    min-height: 60px;
}
.table-loading {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.7);
    z-index: 2;
}
.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.data-table th {
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    color: #aaa;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 0 14px 10px 0;
    border-bottom: 1.5px solid #f0f0f0;
    white-space: nowrap;
}
.data-table td {
    padding: 11px 14px 11px 0;
    color: #333;
    border-bottom: 1px solid #f7f7f7;
    vertical-align: middle;
}
.data-table tr:last-child td {
    border-bottom: none;
}
.empty-row {
    text-align: center;
    padding: 36px 0 !important;
    color: #aaa;
    font-size: 13px;
}

/* ── Badges ───────────────────────────────────────────────────────────────── */
.badge {
    font-size: 11px;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: 100px;
    text-transform: capitalize;
}
.badge.green {
    background: rgba(22, 163, 74, 0.1);
    color: #16a34a;
}
.badge.orange {
    background: rgba(249, 115, 22, 0.1);
    color: #f97316;
}
.badge.red {
    background: rgba(220, 38, 38, 0.1);
    color: #dc2626;
}
.badge.grey {
    background: #f5f5f5;
    color: #888;
}

/* ── Pagination ───────────────────────────────────────────────────────────── */
.pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 16px;
    margin-top: 4px;
    border-top: 1.5px solid #f3f3f3;
    flex-wrap: wrap;
    gap: 10px;
}
.page-info {
    font-size: 12px;
    color: #999;
}
.page-btns {
    display: flex;
    gap: 4px;
    align-items: center;
}
.pg-btn {
    min-width: 30px;
    height: 30px;
    padding: 0 6px;
    border: 1.5px solid #e8e8e8;
    border-radius: 8px;
    background: #fff;
    color: #444;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Segoe UI', sans-serif;
    transition: all 0.15s;
}
.pg-btn:hover:not(:disabled) {
    border-color: #f97316;
    color: #f97316;
}
.pg-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.pg-btn.active {
    background: #f97316;
    border-color: #f97316;
    color: #fff;
}
.pg-ellipsis {
    font-size: 13px;
    color: #bbb;
    padding: 0 4px;
}

/* ── Utility ──────────────────────────────────────────────────────────────── */
.fw6 {
    font-weight: 600;
    color: #111;
}
.fw7 {
    font-weight: 700;
}
.muted {
    color: #888;
}
.small {
    font-size: 12px;
}
.mono {
    font-family: monospace;
    font-size: 12px;
}
.nowrap {
    white-space: nowrap;
}
.green-text {
    color: #16a34a;
}
.red-text {
    color: #dc2626;
}
.arrow {
    color: #ddd;
    margin: 0 4px;
}

/* ── Empty state ──────────────────────────────────────────────────────────── */
.empty-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 48px 24px;
    text-align: center;
}
.empty-title {
    font-size: 14px;
    font-weight: 700;
    color: #111;
}
.empty-desc {
    font-size: 13px;
    color: #999;
    max-width: 340px;
    line-height: 1.6;
}

/* ── Bank Details ─────────────────────────────────────────────────────────── */
.bank-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
}
.bank-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.bank-lbl {
    font-size: 11px;
    font-weight: 700;
    color: #aaa;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.bank-val {
    font-size: 14px;
    font-weight: 600;
    color: #111;
}
.bank-item.verified {
    flex-direction: row;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: #16a34a;
    font-weight: 600;
    grid-column: 1 / -1;
}
.bank-missing {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    background: rgba(249, 115, 22, 0.04);
    border: 1.5px solid rgba(249, 115, 22, 0.15);
    border-radius: 12px;
    padding: 16px;
}
.bm-icon {
    font-size: 20px;
    color: #f97316;
    flex-shrink: 0;
}
.bm-title {
    font-size: 14px;
    font-weight: 700;
    color: #111;
    margin-bottom: 4px;
}
.bm-desc {
    font-size: 13px;
    color: #888;
    line-height: 1.5;
}

/* ── Modal ────────────────────────────────────────────────────────────────── */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.45);
    z-index: 500;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal {
    background: #fff;
    border-radius: 20px;
    padding: 32px;
    width: 100%;
    max-width: 500px;
    box-shadow: 0 24px 64px rgba(0, 0, 0, 0.18);
    max-height: 90vh;
    overflow-y: auto;
}
.modal-title {
    font-size: 18px;
    font-weight: 800;
    color: #111;
    margin-bottom: 6px;
}
.modal-note {
    font-size: 13px;
    color: #888;
    margin-bottom: 22px;
    line-height: 1.5;
}
.mf {
    margin-bottom: 14px;
}
.ml {
    font-size: 11px;
    font-weight: 700;
    color: #555;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: block;
    margin-bottom: 6px;
}
.mi {
    width: 100%;
    padding: 10px 14px;
    border: 1.5px solid #e5e5e5;
    border-radius: 10px;
    font-size: 14px;
    font-family: 'Segoe UI', sans-serif;
    color: #111;
    outline: none;
    transition: all 0.15s;
    box-sizing: border-box;
}
.mi:focus {
    border-color: #f97316;
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
}
.mi.mono {
    font-family: monospace;
    letter-spacing: 1px;
}
.mr {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 14px;
}
.modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 22px;
}
.type-toggle {
    display: flex;
    gap: 8px;
}
.type-btn {
    flex: 1;
    padding: 9px 8px;
    border: 1.5px solid #e5e5e5;
    border-radius: 10px;
    background: #fff;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    color: #555;
    transition: all 0.2s;
    font-family: 'Segoe UI', sans-serif;
}
.type-btn:hover {
    border-color: #f97316;
    color: #f97316;
}
.type-btn.active {
    border-color: #f97316;
    background: rgba(249, 115, 22, 0.05);
    color: #f97316;
}

/* ── Buttons ──────────────────────────────────────────────────────────────── */
.btn-primary {
    padding: 10px 20px;
    background: #f97316;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 8px;
    font-family: 'Segoe UI', sans-serif;
}
.btn-primary:hover:not(:disabled) {
    background: #ea580c;
}
.btn-primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
.btn-ghost {
    padding: 10px 20px;
    background: #fff;
    color: #555;
    border: 1.5px solid #e5e5e5;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    font-family: 'Segoe UI', sans-serif;
    transition: all 0.2s;
}
.btn-ghost:hover {
    border-color: #ccc;
    color: #111;
}
.btn-secondary {
    padding: 8px 16px;
    background: #f5f5f5;
    color: #333;
    border: 1.5px solid #e5e5e5;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    font-family: 'Segoe UI', sans-serif;
}
.btn-secondary:hover {
    border-color: #f97316;
    color: #f97316;
    background: rgba(249, 115, 22, 0.04);
}

/* ── Spinners ─────────────────────────────────────────────────────────────── */
.spinner {
    width: 30px;
    height: 30px;
    border: 3px solid #f0f0f0;
    border-top-color: #f97316;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
}
.spinner.sm {
    width: 22px;
    height: 22px;
    border-width: 2.5px;
}
.btn-spinner {
    width: 15px;
    height: 15px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
    display: inline-block;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

/* ── Responsive ───────────────────────────────────────────────────────────── */
@media (max-width: 1000px) {
    .stat-row {
        grid-template-columns: repeat(3, 1fr);
    }
}
@media (max-width: 700px) {
    .hero-banner {
        flex-direction: column;
    }
    .hero-amount {
        font-size: 38px;
    }
    .hero-right {
        justify-content: flex-start;
    }
    .stat-row {
        grid-template-columns: 1fr 1fr;
    }
    .bank-grid {
        grid-template-columns: 1fr 1fr;
    }
    .mr {
        grid-template-columns: 1fr;
    }
    .type-toggle {
        flex-wrap: wrap;
    }
    .filter-bar {
        flex-direction: column;
        align-items: flex-start;
    }
    .filter-bar-right {
        width: 100%;
        justify-content: flex-end;
    }
}
@media (max-width: 420px) {
    .bank-grid {
        grid-template-columns: 1fr;
    }
    .hero-right {
        flex-direction: column;
        gap: 14px;
    }
    .hero-divider {
        display: none;
    }
    .stat-row {
        grid-template-columns: 1fr 1fr;
    }
}
</style>

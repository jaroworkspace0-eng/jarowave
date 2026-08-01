<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import {
    AlertTriangle,
    Building2,
    CheckCircle,
    Clock,
    Copy,
    CreditCard,
    Trash2,
    Upload,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

const auth = useAuthStore();

onMounted(() => {
    const isEstateBilling = auth.user?.role === 'estate_billing';
    const isGrantedGuard =
        auth.user?.is_gate_guard && auth.user?.has_dashboard_access;

    if (!isEstateBilling && !isGrantedGuard) {
        router.visit('/dashboard');
    }
});

// ── Types ─────────────────────────────────────────────────────────────────
interface ChannelSubscription {
    id: number;
    household_count: number;
    amount_per_household: number;
    linked_account_count: number;
    amount_per_linked_account: number | null;
    total_amount: number;
    status: 'pending' | 'active' | 'overdue' | 'cancelled';
    billing_model: string;
    current_period_start: string | null;
    current_period_end: string | null;
    paid_at: string | null;
}

interface Household {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    unit_number: string | null;
    subscription_status: string;
}

interface Payment {
    id: number;
    amount: number;
    household_count: number;
    payment_method: string;
    status: string;
    merchant_reference: string | null;
    paid_at: string | null;
    created_at: string;
    notes?: string | null;
}

interface Channel {
    id: number;
    name: string;
    billing_model: string;
}

// ── State ─────────────────────────────────────────────────────────────────
const channel = ref<Channel | null>(null);
const summary = ref<ChannelSubscription | null>(null);
const households = ref<Household[]>([]);
const payments = ref<Payment[]>([]);
const isLoading = ref(true);
const flash = ref<{ msg: string; type: 'success' | 'error' } | null>(null);

// EFT modal
const showEftModal = ref(false);
const eftForm = ref({ amount: '', note: '', proof: null as File | null });
const isSubmittingEft = ref(false);
const eftRef = ref('');
const copiedRef = ref(false);

// Pay Now
const isPayingNow = ref(false);

// Remove household modal
const showRemoveModal = ref(false);
const householdToRemove = ref<Household | null>(null);
const isRemoving = ref(false);

// Tabs
const activeTab = ref<'households' | 'payments'>('households');

// ── Helpers ───────────────────────────────────────────────────────────────
const getHeaders = () => ({
    headers: { Authorization: `Bearer ${localStorage.getItem('token')}` },
});

const showFlash = (msg: string, type: 'success' | 'error' = 'success') => {
    flash.value = { msg, type };
    setTimeout(() => (flash.value = null), 3500);
};

const fmt = (val: number | null | undefined) =>
    val != null
        ? `R${Number(val).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
        : '—';

const formatDate = (d: string | null) =>
    d
        ? new Date(d).toLocaleDateString('en-ZA', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '—';

const generateEftRef = () => {
    const now = new Date();
    const yyyy = now.getFullYear();
    const mm = String(now.getMonth() + 1).padStart(2, '0');
    const seq = String(Math.floor(Math.random() * 900) + 100);
    return `ECL-EST-${yyyy}-${mm}-${seq}`;
};

const copyToClipboard = async (text: string, cb: () => void) => {
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
};

const copyEftRef = () => {
    copyToClipboard(eftRef.value, () => {
        copiedRef.value = true;
        setTimeout(() => (copiedRef.value = false), 2000);
    });
};

// ── Computed ──────────────────────────────────────────────────────────────

// Days remaining until the current period ends. Can be negative once the
// period has passed — never render this raw, use isPastDue/daysOverdue.
const daysUntilDue = computed(() => {
    if (!summary.value || !summary.value.current_period_end) return null;
    const diff =
        new Date(summary.value.current_period_end).getTime() - Date.now();
    return Math.ceil(diff / (1000 * 60 * 60 * 24));
});

// True once the period has passed without payment, regardless of whether
// the backend status field has transitioned to 'overdue' yet.
const isPastDue = computed(() => {
    if (!summary.value) return false;
    if (daysUntilDue.value === null) return false;
    if (
        summary.value.status === 'active' ||
        summary.value.status === 'cancelled'
    )
        return false;
    return daysUntilDue.value < 0;
});

const daysOverdue = computed(() => {
    if (daysUntilDue.value === null) return 0;
    return Math.abs(daysUntilDue.value);
});

const isDueSoon = computed(() => {
    if (!summary.value) return false;
    if (summary.value.status === 'cancelled') return false;
    if (daysUntilDue.value === null) return false;
    return daysUntilDue.value >= 0 && daysUntilDue.value <= 3;
});

const statusConfig = computed(() => {
    const effectiveStatus = isPastDue.value ? 'overdue' : summary.value?.status;

    switch (effectiveStatus) {
        case 'active':
            return {
                label: 'Active',
                cls: 'stat-card__value--green',
                icon: CheckCircle,
            };
        case 'pending':
            return {
                label: 'Pending Payment',
                cls: 'stat-card__value--orange',
                icon: Clock,
            };
        case 'overdue':
            return {
                label: 'Overdue',
                cls: 'stat-card__value--red',
                icon: AlertTriangle,
            };
        case 'cancelled':
            return { label: 'Cancelled', cls: '', icon: Clock };
        default:
            return { label: '—', cls: '', icon: Clock };
    }
});

const householdBadge = (status: string) => {
    if (status === 'active')
        return { label: 'Active', cls: 'bg-emerald-50 text-emerald-700' };
    if (status === 'trialing')
        return { label: 'Trialing', cls: 'bg-amber-50 text-amber-700' };
    if (status === 'past_due')
        return { label: 'Past Due', cls: 'bg-red-50 text-red-600' };
    if (status === 'cancelled')
        return { label: 'Cancelled', cls: 'bg-slate-100 text-slate-500' };
    return { label: status, cls: 'bg-slate-100 text-slate-500' };
};

const paymentBadge = (status: string) => {
    if (status === 'paid')
        return { label: 'Approved', cls: 'bg-emerald-50 text-emerald-700' };
    if (status === 'pending_review')
        return { label: 'Pending Review', cls: 'bg-amber-50 text-amber-700' };
    if (status === 'rejected')
        return { label: 'Rejected', cls: 'bg-red-50 text-red-600' };
    if (status === 'failed')
        return { label: 'Failed', cls: 'bg-red-50 text-red-600' };
    if (status === 'abandoned')
        return { label: 'Abandoned', cls: 'bg-slate-100 text-slate-500' };
    return { label: status, cls: 'bg-slate-100 text-slate-500' };
};

const initials = (name: string) =>
    (name || '?')
        .split(' ')
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('');

// ── Data ──────────────────────────────────────────────────────────────────
const base = () =>
    `${import.meta.env.VITE_APP_URL}/api/channels/${channel.value ? channel.value.id : ''}/billing`;

const fetchAll = async () => {
    isLoading.value = true;
    try {
        const [sumRes, hhRes, payRes] = await Promise.all([
            axios.get(`${base()}/summary`, getHeaders()),
            axios.get(`${base()}/opted-in-households`, getHeaders()),
            axios.get(`${base()}/payment-history`, getHeaders()),
        ]);
        summary.value = sumRes.data.channel_subscription;
        households.value = hhRes.data.households;
        payments.value = payRes.data.payments.data ?? payRes.data.payments;
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to load billing data.',
            'error',
        );
    } finally {
        isLoading.value = false;
    }
};

onMounted(async () => {
    try {
        const res = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/estate/my-channel`,
            getHeaders(),
        );
        channel.value = res.data.channel;
        await fetchAll();
    } catch (err: any) {
        showFlash('Failed to load channel.', 'error');
        isLoading.value = false;
    }
});

// ── EFT Payment ───────────────────────────────────────────────────────────
const openEftModal = () => {
    eftRef.value = generateEftRef();
    eftForm.value = {
        amount: String(summary.value?.total_amount ?? ''),
        note: '',
        proof: null,
    };
    copiedRef.value = false;
    showEftModal.value = true;
};

const onProofSelected = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    eftForm.value.proof = file;
};

const submitEft = async () => {
    if (!eftForm.value.proof || !eftForm.value.note) return;
    isSubmittingEft.value = true;

    const fd = new FormData();
    fd.append('amount', eftForm.value.amount);
    fd.append('note', eftForm.value.note);
    fd.append('proof', eftForm.value.proof);

    try {
        await axios.post(`${base()}/mark-eft-paid`, fd, {
            headers: {
                ...getHeaders().headers,
                'Content-Type': 'multipart/form-data',
            },
        });
        showFlash(
            'EFT payment submitted. All opted-in households will be activated shortly.',
        );
        showEftModal.value = false;
        await fetchAll();
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to submit payment.',
            'error',
        );
    } finally {
        isSubmittingEft.value = false;
    }
};

// ── Pay Now (PayFast) ────────────────────────────────────────────────────
const submitPayFastForm = (action: string, fields: Record<string, string>) => {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = action;
    Object.entries(fields).forEach(([key, value]) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = value;
        form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
};

const payNow = async () => {
    if (!channel.value) return;
    isPayingNow.value = true;
    try {
        const res = await axios.post(`${base()}/pay-now`, {}, getHeaders());
        submitPayFastForm(res.data.action, res.data.fields);
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to start payment.',
            'error',
        );
    } finally {
        isPayingNow.value = false;
    }
};

// ── Remove Household ──────────────────────────────────────────────────────
const confirmRemove = (household: Household) => {
    householdToRemove.value = household;
    showRemoveModal.value = true;
};

const removeHousehold = async () => {
    if (!householdToRemove.value) return;
    isRemoving.value = true;

    try {
        await axios.post(
            `${base()}/remove-household`,
            { user_id: householdToRemove.value.id },
            getHeaders(),
        );

        showFlash(
            `${householdToRemove.value.name} has been removed from estate billing.`,
        );
        showRemoveModal.value = false;
        householdToRemove.value = null;
        await fetchAll();
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to remove household.',
            'error',
        );
    } finally {
        isRemoving.value = false;
    }
};

// Payment filters
const paySearch = ref('');
const payStatusFilter = ref('all');
const payDateFrom = ref('');
const payDateTo = ref('');

const hasPayFilters = computed(
    () =>
        payStatusFilter.value !== 'all' ||
        paySearch.value !== '' ||
        payDateFrom.value !== '' ||
        payDateTo.value !== '',
);

const clearPayFilters = () => {
    paySearch.value = '';
    payStatusFilter.value = 'all';
    payDateFrom.value = '';
    payDateTo.value = '';
};

const filteredPayments = computed(() => {
    let result = [...payments.value];

    if (payStatusFilter.value !== 'all') {
        result = result.filter((p) => p.status === payStatusFilter.value);
    }

    if (paySearch.value.trim()) {
        const q = paySearch.value.toLowerCase();
        result = result.filter((p) =>
            (p.merchant_reference ?? '').toLowerCase().includes(q),
        );
    }

    if (payDateFrom.value) {
        const from = new Date(payDateFrom.value);
        result = result.filter((p) => new Date(p.created_at) >= from);
    }

    if (payDateTo.value) {
        const to = new Date(payDateTo.value);
        to.setHours(23, 59, 59);
        result = result.filter((p) => new Date(p.created_at) <= to);
    }

    return result;
});
</script>

<template>
    <Head :title="`${channel?.name} · Estate Billing`" />
    <AppLayout>
        <div class="page-root">
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Estate Billing</div>
                    <h1 class="page-header__title">{{ channel?.name }}</h1>
                </div>
                <button class="btn-icon" @click="fetchAll" title="Refresh">
                    <RefreshCw v-if="false" />
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width="16"
                        height="16"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M21 12a9 9 0 1 1-2.64-6.36" />
                        <path d="M21 3v6h-6" />
                    </svg>
                </button>
            </div>

            <!-- LOADING -->
            <div v-if="isLoading" class="table-card">
                <div class="empty-state">
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
                        >Loading billing data…</span
                    >
                </div>
            </div>

            <template v-else-if="summary">
                <!-- DUE SOON WARNING -->
                <div v-if="isDueSoon" class="alert-banner alert-banner--warn">
                    <AlertTriangle :size="16" stroke-width="2" />
                    <span>
                        <strong>
                            Payment due
                            {{
                                daysUntilDue === 0
                                    ? 'today'
                                    : `in ${daysUntilDue} day${daysUntilDue !== 1 ? 's' : ''}`
                            }}.
                        </strong>
                        Please submit your EFT proof to keep all households
                        active.
                    </span>
                </div>

                <!-- OVERDUE WARNING -->
                <div v-if="isPastDue" class="alert-banner alert-banner--danger">
                    <AlertTriangle :size="16" stroke-width="2" />
                    <span>
                        <strong>
                            Payment overdue by {{ daysOverdue }} day{{
                                daysOverdue !== 1 ? 's' : ''
                            }}.
                        </strong>
                        Household access may be suspended until payment is
                        confirmed.
                    </span>
                </div>

                <!-- SUMMARY -->
                <div class="stat-row">
                    <div class="stat-card">
                        <div class="stat-card__label">Opted-In Households</div>
                        <div class="stat-card__value">
                            {{ summary.household_count }}
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card__label">
                            {{
                                summary.status === 'active'
                                    ? 'Current Period'
                                    : 'Amount Due'
                            }}
                        </div>
                        <div class="stat-card__value stat-card__value--orange">
                            {{
                                summary.status === 'active'
                                    ? 'Paid'
                                    : fmt(summary.total_amount)
                            }}
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card__label">Period Ends</div>
                        <div class="stat-card__value">
                            {{ formatDate(summary.current_period_end) }}
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card__label">Billing Status</div>
                        <div class="stat-card__value" :class="statusConfig.cls">
                            {{ statusConfig.label }}
                        </div>
                    </div>
                </div>

                <!-- PERIOD + PAY PANEL -->
                <div class="table-card pay-panel">
                    <div class="pay-panel__info">
                        <div class="pay-panel__label">Billing Period</div>
                        <div class="pay-panel__dates">
                            {{ formatDate(summary.current_period_start) }}
                            &rarr;
                            {{ formatDate(summary.current_period_end) }}
                        </div>
                        <div class="pay-panel__rate">
                            {{ summary.household_count }} households ×
                            {{ fmt(summary.amount_per_household) }}
                            <template v-if="summary.linked_account_count">
                                + {{ summary.linked_account_count }} linked ×
                                {{ fmt(summary.amount_per_linked_account) }}
                            </template>
                            = <strong>{{ fmt(summary.total_amount) }}</strong>
                        </div>
                    </div>
                    <div class="pay-panel__actions">
                        <button
                            class="btn-ghost btn-ghost--outline"
                            :disabled="summary.status === 'cancelled'"
                            @click="openEftModal"
                        >
                            <Upload :size="15" stroke-width="2" />
                            Submit EFT Proof
                        </button>
                        <button
                            class="btn-primary"
                            :disabled="
                                summary.status === 'cancelled' || isPayingNow
                            "
                            @click="payNow"
                        >
                            <span v-if="isPayingNow" class="btn-spinner"></span>
                            <template v-else>
                                <CreditCard :size="15" stroke-width="2" />
                                Pay Now
                            </template>
                        </button>
                    </div>
                </div>

                <!-- TABS -->
                <div class="filter-bar">
                    <div class="filter-bar__chips">
                        <button
                            class="chip"
                            :class="{
                                'chip--active': activeTab === 'households',
                            }"
                            @click="activeTab = 'households'"
                        >
                            Households ({{ households.length }})
                        </button>
                        <button
                            class="chip"
                            :class="{
                                'chip--active': activeTab === 'payments',
                            }"
                            @click="activeTab = 'payments'"
                        >
                            Payment History ({{ payments.length }})
                        </button>
                    </div>
                </div>

                <!-- HOUSEHOLDS TAB -->
                <div v-if="activeTab === 'households'" class="table-card">
                    <div v-if="!households.length" class="empty-state">
                        <p class="empty-state__title">
                            No opted-in households yet
                        </p>
                        <p class="empty-state__sub">
                            Households in this channel will see an opt-in banner
                            in the app.
                        </p>
                    </div>

                    <table v-else class="data-table">
                        <thead>
                            <tr>
                                <th>Household</th>
                                <th>Unit</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="hh in households" :key="hh.id">
                                <td>
                                    <div class="person-cell">
                                        <div class="avatar">
                                            {{ initials(hh.name) }}
                                        </div>
                                        <div>
                                            <div class="td-announce__title">
                                                {{ hh.name }}
                                            </div>
                                            <div class="td-announce__sub">
                                                {{ hh.email }}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="td-time">
                                    {{ hh.unit_number ?? '—' }}
                                </td>
                                <td>
                                    <span
                                        class="type-badge"
                                        :class="
                                            householdBadge(
                                                hh.subscription_status,
                                            ).cls
                                        "
                                    >
                                        {{
                                            householdBadge(
                                                hh.subscription_status,
                                            ).label
                                        }}
                                    </span>
                                </td>
                                <td>
                                    <div
                                        style="
                                            display: flex;
                                            justify-content: flex-end;
                                        "
                                    >
                                        <button
                                            class="btn-ghost"
                                            style="padding: 7px 14px"
                                            @click="confirmRemove(hh)"
                                        >
                                            <Trash2
                                                :size="14"
                                                stroke-width="2"
                                            />
                                            Remove
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- PAYMENTS TAB -->
                <div v-if="activeTab === 'payments'">
                    <div class="table-card pay-filters">
                        <div class="pf-search-wrap">
                            <input
                                class="pf-search"
                                type="text"
                                v-model="paySearch"
                                placeholder="Search reference..."
                            />
                        </div>
                        <select class="pf-select" v-model="payStatusFilter">
                            <option value="all">All Statuses</option>
                            <option value="paid">Paid</option>
                            <option value="pending_review">
                                Pending Review
                            </option>
                            <option value="rejected">Rejected</option>
                            <option value="failed">Failed</option>
                        </select>
                        <div class="pf-date-wrap">
                            <label class="pf-date-label">From</label>
                            <input
                                class="pf-select"
                                type="date"
                                v-model="payDateFrom"
                            />
                        </div>
                        <div class="pf-date-wrap">
                            <label class="pf-date-label">To</label>
                            <input
                                class="pf-select"
                                type="date"
                                v-model="payDateTo"
                            />
                        </div>
                        <button
                            v-if="hasPayFilters"
                            class="btn-ghost"
                            @click="clearPayFilters"
                        >
                            ✕ Clear
                        </button>
                    </div>

                    <div class="table-card">
                        <div
                            v-if="!filteredPayments.length"
                            class="empty-state"
                        >
                            <p class="empty-state__title">No payments found</p>
                            <p class="empty-state__sub">
                                Try adjusting your filters.
                            </p>
                        </div>

                        <table v-else class="data-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Details</th>
                                    <th>Status</th>
                                    <th style="text-align: right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="payment in filteredPayments"
                                    :key="payment.id"
                                >
                                    <td class="td-announce__title">
                                        {{ payment.merchant_reference ?? '—' }}
                                    </td>
                                    <td>
                                        <div class="td-announce__sub">
                                            {{
                                                payment.payment_method?.toUpperCase()
                                            }}
                                            ·
                                            {{ payment.household_count }}
                                            households · Submitted
                                            {{ formatDate(payment.created_at) }}
                                        </div>
                                        <div
                                            v-if="payment.paid_at"
                                            class="td-announce__sub"
                                        >
                                            Approved
                                            {{ formatDate(payment.paid_at) }}
                                        </div>
                                        <div
                                            v-if="payment.notes"
                                            class="td-announce__sub pay-note"
                                        >
                                            "{{ payment.notes }}"
                                        </div>
                                    </td>
                                    <td>
                                        <span
                                            class="type-badge"
                                            :class="
                                                paymentBadge(payment.status).cls
                                            "
                                        >
                                            {{
                                                paymentBadge(payment.status)
                                                    .label
                                            }}
                                        </span>
                                    </td>
                                    <td class="pay-amount-cell">
                                        {{ fmt(payment.amount) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>

            <!-- NO SUBSCRIPTION YET -->
            <div v-else-if="!isLoading" class="table-card">
                <div class="empty-state">
                    <Building2 :size="32" stroke-width="1.5" color="#94a3b8" />
                    <p class="empty-state__title">
                        Estate billing not activated
                    </p>
                    <p class="empty-state__sub">
                        Please contact Echo Link admin to activate estate
                        billing for this channel.
                    </p>
                </div>
            </div>
        </div>

        <!-- ── EFT MODAL ───────────────────────────────────────────────── -->
        <transition name="modal">
            <div
                v-if="showEftModal"
                class="modal-backdrop"
                @click.self="showEftModal = false"
            >
                <div
                    class="confirm-modal confirm-modal--wide confirm-modal--form"
                >
                    <h2 class="confirm-modal__title">Submit EFT Proof</h2>
                    <p class="confirm-modal__body">
                        Transfer
                        <strong>{{ fmt(summary?.total_amount) }}</strong> to the
                        Echo Link bank account, then upload your proof of
                        payment below.
                    </p>

                    <div class="payout-ref-banner">
                        <div class="prb-top">
                            <span class="prb-label">Payment Reference</span>
                            <span class="prb-hint"
                                >Use this as your beneficiary reference</span
                            >
                        </div>
                        <div class="prb-row">
                            <span class="prb-value">{{ eftRef }}</span>
                            <button class="prb-copy-btn" @click="copyEftRef">
                                <Copy :size="13" />
                                {{ copiedRef ? 'Copied!' : 'Copy' }}
                            </button>
                        </div>
                    </div>

                    <div class="bank-details-box">
                        <div class="bdb-title">Echo Link Bank Details</div>
                        <div class="bdb-row">
                            <span>Bank</span><strong>FNB</strong>
                        </div>
                        <div class="bdb-row">
                            <span>Account Name</span
                            ><strong>Echo Link (Pty) Ltd</strong>
                        </div>
                        <div class="bdb-row">
                            <span>Account Number</span
                            ><strong>62XXXXXXXXXX</strong>
                        </div>
                        <div class="bdb-row">
                            <span>Branch Code</span><strong>250655</strong>
                        </div>
                        <div class="bdb-row">
                            <span>Account Type</span><strong>Cheque</strong>
                        </div>
                        <div class="bdb-row">
                            <span>Amount</span>
                            <strong class="orange-text">{{
                                fmt(summary?.total_amount)
                            }}</strong>
                        </div>
                    </div>

                    <div class="mf">
                        <label class="ml"
                            >Note / Reference used in transfer</label
                        >
                        <input
                            class="mi"
                            type="text"
                            v-model="eftForm.note"
                            :placeholder="eftRef"
                        />
                        <p class="mi-hint">
                            Edit if you used a different reference in your
                            banking app.
                        </p>
                    </div>

                    <div class="mf">
                        <label class="ml">Proof of Payment</label>
                        <div
                            class="file-drop"
                            @click="
                                ($refs.proofInput as HTMLInputElement)?.click()
                            "
                        >
                            <Upload
                                :size="20"
                                stroke-width="1.5"
                                color="#94a3b8"
                            />
                            <span v-if="eftForm.proof">{{
                                eftForm.proof.name
                            }}</span>
                            <span v-else class="file-hint"
                                >Click to upload PDF, JPG or PNG (max 5MB)</span
                            >
                        </div>
                        <input
                            ref="proofInput"
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png"
                            style="display: none"
                            @change="onProofSelected"
                        />
                    </div>

                    <div class="confirm-modal__actions">
                        <button class="btn-ghost" @click="showEftModal = false">
                            Cancel
                        </button>
                        <button
                            class="btn-primary"
                            style="flex: 1.4; justify-content: center"
                            :disabled="
                                isSubmittingEft ||
                                !eftForm.proof ||
                                !eftForm.note
                            "
                            @click="submitEft"
                        >
                            <span
                                v-if="isSubmittingEft"
                                class="btn-spinner"
                            ></span>
                            <span v-else>Submit Payment</span>
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- ── REMOVE HOUSEHOLD MODAL ──────────────────────────────────── -->
        <transition name="modal">
            <div
                v-if="showRemoveModal"
                class="modal-backdrop"
                @click.self="showRemoveModal = false"
            >
                <div class="confirm-modal">
                    <div class="confirm-modal__icon">
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-7 w-7 text-red-500"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="1.5"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M6 18L18 6M6 6l12 12"
                            />
                        </svg>
                    </div>
                    <h2 class="confirm-modal__title">Remove Household</h2>
                    <p class="confirm-modal__body">
                        Are you sure you want to remove
                        <strong>{{ householdToRemove?.name }}</strong> from
                        estate billing? They will be moved back to individual
                        billing.
                    </p>
                    <div class="confirm-modal__actions">
                        <button
                            class="btn-ghost"
                            @click="showRemoveModal = false"
                        >
                            Cancel
                        </button>
                        <button
                            class="btn-danger"
                            :disabled="isRemoving"
                            @click="removeHousehold"
                        >
                            <span v-if="isRemoving" class="btn-spinner"></span>
                            <span v-else>Yes, Remove</span>
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <transition name="toast">
            <div
                v-if="flash"
                class="toast"
                :class="flash.type === 'error' ? 'toast--error' : ''"
            >
                {{ flash.msg }}
            </div>
        </transition>
    </AppLayout>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap');

.page-root,
.modal-backdrop,
.toast {
    --c-primary: #ea580c;
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

/* Header */
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
.btn-icon {
    padding: 9px;
    border: 1px solid #e4e8ef;
    border-radius: 10px;
    background: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    color: #64748b;
    transition: all 0.15s;
}
.btn-icon:hover {
    border-color: #ea580c;
    color: #ea580c;
}

/* Alert banners */
.alert-banner {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 13px 16px;
    border-radius: 16px;
    font-size: 13px;
    line-height: 1.5;
}
.alert-banner--warn {
    background: #fff7ed;
    border: 1px solid #fed7aa;
    color: #c2410c;
}
.alert-banner--danger {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #dc2626;
}

/* Stats */
.stat-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
}
.stat-card {
    background: #fff;
    border: 1px solid #e4e8ef;
    border-radius: 16px;
    padding: 20px 22px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
}
.stat-card__label {
    font-size: 11px;
    font-weight: 600;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.stat-card__value {
    font-size: 26px;
    font-weight: 800;
    color: #1a2332;
    line-height: 1.15;
    letter-spacing: -0.5px;
}
.stat-card__value--red {
    color: #dc2626;
}
.stat-card__value--green {
    color: #16a34a;
}
.stat-card__value--orange {
    color: #ea580c;
}

/* Pay panel */
.pay-panel {
    padding: 18px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}
.pay-panel__info {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.pay-panel__label {
    font-size: 11px;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.pay-panel__dates {
    font-size: 14px;
    font-weight: 700;
    color: #1a2332;
}
.pay-panel__rate {
    font-size: 13px;
    color: #64748b;
}
.pay-panel__actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

/* Filter bar / tabs */
.filter-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.filter-bar__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.chip {
    padding: 7px 16px;
    border-radius: 20px;
    border: 1px solid #e4e8ef;
    background: #fff;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
    transition: all 0.15s;
    font-family: 'DM Sans', system-ui, sans-serif;
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

/* Table card */
.table-card {
    background: #fff;
    border: 1px solid #e4e8ef;
    border-radius: 16px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
    overflow: hidden;
}
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 64px 24px;
    gap: 8px;
    text-align: center;
}
.empty-state__title {
    font-size: 15px;
    font-weight: 700;
    color: #1a2332;
    margin: 0;
}
.empty-state__sub {
    font-size: 13px;
    color: #64748b;
    margin: 0;
    max-width: 340px;
    line-height: 1.6;
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

.person-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}
.avatar {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    background: #fff7ed;
    border: 1px solid #fed7aa;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 800;
    color: #ea580c;
    flex-shrink: 0;
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
.bg-emerald-50 {
    background: #ecfdf5;
}
.text-emerald-700 {
    color: #047857;
}
.bg-red-50 {
    background: #fef2f2;
}
.text-red-600 {
    color: #dc2626;
}
.bg-amber-50 {
    background: #fffbeb;
}
.text-amber-700 {
    color: #b45309;
}
.bg-slate-100 {
    background: #f1f5f9;
}
.text-slate-500 {
    color: #64748b;
}

.td-announce__title {
    font-weight: 600;
    color: #1a2332;
}
.td-announce__sub {
    font-size: 12px;
    color: #94a3b8;
}
.pay-note {
    font-style: italic;
}
.td-time {
    color: #94a3b8;
    white-space: nowrap;
    font-size: 12px;
}
.pay-amount-cell {
    text-align: right;
    font-weight: 800;
    color: #ea580c;
    white-space: nowrap;
}

/* Buttons */
.btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #ea580c !important;
    color: #fff !important;
    border: none;
    border-radius: 10px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.18s;
    font-family: 'DM Sans', system-ui, sans-serif;
}
.btn-primary:hover:not(:disabled) {
    background: #c2410c !important;
}
.btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.btn-ghost {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #f1f5f9;
    color: #64748b;
    border: none;
    border-radius: 10px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
    font-family: 'DM Sans', system-ui, sans-serif;
}
.btn-ghost:hover:not(:disabled) {
    background: #e2e8f0;
}
.btn-ghost:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.btn-ghost--outline {
    background: #fff;
    color: #ea580c;
    border: 1px solid #ea580c;
}
.btn-ghost--outline:hover:not(:disabled) {
    background: #fff7ed;
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
    font-family: 'DM Sans', system-ui, sans-serif;
}
.btn-danger:hover:not(:disabled) {
    background: #b91c1c;
}
.btn-danger:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.btn-spinner {
    width: 14px;
    height: 14px;
    border: 2px solid rgba(255, 255, 255, 0.35);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
    display: inline-block;
}

/* Modal */
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
.confirm-modal {
    background: #fff !important;
    border-radius: 20px;
    width: 100%;
    max-width: 380px;
    padding: 32px 28px 26px;
    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.18);
    border: 1px solid #e4e8ef;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 10px;
    max-height: 90vh;
    overflow-y: auto;
}
.confirm-modal--wide {
    max-width: 520px;
}
.confirm-modal--form {
    align-items: stretch;
    text-align: left;
}
.confirm-modal__icon {
    width: 60px;
    height: 60px;
    background: #fef2f2;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 6px;
}
.confirm-modal__title {
    font-size: 17px;
    font-weight: 800;
    color: #1a2332;
    margin: 0;
}
.confirm-modal__body {
    font-size: 13px;
    color: #64748b;
    line-height: 1.6;
    margin: 0 0 8px;
}
.confirm-modal__actions {
    display: flex;
    gap: 10px;
    width: 100%;
    margin-top: 4px;
}
.confirm-modal__actions .btn-ghost {
    flex: 1;
    justify-content: center;
}
.confirm-modal__actions .btn-danger {
    flex: 1.4;
    justify-content: center;
}

/* EFT reference banner */
.payout-ref-banner {
    background: #fff7ed;
    border: 1px solid #fed7aa;
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 16px;
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
    letter-spacing: 0.8px;
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
    font-size: 18px;
    font-weight: 800;
    color: #c2410c;
    letter-spacing: 0.5px;
    font-family: 'Courier New', monospace;
}
.prb-copy-btn {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 6px 14px;
    background: #ea580c;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    font-family: 'DM Sans', system-ui, sans-serif;
    white-space: nowrap;
    flex-shrink: 0;
}
.prb-copy-btn:hover {
    background: #c2410c;
}

/* Bank details box */
.bank-details-box {
    background: #f8fafc;
    border: 1px solid #e4e8ef;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 16px;
}
.bdb-title {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #94a3b8;
    margin-bottom: 10px;
}
.bdb-row {
    display: flex;
    justify-content: space-between;
    padding: 5px 0;
    font-size: 13px;
    color: #475569;
    border-bottom: 1px solid #eef1f5;
}
.bdb-row:last-child {
    border-bottom: none;
}
.orange-text {
    color: #ea580c;
}

/* File drop / form fields */
.file-drop {
    border: 1.5px dashed #e4e8ef;
    border-radius: 10px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    transition: all 0.15s;
    font-size: 13px;
    color: #64748b;
}
.file-drop:hover {
    border-color: #ea580c;
    color: #ea580c;
}
.file-hint {
    font-size: 12px;
    color: #94a3b8;
}
.mf {
    margin-bottom: 14px;
}
.ml {
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    display: block;
    margin-bottom: 6px;
}
.mi {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #e4e8ef;
    border-radius: 10px;
    font-size: 14px;
    font-family: 'DM Sans', system-ui, sans-serif;
    color: #1a2332;
    outline: none;
    transition: all 0.15s;
    box-sizing: border-box;
}
.mi:focus {
    border-color: #ea580c;
    box-shadow: 0 0 0 3px rgba(234, 88, 12, 0.1);
}
.mi-hint {
    font-size: 12px;
    color: #94a3b8;
    margin: 5px 0 0;
    line-height: 1.5;
}

/* Payment filters bar */
.pay-filters {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    padding: 14px 18px;
    margin-bottom: 16px;
}
.pf-search-wrap {
    flex: 1;
    min-width: 160px;
}
.pf-search {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #e4e8ef;
    border-radius: 10px;
    font-size: 13px;
    color: #1a2332;
    background: #f8fafc;
    outline: none;
    font-family: 'DM Sans', system-ui, sans-serif;
    box-sizing: border-box;
}
.pf-search:focus {
    border-color: #ea580c;
}
.pf-select {
    padding: 8px 12px;
    border: 1px solid #e4e8ef;
    border-radius: 10px;
    font-size: 13px;
    color: #1a2332;
    background: #f8fafc;
    outline: none;
    font-family: 'DM Sans', system-ui, sans-serif;
    cursor: pointer;
}
.pf-date-wrap {
    display: flex;
    align-items: center;
    gap: 6px;
}
.pf-date-label {
    font-size: 11px;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    white-space: nowrap;
}

/* Toast */
.toast {
    position: fixed;
    bottom: 28px;
    right: 28px;
    background: #1a2332;
    color: #f1f5f9;
    padding: 12px 18px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 600;
    z-index: 99999;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
    border-left: 3px solid #ea580c;
}
.toast--error {
    border-left-color: #dc2626;
}

/* Transitions */
.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.22s ease;
}
.modal-enter-active .confirm-modal,
.modal-leave-active .confirm-modal {
    transition:
        transform 0.22s ease,
        opacity 0.22s ease;
}
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
.modal-enter-from .confirm-modal,
.modal-leave-to .confirm-modal {
    transform: scale(0.97) translateY(12px);
}
.toast-enter-active,
.toast-leave-active {
    transition: all 0.25s ease;
}
.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateY(8px);
}
.spin {
    animation: spin 0.65s linear infinite;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

@media (max-width: 900px) {
    .stat-row {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 768px) {
    .stat-row {
        gap: 10px;
    }
}
@media (max-width: 640px) {
    .page-root {
        padding: 16px;
    }
    .table-card {
        overflow-x: auto;
    }
    .data-table {
        min-width: 560px;
    }
    .pay-panel {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

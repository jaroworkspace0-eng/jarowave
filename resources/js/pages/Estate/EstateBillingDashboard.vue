<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import {
    AlertTriangle,
    Ban,
    Building2,
    CalendarDays,
    CheckCircle,
    CircleDollarSign,
    Clock,
    Copy,
    CreditCard,
    FileText,
    RefreshCw,
    Trash2,
    Upload,
    Users,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

const auth = useAuthStore();

onMounted(() => {
    if (auth.user?.role !== 'estate_billing') {
        router.visit('/dashboard');
    }
});

// ── Types ─────────────────────────────────────────────────────────────────
interface ChannelSubscription {
    id: number;
    household_count: number;
    amount_per_household: number;
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
    setTimeout(() => (flash.value = null), 6000);
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
const statusConfig = computed(() => {
    switch (summary.value?.status) {
        case 'active':
            return { label: 'Active', cls: 'status-active', icon: CheckCircle };
        case 'pending':
            return {
                label: 'Pending Payment',
                cls: 'status-pending',
                icon: Clock,
            };
        case 'overdue':
            return {
                label: 'Overdue',
                cls: 'status-overdue',
                icon: AlertTriangle,
            };
        case 'cancelled':
            return { label: 'Cancelled', cls: 'status-cancelled', icon: Ban };
        default:
            return { label: '—', cls: '', icon: Clock };
    }
});

const daysUntilDue = computed(() => {
    if (!summary.value?.current_period_end) return null;
    const diff =
        new Date(summary.value.current_period_end).getTime() - Date.now();
    return Math.ceil(diff / (1000 * 60 * 60 * 24));
});

const isDueSoon = computed(
    () => daysUntilDue.value !== null && daysUntilDue.value <= 3,
);

// ── Data ──────────────────────────────────────────────────────────────────
const base = () =>
    `${import.meta.env.VITE_APP_URL}/api/channels/${channel.value!.id}/billing`;

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

// ── Remove Household ──────────────────────────────────────────────────────
const confirmRemove = (household: Household) => {
    householdToRemove.value = household;
    showRemoveModal.value = true;
};

const removeHousehold = async () => {
    if (!householdToRemove.value) return;
    isRemoving.value = true;

    try {
        // await axios.post(
        //     `${base()}/opt-out`,
        //     { user_id: householdToRemove.value.id },
        //     getHeaders(),
        // );
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
        paySearch.value ||
        payDateFrom.value ||
        payDateTo.value,
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
            p.merchant_reference?.toLowerCase().includes(q),
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
        <div class="eb-root">
            <!-- HEADER -->
            <div class="eb-header">
                <div class="eb-header-left">
                    <div class="eb-channel-icon">
                        <Building2 :size="20" stroke-width="2" />
                    </div>
                    <div>
                        <h1 class="eb-title">{{ channel?.name }}</h1>
                        <p class="eb-sub">Estate Billing Dashboard</p>
                    </div>
                </div>
                <button class="btn-icon" @click="fetchAll" title="Refresh">
                    <RefreshCw :size="16" stroke-width="2" />
                </button>
            </div>

            <!-- FLASH -->
            <div v-if="flash" :class="['flash', flash.type]">
                {{ flash.type === 'success' ? '✓' : '⚠' }} {{ flash.msg }}
            </div>

            <!-- LOADING -->
            <div v-if="isLoading" class="loading">
                <div class="spinner"></div>
                <p>Loading billing data…</p>
            </div>

            <template v-else-if="summary">
                <!-- DUE SOON WARNING -->
                <div
                    v-if="isDueSoon && summary.status !== 'cancelled'"
                    class="due-soon-banner"
                >
                    <AlertTriangle :size="16" stroke-width="2" />
                    <span>
                        <strong
                            >Payment due
                            {{
                                daysUntilDue === 0
                                    ? 'today'
                                    : `in ${daysUntilDue} day${daysUntilDue !== 1 ? 's' : ''}`
                            }}.</strong
                        >
                        Please submit your EFT proof to keep all households
                        active.
                    </span>
                </div>

                <!-- OVERDUE WARNING -->
                <div v-if="summary.status === 'overdue'" class="overdue-banner">
                    <AlertTriangle :size="16" stroke-width="2" />
                    <span>
                        <strong>Payment overdue.</strong>
                        Household access may be suspended until payment is
                        confirmed.
                    </span>
                </div>

                <!-- SUMMARY CARDS -->
                <div class="summary-row">
                    <div class="sum-card">
                        <div class="sum-icon blue">
                            <Users :size="18" stroke-width="2" />
                        </div>
                        <div>
                            <div class="sum-val">
                                {{ summary.household_count }}
                            </div>
                            <div class="sum-lbl">Opted-In Households</div>
                        </div>
                    </div>
                    <div class="sum-card">
                        <div class="sum-icon orange">
                            <CircleDollarSign :size="18" stroke-width="2" />
                        </div>
                        <div>
                            <div class="sum-val">
                                {{
                                    summary.status === 'active'
                                        ? 'Paid'
                                        : fmt(summary.total_amount)
                                }}
                            </div>
                            <div class="sum-lbl">
                                {{
                                    summary.status === 'active'
                                        ? 'Current Period'
                                        : 'Amount Due'
                                }}
                            </div>
                        </div>
                    </div>
                    <div class="sum-card">
                        <div class="sum-icon green">
                            <CalendarDays :size="18" stroke-width="2" />
                        </div>
                        <div>
                            <div class="sum-val">
                                {{ formatDate(summary.current_period_end) }}
                            </div>
                            <div class="sum-lbl">Period Ends</div>
                        </div>
                    </div>
                    <div class="sum-card">
                        <div
                            :class="[
                                'sum-icon',
                                statusConfig.cls.replace('status-', ''),
                            ]"
                        >
                            <component
                                :is="statusConfig.icon"
                                :size="18"
                                stroke-width="2"
                            />
                        </div>
                        <div>
                            <div :class="['sum-val', statusConfig.cls]">
                                {{ statusConfig.label }}
                            </div>
                            <div class="sum-lbl">Billing Status</div>
                        </div>
                    </div>
                </div>

                <!-- PERIOD + PAY ROW -->
                <div class="pay-row">
                    <div class="period-info">
                        <span class="period-label">Billing Period</span>
                        <span class="period-dates">
                            {{ formatDate(summary.current_period_start) }}
                            &rarr;
                            {{ formatDate(summary.current_period_end) }}
                        </span>
                        <span class="period-rate">
                            {{ summary.household_count }} households ×
                            {{ fmt(summary.amount_per_household) }} =
                            <strong>{{ fmt(summary.total_amount) }}</strong>
                        </span>
                    </div>
                    <div class="pay-actions">
                        <button
                            class="btn-eft"
                            :disabled="summary.status === 'cancelled'"
                            @click="openEftModal"
                        >
                            <Upload :size="15" stroke-width="2" />
                            Submit EFT Proof
                        </button>
                        <button
                            class="btn-paynow"
                            :disabled="summary.status === 'cancelled'"
                        >
                            <CreditCard :size="15" stroke-width="2" />
                            Pay Now
                        </button>
                    </div>
                </div>

                <!-- TABS -->
                <div class="tabs">
                    <button
                        :class="['tab', { active: activeTab === 'households' }]"
                        @click="activeTab = 'households'"
                    >
                        <Users :size="14" stroke-width="2" />
                        Households ({{ households.length }})
                    </button>
                    <button
                        :class="['tab', { active: activeTab === 'payments' }]"
                        @click="activeTab = 'payments'"
                    >
                        <FileText :size="14" stroke-width="2" />
                        Payment History ({{ payments.length }})
                    </button>
                </div>

                <!-- HOUSEHOLDS TAB -->
                <div v-if="activeTab === 'households'">
                    <div v-if="!households.length" class="empty-card">
                        <Users :size="32" stroke-width="1.5" color="#bbb" />
                        <div class="empty-title">
                            No opted-in households yet
                        </div>
                        <div class="empty-desc">
                            Households in this channel will see an opt-in banner
                            in the app.
                        </div>
                    </div>

                    <div v-else class="hh-list">
                        <div
                            v-for="hh in households"
                            :key="hh.id"
                            class="hh-card"
                        >
                            <div class="hh-avatar">
                                {{ hh.name.charAt(0).toUpperCase() }}
                            </div>
                            <div class="hh-info">
                                <div class="hh-name">{{ hh.name }}</div>
                                <div class="hh-meta">{{ hh.email }}</div>
                                <div class="hh-meta" v-if="hh.unit_number">
                                    Unit {{ hh.unit_number }}
                                </div>
                            </div>
                            <div class="hh-right">
                                <span
                                    :class="[
                                        'sub-badge',
                                        hh.subscription_status,
                                    ]"
                                >
                                    {{ hh.subscription_status }}
                                </span>
                                <button
                                    class="btn-remove"
                                    @click="confirmRemove(hh)"
                                    title="Remove from estate billing"
                                >
                                    <Trash2 :size="14" stroke-width="2" />
                                    Remove
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PAYMENTS TAB -->
                <!-- PAYMENTS TAB -->
                <div v-if="activeTab === 'payments'">
                    <!-- Filters -->
                    <div class="pay-filters">
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
                            class="btn-clear-pay"
                            @click="clearPayFilters"
                        >
                            ✕ Clear
                        </button>
                    </div>

                    <div v-if="!filteredPayments.length" class="empty-card">
                        <FileText :size="32" stroke-width="1.5" color="#bbb" />
                        <div class="empty-title">No payments found</div>
                        <div class="empty-desc">
                            Try adjusting your filters.
                        </div>
                    </div>

                    <div v-else class="payment-list">
                        <div
                            v-for="payment in filteredPayments"
                            :key="payment.id"
                            :class="['payment-card', payment.status]"
                        >
                            <div class="pay-left">
                                <div class="pay-ref">
                                    {{ payment.merchant_reference ?? '—' }}
                                </div>
                                <div class="pay-meta">
                                    {{ payment.payment_method?.toUpperCase() }}
                                    · {{ payment.household_count }} households ·
                                    Submitted
                                    {{ formatDate(payment.created_at) }}
                                </div>
                                <div class="pay-meta" v-if="payment.paid_at">
                                    Approved {{ formatDate(payment.paid_at) }}
                                </div>
                                <div
                                    class="pay-meta pay-note"
                                    v-if="payment.notes"
                                >
                                    "{{ payment.notes }}"
                                </div>
                            </div>
                            <div class="pay-right">
                                <div class="pay-amount">
                                    {{ fmt(payment.amount) }}
                                </div>
                                <span :class="['pay-badge', payment.status]">
                                    {{
                                        payment.status === 'pending_review'
                                            ? 'Pending Review'
                                            : payment.status === 'paid'
                                              ? 'Approved'
                                              : payment.status
                                    }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- NO SUBSCRIPTION YET -->
            <div v-else-if="!isLoading" class="empty-card">
                <Building2 :size="32" stroke-width="1.5" color="#bbb" />
                <div class="empty-title">Estate billing not activated</div>
                <div class="empty-desc">
                    Please contact Echo Link admin to activate estate billing
                    for this channel.
                </div>
            </div>

            <!-- ── EFT MODAL ───────────────────────────────────────────────── -->
            <div
                v-if="showEftModal"
                class="modal-overlay"
                @click.self="showEftModal = false"
            >
                <div class="modal">
                    <div class="modal-title">Submit EFT Proof</div>
                    <p class="modal-note">
                        Transfer
                        <strong>{{ fmt(summary?.total_amount) }}</strong> to the
                        Echo Link bank account, then upload your proof of
                        payment below.
                    </p>

                    <!-- EFT Reference Banner -->
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

                    <!-- Echo Link Bank Details -->
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
                            <span>Amount</span
                            ><strong class="orange-text">{{
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
                                color="#aaa"
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

                    <div class="modal-actions">
                        <button class="btn-ghost" @click="showEftModal = false">
                            Cancel
                        </button>
                        <button
                            class="btn-process"
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

            <!-- ── REMOVE HOUSEHOLD MODAL ──────────────────────────────────── -->
            <div
                v-if="showRemoveModal"
                class="modal-overlay"
                @click.self="showRemoveModal = false"
            >
                <div class="modal modal-sm">
                    <div class="modal-title">Remove Household</div>
                    <p class="modal-note">
                        Are you sure you want to remove
                        <strong>{{ householdToRemove?.name }}</strong> from
                        estate billing? They will be moved back to individual
                        billing.
                    </p>
                    <div class="modal-actions">
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
        </div>
    </AppLayout>
</template>

<style scoped>
.eb-root {
    max-width: 1400px;
    margin: 0 auto;
    padding: 36px 24px 64px;
    font-family: 'Segoe UI', sans-serif;
    color: #111;
    width: 100%;
}

.eb-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
}
.eb-header-left {
    display: flex;
    align-items: center;
    gap: 14px;
}
.eb-channel-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: linear-gradient(135deg, #f97316, #ea580c);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.eb-title {
    font-size: 22px;
    font-weight: 800;
    letter-spacing: -0.5px;
    margin: 0 0 2px;
}
.eb-sub {
    font-size: 13px;
    color: #888;
    margin: 0;
}

.btn-icon {
    padding: 8px;
    border: 1.5px solid #e5e5e5;
    border-radius: 10px;
    background: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    color: #555;
    transition: all 0.2s;
}
.btn-icon:hover {
    border-color: #f97316;
    color: #f97316;
}

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

.loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    padding: 80px 0;
    color: #999;
}

/* Banners */
.due-soon-banner {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    background: #fff7ed;
    border: 1.5px solid #fed7aa;
    border-radius: 12px;
    font-size: 13px;
    color: #c2410c;
    margin-bottom: 16px;
}
.overdue-banner {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    background: #fef2f2;
    border: 1.5px solid #fecaca;
    border-radius: 12px;
    font-size: 13px;
    color: #dc2626;
    margin-bottom: 16px;
}

/* Summary */
.summary-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 16px;
}
.sum-card {
    background: #fff;
    border: 1.5px solid #ebebeb;
    border-radius: 14px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
}
.sum-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.sum-icon.blue {
    background: rgba(37, 99, 235, 0.1);
    color: #2563eb;
}
.sum-icon.orange {
    background: rgba(249, 115, 22, 0.1);
    color: #f97316;
}
.sum-icon.green {
    background: rgba(22, 163, 74, 0.1);
    color: #16a34a;
}
.sum-icon.active {
    background: rgba(22, 163, 74, 0.1);
    color: #16a34a;
}
.sum-icon.pending {
    background: rgba(249, 115, 22, 0.1);
    color: #f97316;
}
.sum-icon.overdue {
    background: rgba(220, 38, 38, 0.1);
    color: #dc2626;
}
.sum-icon.cancelled {
    background: rgba(100, 100, 100, 0.1);
    color: #888;
}
.sum-val {
    font-size: 18px;
    font-weight: 800;
    color: #111;
    letter-spacing: -0.5px;
}
.sum-val.status-active {
    color: #16a34a;
}
.sum-val.status-pending {
    color: #f97316;
}
.sum-val.status-overdue {
    color: #dc2626;
}
.sum-val.status-cancelled {
    color: #888;
}
.sum-lbl {
    font-size: 11px;
    color: #aaa;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 2px;
}

/* Pay row */
.pay-row {
    background: #fff;
    border: 1.5px solid #ebebeb;
    border-radius: 14px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.period-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.period-label {
    font-size: 11px;
    font-weight: 700;
    color: #aaa;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.period-dates {
    font-size: 14px;
    font-weight: 600;
    color: #111;
}
.period-rate {
    font-size: 13px;
    color: #888;
}
.pay-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.btn-eft {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 10px 18px;
    background: #fff;
    color: #f97316;
    border: 1.5px solid #f97316;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    font-family: 'Segoe UI', sans-serif;
    transition: all 0.2s;
}
.btn-eft:hover:not(:disabled) {
    background: rgba(249, 115, 22, 0.06);
}
.btn-eft:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.btn-paynow {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 10px 18px;
    background: #f97316;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    font-family: 'Segoe UI', sans-serif;
    transition: all 0.2s;
}
.btn-paynow:hover:not(:disabled) {
    background: #ea580c;
}
.btn-paynow:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

/* Tabs */
.tabs {
    display: flex;
    gap: 4px;
    margin-bottom: 16px;
    border-bottom: 1.5px solid #ebebeb;
}
.tab {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 10px 16px;
    background: none;
    border: none;
    border-bottom: 2.5px solid transparent;
    margin-bottom: -1.5px;
    font-size: 13px;
    font-weight: 600;
    color: #888;
    cursor: pointer;
    font-family: 'Segoe UI', sans-serif;
    transition: all 0.15s;
}
.tab:hover {
    color: #f97316;
}
.tab.active {
    color: #f97316;
    border-bottom-color: #f97316;
}

/* Households */
.hh-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.hh-card {
    background: #fff;
    border: 1.5px solid #ebebeb;
    border-radius: 14px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
.hh-avatar {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: linear-gradient(135deg, #f97316, #ea580c);
    color: #fff;
    font-size: 16px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.hh-info {
    flex: 1;
    min-width: 140px;
}
.hh-name {
    font-size: 14px;
    font-weight: 700;
    color: #111;
}
.hh-meta {
    font-size: 12px;
    color: #888;
}
.hh-right {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-left: auto;
    flex-wrap: wrap;
}

.sub-badge {
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 11px;
    font-weight: 700;
    text-transform: capitalize;
}
.sub-badge.active {
    background: rgba(22, 163, 74, 0.1);
    color: #16a34a;
    border: 1px solid rgba(22, 163, 74, 0.2);
}
.sub-badge.trialing {
    background: rgba(37, 99, 235, 0.1);
    color: #2563eb;
    border: 1px solid rgba(37, 99, 235, 0.2);
}
.sub-badge.past_due {
    background: rgba(220, 38, 38, 0.1);
    color: #dc2626;
    border: 1px solid rgba(220, 38, 38, 0.2);
}
.sub-badge.cancelled {
    background: rgba(100, 100, 100, 0.1);
    color: #888;
    border: 1px solid #e5e5e5;
}

.btn-remove {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 6px 12px;
    background: rgba(220, 38, 38, 0.06);
    color: #dc2626;
    border: 1px solid rgba(220, 38, 38, 0.2);
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    font-family: 'Segoe UI', sans-serif;
    transition: all 0.15s;
}
.btn-remove:hover {
    background: rgba(220, 38, 38, 0.12);
}

/* Payments */
.payment-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.payment-card {
    background: #fff;
    border: 1.5px solid #ebebeb;
    border-radius: 14px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
}
.pay-left {
    flex: 1;
}
.pay-ref {
    font-size: 14px;
    font-weight: 700;
    color: #111;
}
.pay-meta {
    font-size: 12px;
    color: #888;
    margin-top: 2px;
}
.pay-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 6px;
}
.pay-amount {
    font-size: 16px;
    font-weight: 800;
    color: #f97316;
}
.pay-badge {
    padding: 3px 10px;
    border-radius: 100px;
    font-size: 11px;
    font-weight: 700;
    text-transform: capitalize;
}
.pay-badge.paid {
    background: rgba(22, 163, 74, 0.1);
    color: #16a34a;
    border: 1px solid rgba(22, 163, 74, 0.2);
}
.pay-badge.pending {
    background: rgba(249, 115, 22, 0.1);
    color: #f97316;
    border: 1px solid rgba(249, 115, 22, 0.2);
}
.pay-badge.failed {
    background: rgba(220, 38, 38, 0.1);
    color: #dc2626;
    border: 1px solid rgba(220, 38, 38, 0.2);
}

.pay-badge.pending_review {
    background: rgba(249, 115, 22, 0.1);
    color: #f97316;
    border: 1px solid rgba(249, 115, 22, 0.2);
}
.pay-badge.rejected {
    background: rgba(220, 38, 38, 0.1);
    color: #dc2626;
    border: 1px solid rgba(220, 38, 38, 0.2);
}

/* Empty */
.empty-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 64px 24px;
    text-align: center;
    background: #fff;
    border: 1.5px solid #ebebeb;
    border-radius: 16px;
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

/* Modal */
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
    max-width: 520px;
    box-shadow: 0 24px 64px rgba(0, 0, 0, 0.18);
    max-height: 90vh;
    overflow-y: auto;
}
.modal-sm {
    max-width: 400px;
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
    margin-bottom: 20px;
    line-height: 1.5;
}
.modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 22px;
}

/* Payout ref banner */
.payout-ref-banner {
    background: #fff7ed;
    border: 1.5px solid #fed7aa;
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 18px;
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
    background: #f97316;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    font-family: 'Segoe UI', sans-serif;
    transition: all 0.15s;
    white-space: nowrap;
    flex-shrink: 0;
}
.prb-copy-btn:hover {
    background: #ea580c;
}

/* Bank details box */
.bank-details-box {
    background: #f9f9f9;
    border: 1.5px solid #ebebeb;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 18px;
}
.bdb-title {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #888;
    margin-bottom: 10px;
}
.bdb-row {
    display: flex;
    justify-content: space-between;
    padding: 5px 0;
    font-size: 13px;
    color: #555;
    border-bottom: 1px solid #f0f0f0;
}
.bdb-row:last-child {
    border-bottom: none;
}

/* File drop */
.file-drop {
    border: 1.5px dashed #e5e5e5;
    border-radius: 10px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    transition: all 0.15s;
    font-size: 13px;
    color: #888;
}
.file-drop:hover {
    border-color: #f97316;
    color: #f97316;
}
.file-hint {
    font-size: 12px;
    color: #bbb;
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
.mi-hint {
    font-size: 12px;
    color: #aaa;
    margin: 5px 0 0;
    line-height: 1.5;
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

.btn-process {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: #f97316;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    font-family: 'Segoe UI', sans-serif;
    transition: all 0.2s;
}
.btn-process:hover:not(:disabled) {
    background: #ea580c;
}
.btn-process:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-danger {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: #dc2626;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    font-family: 'Segoe UI', sans-serif;
    transition: all 0.2s;
}
.btn-danger:hover:not(:disabled) {
    background: #b91c1c;
}
.btn-danger:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.orange-text {
    color: #f97316;
}

.spinner {
    width: 28px;
    height: 28px;
    border: 3px solid #f0f0f0;
    border-top-color: #f97316;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
}
.btn-spinner {
    width: 14px;
    height: 14px;
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

@media (max-width: 900px) {
    .summary-row {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 640px) {
    .pay-row {
        flex-direction: column;
        align-items: flex-start;
    }
    .hh-card {
        flex-direction: column;
        align-items: flex-start;
    }
    .hh-right {
        margin-left: 0;
    }
    .summary-row {
        grid-template-columns: 1fr 1fr;
    }
}

.pay-filters {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 16px;
    background: #fff;
    border: 1.5px solid #ebebeb;
    border-radius: 14px;
    padding: 12px 16px;
}
.pf-search-wrap {
    flex: 1;
    min-width: 160px;
}
.pf-search {
    width: 100%;
    padding: 8px 12px;
    border: 1.5px solid #ebebeb;
    border-radius: 10px;
    font-size: 13px;
    color: #111;
    background: #f9f9f9;
    outline: none;
    font-family: 'Segoe UI', sans-serif;
    box-sizing: border-box;
}
.pf-search:focus {
    border-color: #f97316;
}
.pf-select {
    padding: 8px 12px;
    border: 1.5px solid #ebebeb;
    border-radius: 10px;
    font-size: 13px;
    color: #111;
    background: #f9f9f9;
    outline: none;
    font-family: 'Segoe UI', sans-serif;
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
    color: #aaa;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}
.btn-clear-pay {
    padding: 8px 14px;
    background: #fef2f2;
    color: #dc2626;
    border: 1.5px solid #fecaca;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    font-family: 'Segoe UI', sans-serif;
}
.payment-card.pending_review {
    border-left: 4px solid #f97316;
}
.payment-card.paid {
    border-left: 4px solid #16a34a;
}
.payment-card.rejected {
    border-left: 4px solid #dc2626;
}
.payment-card.failed {
    border-left: 4px solid #dc2626;
}
.pay-badge.pending_review {
    background: rgba(249, 115, 22, 0.1);
    color: #f97316;
    border: 1px solid rgba(249, 115, 22, 0.2);
}
.pay-badge.rejected {
    background: rgba(220, 38, 38, 0.1);
    color: #dc2626;
    border: 1px solid rgba(220, 38, 38, 0.2);
}
.pay-note {
    font-style: italic;
    margin-top: 4px;
}
</style>

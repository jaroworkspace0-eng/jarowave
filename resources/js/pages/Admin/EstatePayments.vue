<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import {
    Building2,
    CheckCircle,
    Clock,
    Eye,
    X,
    XCircle,
} from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';

const auth = useAuthStore();

onMounted(() => {
    if (auth.user?.role !== 'admin') {
        router.visit('/dashboard');
    }
});

interface Payment {
    id: number;
    amount: number;
    household_count: number;
    amount_per_household: number;
    payment_method: string;
    status: string;
    merchant_reference: string | null;
    proof_of_payment: string | null;
    notes: string | null;
    paid_at: string | null;
    created_at: string;
    channel_subscription: {
        id: number;
        current_period_start: string | null;
        current_period_end: string | null;
        channel: {
            id: number;
            name: string;
            billing_contact: {
                user: { name: string; email: string };
            } | null;
        };
    };
}

const allPayments = ref<Payment[]>([]);
const isLoading = ref(true);
const flash = ref<{ msg: string; type: 'success' | 'error' } | null>(null);

const filterStatus = ref<'all' | 'pending_review' | 'paid' | 'rejected'>('all');
const search = ref('');
const dateFrom = ref('');
const dateTo = ref('');

const currentPage = ref(1);
const perPage = 15;

const selectedPayment = ref<Payment | null>(null);
const showDetail = ref(false);

const showRejectModal = ref(false);
const rejectingPayment = ref<Payment | null>(null);
const rejectReason = ref('');
const isRejecting = ref(false);

const showProofModal = ref(false);
const proofUrl = ref('');
const proofIsPdf = ref(false);

const processingId = ref<number | null>(null);

const getHeaders = () => ({
    headers: { Authorization: `Bearer ${localStorage.getItem('token')}` },
});

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

async function fetchPayments() {
    isLoading.value = true;
    try {
        const res = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/admin/estate-payments`,
            getHeaders(),
        );
        allPayments.value = res.data.payments.data ?? res.data.payments;
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to load payments.',
            'error',
        );
    } finally {
        isLoading.value = false;
    }
}
onMounted(fetchPayments);

const stats = computed(() => {
    const pending = allPayments.value.filter(
        (p) => p.status === 'pending_review',
    );
    const approved = allPayments.value.filter((p) => p.status === 'paid');
    const rejected = allPayments.value.filter((p) => p.status === 'rejected');
    const totalApprovedAmount = approved.reduce(
        (sum, p) => sum + Number(p.amount),
        0,
    );
    return {
        pending: pending.length,
        approved: approved.length,
        rejected: rejected.length,
        totalApprovedAmount,
    };
});

const filtered = computed(() => {
    let result = [...allPayments.value];

    if (filterStatus.value !== 'all') {
        result = result.filter((p) => p.status === filterStatus.value);
    }

    if (search.value.trim()) {
        const q = search.value.toLowerCase();
        result = result.filter(
            (p) =>
                p.channel_subscription?.channel?.name
                    ?.toLowerCase()
                    .includes(q) ||
                p.merchant_reference?.toLowerCase().includes(q) ||
                p.channel_subscription?.channel?.billing_contact?.user?.name
                    ?.toLowerCase()
                    .includes(q) ||
                p.channel_subscription?.channel?.billing_contact?.user?.email
                    ?.toLowerCase()
                    .includes(q),
        );
    }

    if (dateFrom.value) {
        const from = new Date(dateFrom.value);
        result = result.filter((p) => new Date(p.created_at) >= from);
    }
    if (dateTo.value) {
        const to = new Date(dateTo.value);
        to.setHours(23, 59, 59);
        result = result.filter((p) => new Date(p.created_at) <= to);
    }

    return result;
});

const totalPages = computed(() => Math.ceil(filtered.value.length / perPage));
const paginated = computed(() => {
    const start = (currentPage.value - 1) * perPage;
    return filtered.value.slice(start, start + perPage);
});

watch([filterStatus, search, dateFrom, dateTo], () => {
    currentPage.value = 1;
});

const filterOptions = [
    { value: 'all', label: 'All' },
    { value: 'pending_review', label: 'Pending' },
    { value: 'paid', label: 'Approved' },
    { value: 'rejected', label: 'Rejected' },
] as const;

function openDetail(payment: Payment) {
    selectedPayment.value = payment;
    showDetail.value = true;
}
function closeDetail() {
    showDetail.value = false;
    selectedPayment.value = null;
}

async function approve(payment: Payment) {
    processingId.value = payment.id;
    try {
        await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/admin/channel-payments/${payment.id}/approve`,
            {},
            getHeaders(),
        );
        showFlash('Payment approved. All opted-in households activated.');
        await fetchPayments();
        if (selectedPayment.value?.id === payment.id) {
            selectedPayment.value =
                allPayments.value.find((p) => p.id === payment.id) ?? null;
        }
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to approve payment.',
            'error',
        );
    } finally {
        processingId.value = null;
    }
}

function openRejectModal(payment: Payment) {
    rejectingPayment.value = payment;
    rejectReason.value = '';
    showRejectModal.value = true;
}

async function submitReject() {
    if (!rejectingPayment.value || !rejectReason.value) return;
    isRejecting.value = true;
    try {
        await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/admin/channel-payments/${rejectingPayment.value.id}/reject`,
            { reason: rejectReason.value },
            getHeaders(),
        );
        showFlash('Payment rejected. Billing contact has been notified.');
        showRejectModal.value = false;
        const rejectedId = rejectingPayment.value.id;
        await fetchPayments();
        if (selectedPayment.value?.id === rejectedId) {
            selectedPayment.value =
                allPayments.value.find((p) => p.id === rejectedId) ?? null;
        }
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to reject payment.',
            'error',
        );
    } finally {
        isRejecting.value = false;
    }
}

function viewProof(payment: Payment) {
    proofUrl.value = `${import.meta.env.VITE_APP_URL}/storage/${payment.proof_of_payment}`;
    proofIsPdf.value = payment.proof_of_payment?.endsWith('.pdf') ?? false;
    showProofModal.value = true;
}

function statusConfig(status: string) {
    switch (status) {
        case 'pending_review':
            return {
                label: 'Pending Review',
                cls: 'bg-orange-50 text-orange-700',
                icon: Clock,
            };
        case 'paid':
            return {
                label: 'Approved',
                cls: 'bg-emerald-50 text-emerald-700',
                icon: CheckCircle,
            };
        case 'rejected':
            return {
                label: 'Rejected',
                cls: 'bg-red-50 text-red-600',
                icon: XCircle,
            };
        default:
            return {
                label: status,
                cls: 'bg-slate-100 text-slate-500',
                icon: Clock,
            };
    }
}
</script>

<template>
    <Head title="Estate EFT Payments" />
    <AppLayout>
        <div class="page-root">
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Finance</div>
                    <h1 class="page-header__title">Estate EFT Payments</h1>
                    <p class="page-header__sub">
                        Review and approve estate bulk EFT submissions
                    </p>
                </div>
                <button class="btn-secondary" @click="fetchPayments">
                    ↻ Refresh
                </button>
            </div>

            <div class="stats-grid">
                <div
                    class="stat-card stat-card--clickable"
                    :class="{
                        'stat-card--active': filterStatus === 'pending_review',
                    }"
                    @click="filterStatus = 'pending_review'"
                >
                    <div class="stat-card__value" style="color: #ea580c">
                        {{ stats.pending }}
                    </div>
                    <div class="stat-card__label">Pending Review</div>
                </div>
                <div
                    class="stat-card stat-card--clickable stat-card--success"
                    :class="{ 'stat-card--active': filterStatus === 'paid' }"
                    @click="filterStatus = 'paid'"
                >
                    <div class="stat-card__value">{{ stats.approved }}</div>
                    <div class="stat-card__label">Approved</div>
                </div>
                <div
                    class="stat-card stat-card--clickable stat-card--danger"
                    :class="{
                        'stat-card--active': filterStatus === 'rejected',
                    }"
                    @click="filterStatus = 'rejected'"
                >
                    <div class="stat-card__value">{{ stats.rejected }}</div>
                    <div class="stat-card__label">Rejected</div>
                </div>
                <div class="stat-card stat-card--info">
                    <div class="stat-card__value">
                        {{ fmtAmount(stats.totalApprovedAmount) }}
                    </div>
                    <div class="stat-card__label">Total Approved</div>
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
                            placeholder="Search estate, reference, contact…"
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
                            <label class="date-field__label">From</label>
                            <input
                                v-model="dateFrom"
                                type="date"
                                class="field__input field__input--date"
                            />
                        </div>
                        <div class="date-field">
                            <label class="date-field__label">To</label>
                            <input
                                v-model="dateTo"
                                type="date"
                                class="field__input field__input--date"
                            />
                        </div>
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
                                    'chip--active': filterStatus === f.value,
                                }"
                                @click="filterStatus = f.value"
                            >
                                {{ f.label }}
                            </button>
                        </div>
                    </div>
                    <span class="filter-count ml-auto"
                        >{{ filtered.length }} result{{
                            filtered.length !== 1 ? 's' : ''
                        }}</span
                    >
                </div>
            </div>

            <div class="table-card">
                <div v-if="isLoading" class="empty-state">
                    <span class="text-sm text-slate-400"
                        >Loading payments…</span
                    >
                </div>
                <div v-else-if="paginated.length === 0" class="empty-state">
                    <p class="empty-state__title">No payments found</p>
                    <p class="empty-state__sub">
                        Try adjusting your filters or search query
                    </p>
                </div>
                <table v-else class="data-table">
                    <thead>
                        <tr>
                            <th>Estate</th>
                            <th>Contact</th>
                            <th>Reference</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="payment in paginated"
                            :key="payment.id"
                            class="clickable-row"
                            @click="openDetail(payment)"
                        >
                            <td>
                                <div class="reporter-cell">
                                    <div class="reporter-cell__avatar">
                                        {{
                                            (
                                                payment.channel_subscription
                                                    ?.channel?.name || 'E'
                                            )
                                                .charAt(0)
                                                .toUpperCase()
                                        }}
                                    </div>
                                    <div>
                                        <div class="reporter-cell__name">
                                            {{
                                                payment.channel_subscription
                                                    ?.channel?.name ?? '—'
                                            }}
                                        </div>
                                        <div class="reporter-cell__sub">
                                            {{ payment.household_count }}
                                            households
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div
                                    class="reporter-cell__name"
                                    style="font-weight: 600; font-size: 12.5px"
                                >
                                    {{
                                        payment.channel_subscription?.channel
                                            ?.billing_contact?.user?.name ?? '—'
                                    }}
                                </div>
                                <div class="reporter-cell__sub">
                                    {{
                                        payment.channel_subscription?.channel
                                            ?.billing_contact?.user?.email ?? ''
                                    }}
                                </div>
                            </td>
                            <td class="td-time mono">
                                {{ payment.merchant_reference ?? '—' }}
                            </td>
                            <td class="amount-cell">
                                {{ fmtAmount(payment.amount) }}
                            </td>
                            <td>
                                <span
                                    class="type-badge"
                                    :class="statusConfig(payment.status).cls"
                                >
                                    {{ statusConfig(payment.status).label }}
                                </span>
                            </td>
                            <td class="td-time">
                                {{ fmtDate(payment.created_at) }}
                            </td>
                            <td @click.stop>
                                <div class="row-actions">
                                    <button
                                        class="icon-btn"
                                        title="View Proof"
                                        :disabled="!payment.proof_of_payment"
                                        @click="viewProof(payment)"
                                    >
                                        <Eye :size="15" />
                                    </button>
                                    <button
                                        v-if="
                                            payment.status === 'pending_review'
                                        "
                                        class="icon-btn icon-btn--success"
                                        title="Approve"
                                        :disabled="processingId === payment.id"
                                        @click="approve(payment)"
                                    >
                                        <CheckCircle :size="15" />
                                    </button>
                                    <button
                                        v-if="
                                            payment.status === 'pending_review'
                                        "
                                        class="icon-btn icon-btn--danger"
                                        title="Reject"
                                        :disabled="processingId === payment.id"
                                        @click="openRejectModal(payment)"
                                    >
                                        <XCircle :size="15" />
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

        <!-- DETAIL MODAL -->
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
                                    <Building2
                                        :size="15"
                                        style="
                                            display: inline;
                                            vertical-align: -2px;
                                            margin-right: 4px;
                                        "
                                    />
                                    {{
                                        selectedPayment?.channel_subscription
                                            ?.channel?.name
                                    }}
                                </div>
                                <div class="modal-sheet__sub">
                                    Submitted
                                    {{
                                        fmtDateTime(selectedPayment?.created_at)
                                    }}
                                </div>
                            </div>
                            <button class="close-btn" @click="closeDetail">
                                <X :size="16" />
                            </button>
                        </div>

                        <div class="modal-sheet__body">
                            <div class="toggle-row">
                                <span
                                    class="type-badge"
                                    :class="
                                        statusConfig(
                                            selectedPayment?.status ?? '',
                                        ).cls
                                    "
                                >
                                    {{
                                        statusConfig(
                                            selectedPayment?.status ?? '',
                                        ).label
                                    }}
                                </span>
                                <span class="amount-pill">{{
                                    fmtAmount(selectedPayment?.amount)
                                }}</span>
                            </div>

                            <div class="toggle-row">
                                <div class="review-info-panel">
                                    <div class="field__label">
                                        Billing Contact
                                    </div>
                                    <div class="review-info-panel__name">
                                        {{
                                            selectedPayment
                                                ?.channel_subscription?.channel
                                                ?.billing_contact?.user?.name ??
                                            '—'
                                        }}
                                    </div>
                                    <div class="review-info-panel__sub">
                                        {{
                                            selectedPayment
                                                ?.channel_subscription?.channel
                                                ?.billing_contact?.user
                                                ?.email ?? ''
                                        }}
                                    </div>
                                </div>
                                <div class="review-info-panel">
                                    <div class="field__label">Reference</div>
                                    <div
                                        class="review-info-panel__name mono"
                                        style="font-size: 13px"
                                    >
                                        {{
                                            selectedPayment?.merchant_reference ??
                                            '—'
                                        }}
                                    </div>
                                    <div class="review-info-panel__sub">
                                        {{
                                            selectedPayment?.payment_method ??
                                            ''
                                        }}
                                    </div>
                                </div>
                            </div>

                            <div class="review-info-panel">
                                <div class="field__label">
                                    Billing Breakdown
                                </div>
                                <div
                                    class="detail-grid detail-grid--pad"
                                    style="
                                        margin-top: 8px;
                                        padding-top: 0;
                                        border-top: none;
                                    "
                                >
                                    <div>
                                        <div class="field__label">
                                            Households
                                        </div>
                                        <div class="detail-grid__value">
                                            {{
                                                selectedPayment?.household_count
                                            }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="field__label">
                                            Per Household
                                        </div>
                                        <div class="detail-grid__value">
                                            {{
                                                fmtAmount(
                                                    selectedPayment?.amount_per_household,
                                                )
                                            }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="field__label">Approved</div>
                                        <div class="detail-grid__value">
                                            {{
                                                selectedPayment?.paid_at
                                                    ? fmtDate(
                                                          selectedPayment.paid_at,
                                                      )
                                                    : '—'
                                            }}
                                        </div>
                                    </div>
                                </div>
                                <div class="detail-grid detail-grid--pad">
                                    <div>
                                        <div class="field__label">
                                            Period Start
                                        </div>
                                        <div class="detail-grid__value">
                                            {{
                                                fmtDate(
                                                    selectedPayment
                                                        ?.channel_subscription
                                                        ?.current_period_start ??
                                                        null,
                                                )
                                            }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="field__label">
                                            Period End
                                        </div>
                                        <div class="detail-grid__value">
                                            {{
                                                fmtDate(
                                                    selectedPayment
                                                        ?.channel_subscription
                                                        ?.current_period_end ??
                                                        null,
                                                )
                                            }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div v-if="selectedPayment?.notes" class="field">
                                <label class="field__label">Notes</label>
                                <p class="review-description">
                                    {{ selectedPayment.notes }}
                                </p>
                            </div>

                            <div class="modal-actions">
                                <button
                                    class="btn-secondary"
                                    :disabled="
                                        !selectedPayment?.proof_of_payment
                                    "
                                    @click="
                                        selectedPayment &&
                                        viewProof(selectedPayment)
                                    "
                                >
                                    <Eye :size="14" /> View Proof
                                </button>
                                <template
                                    v-if="
                                        selectedPayment?.status ===
                                        'pending_review'
                                    "
                                >
                                    <button
                                        class="btn-primary btn-primary--success"
                                        :disabled="
                                            processingId === selectedPayment.id
                                        "
                                        @click="approve(selectedPayment)"
                                    >
                                        ✓ Approve
                                    </button>
                                    <button
                                        class="btn-secondary btn-secondary--danger"
                                        @click="
                                            openRejectModal(selectedPayment)
                                        "
                                    >
                                        Reject
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>

        <!-- PROOF MODAL -->
        <Teleport to="body">
            <transition name="modal">
                <div
                    v-if="showProofModal"
                    class="modal-backdrop"
                    @click.self="showProofModal = false"
                >
                    <div class="modal-sheet modal-sheet--lg">
                        <div class="modal-sheet__header">
                            <div class="modal-sheet__title">
                                Proof of Payment
                            </div>
                            <button
                                class="close-btn"
                                @click="showProofModal = false"
                            >
                                <X :size="16" />
                            </button>
                        </div>
                        <div class="modal-sheet__body">
                            <iframe
                                v-if="proofIsPdf"
                                :src="proofUrl"
                                class="proof-iframe"
                            ></iframe>
                            <img v-else :src="proofUrl" class="proof-img" />
                            <div class="modal-actions">
                                <a
                                    :href="proofUrl"
                                    target="_blank"
                                    class="btn-primary btn-primary--link"
                                    >Open in New Tab</a
                                >
                                <button
                                    class="btn-secondary"
                                    @click="showProofModal = false"
                                >
                                    Close
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>

        <!-- REJECT MODAL -->
        <Teleport to="body">
            <transition name="modal">
                <div
                    v-if="showRejectModal"
                    class="modal-backdrop"
                    @click.self="showRejectModal = false"
                >
                    <div class="modal-sheet modal-sheet--sm">
                        <div class="confirm-body">
                            <div class="confirm-icon confirm-icon--danger">
                                <XCircle :size="20" />
                            </div>
                            <div>
                                <h3 class="confirm-title">Reject Payment</h3>
                                <p class="confirm-sub">
                                    Emailed to
                                    {{
                                        rejectingPayment?.channel_subscription
                                            ?.channel?.billing_contact?.user
                                            ?.name
                                    }}
                                </p>
                            </div>
                        </div>
                        <div class="field" style="margin-bottom: 18px">
                            <label class="field__label"
                                >Reason for Rejection</label
                            >
                            <input
                                class="text-field"
                                type="text"
                                v-model="rejectReason"
                                placeholder="e.g. Amount does not match, unclear image…"
                                autofocus
                            />
                        </div>
                        <div class="confirm-actions">
                            <button
                                class="btn-secondary"
                                @click="showRejectModal = false"
                            >
                                Cancel
                            </button>
                            <button
                                class="btn-primary btn-primary--danger"
                                :disabled="isRejecting || !rejectReason"
                                @click="submitReject"
                            >
                                {{
                                    isRejecting
                                        ? 'Rejecting…'
                                        : 'Reject Payment'
                                }}
                            </button>
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
.btn-primary--link {
    background: #ea580c;
    text-decoration: none;
}
.btn-primary--link:hover {
    background: #c2410c;
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
.ml-auto {
    margin-left: auto;
}
.filter-count {
    font-size: 12px;
    color: #94a3b8;
    font-weight: 600;
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
.icon-btn--danger {
    color: #dc2626;
}
.icon-btn--danger:hover:not(:disabled) {
    background: #fef2f2;
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
.modal-sheet--sm {
    max-width: 440px;
    padding: 24px;
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
    font-size: 18px;
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
    font-style: italic;
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

.detail-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}
.detail-grid--pad {
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px dashed #e4e8ef;
}
.detail-grid__value {
    margin-top: 2px;
    font-size: 13px;
    font-weight: 700;
    color: #1a2332;
}

.modal-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    padding-top: 4px;
}

.confirm-body {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 16px;
}
.confirm-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.confirm-icon--danger {
    background: #fef2f2;
    color: #dc2626;
}
.confirm-title {
    font-size: 15px;
    font-weight: 700;
    color: #1a2332;
    margin: 0;
}
.confirm-sub {
    font-size: 12px;
    color: #94a3b8;
    margin: 2px 0 0;
}
.confirm-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.proof-iframe {
    width: 100%;
    height: 500px;
    border: none;
    border-radius: 10px;
}
.proof-img {
    width: 100%;
    border-radius: 10px;
    object-fit: contain;
    max-height: 500px;
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

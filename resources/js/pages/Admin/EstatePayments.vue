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
    Eye,
    Filter,
    RefreshCw,
    Search,
    XCircle,
} from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';

const auth = useAuthStore();

onMounted(() => {
    if (auth.user?.role !== 'admin') {
        router.visit('/dashboard'); // redirect non-admins away
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

const payments = ref<Payment[]>([]);
const allPayments = ref<Payment[]>([]);
const isLoading = ref(true);
const flash = ref<{ msg: string; type: 'success' | 'error' } | null>(null);

// Filters
const filterStatus = ref('all');
const filterSearch = ref('');
const filterDateFrom = ref('');
const filterDateTo = ref('');

// Pagination
const currentPage = ref(1);
const perPage = 15;

// Reject modal
const showRejectModal = ref(false);
const rejectingPayment = ref<Payment | null>(null);
const rejectReason = ref('');
const isRejecting = ref(false);

// Proof modal
const showProofModal = ref(false);
const proofUrl = ref('');
const proofIsPdf = ref(false);

// Processing
const processingId = ref<number | null>(null);

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

const fetchPayments = async () => {
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
};

// ── Stats ─────────────────────────────────────────────────────────────────
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

// ── Filtering ─────────────────────────────────────────────────────────────
const filtered = computed(() => {
    let result = [...allPayments.value];

    if (filterStatus.value !== 'all') {
        result = result.filter((p) => p.status === filterStatus.value);
    }

    if (filterSearch.value.trim()) {
        const q = filterSearch.value.toLowerCase();
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

    if (filterDateFrom.value) {
        const from = new Date(filterDateFrom.value);
        result = result.filter((p) => new Date(p.created_at) >= from);
    }

    if (filterDateTo.value) {
        const to = new Date(filterDateTo.value);
        to.setHours(23, 59, 59);
        result = result.filter((p) => new Date(p.created_at) <= to);
    }

    return result;
});

// ── Pagination ────────────────────────────────────────────────────────────
const totalPages = computed(() => Math.ceil(filtered.value.length / perPage));

const paginated = computed(() => {
    const start = (currentPage.value - 1) * perPage;
    return filtered.value.slice(start, start + perPage);
});

watch([filterStatus, filterSearch, filterDateFrom, filterDateTo], () => {
    currentPage.value = 1;
});

const clearFilters = () => {
    filterStatus.value = 'all';
    filterSearch.value = '';
    filterDateFrom.value = '';
    filterDateTo.value = '';
};

const hasActiveFilters = computed(
    () =>
        filterStatus.value !== 'all' ||
        filterSearch.value ||
        filterDateFrom.value ||
        filterDateTo.value,
);

// ── Actions ───────────────────────────────────────────────────────────────
const approve = async (payment: Payment) => {
    processingId.value = payment.id;
    try {
        await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/admin/channel-payments/${payment.id}/approve`,
            {},
            getHeaders(),
        );
        showFlash('Payment approved. All opted-in households activated.');
        await fetchPayments();
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to approve payment.',
            'error',
        );
    } finally {
        processingId.value = null;
    }
};

const openRejectModal = (payment: Payment) => {
    rejectingPayment.value = payment;
    rejectReason.value = '';
    showRejectModal.value = true;
};

const submitReject = async () => {
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
        await fetchPayments();
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to reject payment.',
            'error',
        );
    } finally {
        isRejecting.value = false;
    }
};

const viewProof = (payment: Payment) => {
    proofUrl.value = `${import.meta.env.VITE_APP_URL}/storage/${payment.proof_of_payment}`;
    proofIsPdf.value = payment.proof_of_payment?.endsWith('.pdf') ?? false;
    showProofModal.value = true;
};

const statusConfig = (status: string) => {
    switch (status) {
        case 'pending_review':
            return {
                label: 'Pending Review',
                cls: 'badge-pending',
                icon: Clock,
            };
        case 'paid':
            return { label: 'Approved', cls: 'badge-paid', icon: CheckCircle };
        case 'rejected':
            return { label: 'Rejected', cls: 'badge-rejected', icon: XCircle };
        default:
            return { label: status, cls: '', icon: Clock };
    }
};

onMounted(fetchPayments);
</script>

<template>
    <Head title="Estate EFT Payments" />
    <AppLayout>
        <div class="ep-root">
            <!-- HEADER -->
            <div class="ep-header">
                <div class="ep-header-left">
                    <div class="ep-icon">
                        <Building2 :size="20" stroke-width="2" />
                    </div>
                    <div>
                        <h1 class="ep-title">Estate EFT Payments</h1>
                        <p class="ep-sub">
                            Review and approve estate bulk EFT submissions
                        </p>
                    </div>
                </div>
                <button class="btn-icon" @click="fetchPayments" title="Refresh">
                    <RefreshCw :size="16" stroke-width="2" />
                </button>
            </div>

            <!-- FLASH -->
            <div v-if="flash" :class="['flash', flash.type]">
                {{ flash.type === 'success' ? '✓' : '⚠' }} {{ flash.msg }}
            </div>

            <!-- STATS -->
            <div class="stats-row">
                <div
                    class="stat-card"
                    @click="filterStatus = 'pending_review'"
                    :class="{ active: filterStatus === 'pending_review' }"
                >
                    <div class="stat-icon orange">
                        <Clock :size="18" stroke-width="2" />
                    </div>
                    <div>
                        <div class="stat-val">{{ stats.pending }}</div>
                        <div class="stat-lbl">Pending Review</div>
                    </div>
                </div>
                <div
                    class="stat-card"
                    @click="filterStatus = 'paid'"
                    :class="{ active: filterStatus === 'paid' }"
                >
                    <div class="stat-icon green">
                        <CheckCircle :size="18" stroke-width="2" />
                    </div>
                    <div>
                        <div class="stat-val">{{ stats.approved }}</div>
                        <div class="stat-lbl">Approved</div>
                    </div>
                </div>
                <div
                    class="stat-card"
                    @click="filterStatus = 'rejected'"
                    :class="{ active: filterStatus === 'rejected' }"
                >
                    <div class="stat-icon red">
                        <XCircle :size="18" stroke-width="2" />
                    </div>
                    <div>
                        <div class="stat-val">{{ stats.rejected }}</div>
                        <div class="stat-lbl">Rejected</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon blue">
                        <AlertTriangle :size="18" stroke-width="2" />
                    </div>
                    <div>
                        <div class="stat-val">
                            {{ fmt(stats.totalApprovedAmount) }}
                        </div>
                        <div class="stat-lbl">Total Approved</div>
                    </div>
                </div>
            </div>

            <!-- FILTERS -->
            <div class="filters-bar">
                <!-- Search -->
                <div class="search-wrap">
                    <Search :size="14" stroke-width="2" color="#aaa" />
                    <input
                        class="search-input"
                        type="text"
                        v-model="filterSearch"
                        placeholder="Search estate, reference, contact..."
                    />
                </div>

                <!-- Status filter -->
                <select class="filter-select" v-model="filterStatus">
                    <option value="all">All Statuses</option>
                    <option value="pending_review">Pending Review</option>
                    <option value="paid">Approved</option>
                    <option value="rejected">Rejected</option>
                </select>

                <!-- Date from -->
                <div class="date-wrap">
                    <label class="date-label">From</label>
                    <input
                        class="filter-select"
                        type="date"
                        v-model="filterDateFrom"
                    />
                </div>

                <!-- Date to -->
                <div class="date-wrap">
                    <label class="date-label">To</label>
                    <input
                        class="filter-select"
                        type="date"
                        v-model="filterDateTo"
                    />
                </div>

                <!-- Clear -->
                <button
                    v-if="hasActiveFilters"
                    class="btn-clear"
                    @click="clearFilters"
                >
                    <XCircle :size="13" stroke-width="2" />
                    Clear
                </button>

                <div class="filter-count">
                    {{ filtered.length }} result{{
                        filtered.length !== 1 ? 's' : ''
                    }}
                </div>
            </div>

            <!-- LOADING -->
            <div v-if="isLoading" class="loading">
                <div class="spinner"></div>
                <p>Loading payments…</p>
            </div>

            <!-- EMPTY -->
            <div v-else-if="!filtered.length" class="empty-card">
                <Filter :size="32" stroke-width="1.5" color="#bbb" />
                <div class="empty-title">No payments found</div>
                <div class="empty-desc">
                    Try adjusting your filters or search query.
                </div>
                <button
                    v-if="hasActiveFilters"
                    class="btn-ghost"
                    style="margin-top: 8px"
                    @click="clearFilters"
                >
                    Clear Filters
                </button>
            </div>

            <!-- PAYMENT LIST -->
            <div v-else class="payment-list">
                <div
                    v-for="payment in paginated"
                    :key="payment.id"
                    :class="['payment-card', payment.status]"
                >
                    <!-- Left -->
                    <div class="pc-left">
                        <div class="pc-estate-icon">
                            {{
                                payment.channel_subscription?.channel?.name
                                    ?.charAt(0)
                                    ?.toUpperCase()
                            }}
                        </div>
                        <div class="pc-info">
                            <div class="pc-name">
                                {{
                                    payment.channel_subscription?.channel?.name
                                }}
                            </div>
                            <div class="pc-meta">
                                <strong>{{
                                    payment.channel_subscription?.channel
                                        ?.billing_contact?.user?.name
                                }}</strong>
                                ·
                                {{
                                    payment.channel_subscription?.channel
                                        ?.billing_contact?.user?.email
                                }}
                            </div>
                            <div class="pc-meta">
                                Ref:
                                <span class="mono">{{
                                    payment.merchant_reference ?? '—'
                                }}</span>
                                · {{ payment.household_count }} households ·
                                {{
                                    fmt(payment.amount_per_household)
                                }}/household
                            </div>
                            <div class="pc-meta">
                                Submitted: {{ formatDate(payment.created_at) }}
                                <span v-if="payment.paid_at">
                                    · Approved:
                                    {{ formatDate(payment.paid_at) }}</span
                                >
                            </div>
                            <div class="pc-meta pc-period">
                                Period:
                                {{
                                    formatDate(
                                        payment.channel_subscription
                                            ?.current_period_start,
                                    )
                                }}
                                →
                                {{
                                    formatDate(
                                        payment.channel_subscription
                                            ?.current_period_end,
                                    )
                                }}
                            </div>
                            <div v-if="payment.notes" class="pc-note">
                                "{{ payment.notes }}"
                            </div>
                        </div>
                    </div>

                    <!-- Right -->
                    <div class="pc-right">
                        <div class="pc-amount">{{ fmt(payment.amount) }}</div>

                        <span
                            :class="['badge', statusConfig(payment.status).cls]"
                        >
                            <component
                                :is="statusConfig(payment.status).icon"
                                :size="11"
                                stroke-width="2.5"
                            />
                            {{ statusConfig(payment.status).label }}
                        </span>

                        <div class="pc-actions">
                            <button
                                class="btn-proof"
                                @click="viewProof(payment)"
                                :disabled="!payment.proof_of_payment"
                            >
                                <Eye :size="13" stroke-width="2" />
                                View Proof
                            </button>
                            <button
                                v-if="payment.status === 'pending_review'"
                                class="btn-approve"
                                :disabled="processingId === payment.id"
                                @click="approve(payment)"
                            >
                                <span
                                    v-if="processingId === payment.id"
                                    class="btn-spinner"
                                ></span>
                                <CheckCircle
                                    v-else
                                    :size="13"
                                    stroke-width="2.5"
                                />
                                Approve
                            </button>
                            <button
                                v-if="payment.status === 'pending_review'"
                                class="btn-reject"
                                :disabled="processingId === payment.id"
                                @click="openRejectModal(payment)"
                            >
                                <XCircle :size="13" stroke-width="2.5" />
                                Reject
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PAGINATION -->
            <div v-if="totalPages > 1" class="pagination">
                <button
                    class="pg-btn"
                    :disabled="currentPage === 1"
                    @click="currentPage--"
                >
                    ← Prev
                </button>
                <span class="pg-info"
                    >Page {{ currentPage }} of {{ totalPages }}</span
                >
                <button
                    class="pg-btn"
                    :disabled="currentPage === totalPages"
                    @click="currentPage++"
                >
                    Next →
                </button>
            </div>

            <!-- PROOF MODAL -->
            <div
                v-if="showProofModal"
                class="modal-overlay"
                @click.self="showProofModal = false"
            >
                <div class="modal modal-lg">
                    <div class="modal-title">Proof of Payment</div>
                    <div class="proof-wrap">
                        <iframe
                            v-if="proofIsPdf"
                            :src="proofUrl"
                            class="proof-iframe"
                        ></iframe>
                        <img v-else :src="proofUrl" class="proof-img" />
                    </div>
                    <div class="modal-actions">
                        <a :href="proofUrl" target="_blank" class="btn-process"
                            >Open in New Tab</a
                        >
                        <button
                            class="btn-ghost"
                            @click="showProofModal = false"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </div>

            <!-- REJECT MODAL -->
            <div
                v-if="showRejectModal"
                class="modal-overlay"
                @click.self="showRejectModal = false"
            >
                <div class="modal modal-sm">
                    <div class="modal-title">Reject Payment</div>
                    <p class="modal-note">
                        Provide a reason for rejection. This will be emailed to
                        <strong>{{
                            rejectingPayment?.channel_subscription?.channel
                                ?.billing_contact?.user?.name
                        }}</strong
                        >.
                    </p>
                    <div class="mf">
                        <label class="ml">Reason for Rejection</label>
                        <input
                            class="mi"
                            type="text"
                            v-model="rejectReason"
                            placeholder="e.g. Amount does not match, unclear image..."
                            autofocus
                        />
                    </div>
                    <div class="modal-actions">
                        <button
                            class="btn-ghost"
                            @click="showRejectModal = false"
                        >
                            Cancel
                        </button>
                        <button
                            class="btn-danger"
                            :disabled="isRejecting || !rejectReason"
                            @click="submitReject"
                        >
                            <span v-if="isRejecting" class="btn-spinner"></span>
                            <span v-else>Reject Payment</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.ep-root {
    max-width: 1400;
    margin: 0 auto;
    padding: 36px 24px 64px;
    font-family: 'Segoe UI', sans-serif;
    color: #111;
    width: 100%;
}

.ep-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
}
.ep-header-left {
    display: flex;
    align-items: center;
    gap: 14px;
}
.ep-icon {
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
.ep-title {
    font-size: 22px;
    font-weight: 800;
    letter-spacing: -0.5px;
    margin: 0 0 2px;
}
.ep-sub {
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

/* Stats */
.stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}
.stat-card {
    background: #fff;
    border: 1.5px solid #ebebeb;
    border-radius: 14px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    cursor: pointer;
    transition: all 0.15s;
}
.stat-card:hover {
    border-color: #f97316;
}
.stat-card.active {
    border-color: #f97316;
    background: #fff7ed;
}
.stat-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.stat-icon.orange {
    background: rgba(249, 115, 22, 0.1);
    color: #f97316;
}
.stat-icon.green {
    background: rgba(22, 163, 74, 0.1);
    color: #16a34a;
}
.stat-icon.red {
    background: rgba(220, 38, 38, 0.1);
    color: #dc2626;
}
.stat-icon.blue {
    background: rgba(37, 99, 235, 0.1);
    color: #2563eb;
}
.stat-val {
    font-size: 20px;
    font-weight: 800;
    color: #111;
    letter-spacing: -0.5px;
}
.stat-lbl {
    font-size: 11px;
    color: #aaa;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 2px;
}

/* Filters */
.filters-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    background: #fff;
    border: 1.5px solid #ebebeb;
    border-radius: 14px;
    padding: 14px 16px;
    margin-bottom: 20px;
}
.search-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #f9f9f9;
    border: 1.5px solid #ebebeb;
    border-radius: 10px;
    padding: 8px 12px;
    flex: 1;
    min-width: 200px;
}
.search-input {
    border: none;
    background: none;
    outline: none;
    font-size: 13px;
    color: #111;
    width: 100%;
    font-family: 'Segoe UI', sans-serif;
}
.filter-select {
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
.date-wrap {
    display: flex;
    align-items: center;
    gap: 6px;
}
.date-label {
    font-size: 11px;
    font-weight: 700;
    color: #aaa;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}
.btn-clear {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 8px 14px;
    background: #fef2f2;
    color: #dc2626;
    border: 1.5px solid #fecaca;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    font-family: 'Segoe UI', sans-serif;
    transition: all 0.15s;
}
.btn-clear:hover {
    background: rgba(220, 38, 38, 0.12);
}
.filter-count {
    font-size: 12px;
    color: #aaa;
    font-weight: 600;
    margin-left: auto;
    white-space: nowrap;
}

.loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    padding: 80px 0;
    color: #999;
}

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

/* Payment cards */
.payment-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.payment-card {
    background: #fff;
    border: 1.5px solid #ebebeb;
    border-radius: 16px;
    padding: 20px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    transition: border-color 0.15s;
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

.pc-left {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    flex: 1;
    min-width: 0;
}
.pc-estate-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: linear-gradient(135deg, #f97316, #ea580c);
    color: #fff;
    font-size: 18px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.pc-info {
    flex: 1;
    min-width: 0;
}
.pc-name {
    font-size: 15px;
    font-weight: 800;
    color: #111;
    margin-bottom: 4px;
}
.pc-meta {
    font-size: 12px;
    color: #888;
    margin-top: 3px;
}
.pc-period {
    color: #aaa;
}
.pc-note {
    font-size: 12px;
    color: #666;
    font-style: italic;
    background: #f9f9f9;
    border-radius: 8px;
    padding: 6px 10px;
    margin-top: 6px;
}
.mono {
    font-family: 'Courier New', monospace;
    font-size: 11px;
}

.pc-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
    flex-shrink: 0;
}
.pc-amount {
    font-size: 22px;
    font-weight: 800;
    color: #f97316;
    letter-spacing: -0.5px;
}

.badge {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 11px;
    font-weight: 700;
}
.badge-pending {
    background: rgba(249, 115, 22, 0.1);
    color: #f97316;
    border: 1px solid rgba(249, 115, 22, 0.2);
}
.badge-paid {
    background: rgba(22, 163, 74, 0.1);
    color: #16a34a;
    border: 1px solid rgba(22, 163, 74, 0.2);
}
.badge-rejected {
    background: rgba(220, 38, 38, 0.1);
    color: #dc2626;
    border: 1px solid rgba(220, 38, 38, 0.2);
}

.pc-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.btn-proof {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 7px 14px;
    background: #fff;
    color: #555;
    border: 1.5px solid #e5e5e5;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    font-family: 'Segoe UI', sans-serif;
    transition: all 0.15s;
}
.btn-proof:hover:not(:disabled) {
    border-color: #ccc;
    color: #111;
}
.btn-proof:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.btn-approve {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 7px 14px;
    background: rgba(22, 163, 74, 0.08);
    color: #16a34a;
    border: 1.5px solid rgba(22, 163, 74, 0.2);
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    font-family: 'Segoe UI', sans-serif;
    transition: all 0.15s;
}
.btn-approve:hover:not(:disabled) {
    background: rgba(22, 163, 74, 0.15);
}
.btn-approve:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.btn-reject {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 7px 14px;
    background: rgba(220, 38, 38, 0.06);
    color: #dc2626;
    border: 1.5px solid rgba(220, 38, 38, 0.2);
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    font-family: 'Segoe UI', sans-serif;
    transition: all 0.15s;
}
.btn-reject:hover:not(:disabled) {
    background: rgba(220, 38, 38, 0.12);
}
.btn-reject:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

/* Pagination */
.pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    margin-top: 24px;
}
.pg-btn {
    padding: 8px 18px;
    background: #fff;
    border: 1.5px solid #ebebeb;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    color: #555;
    cursor: pointer;
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
.pg-info {
    font-size: 13px;
    color: #888;
    font-weight: 600;
}

/* Proof modal */
.proof-wrap {
    margin: 16px 0;
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
.modal-lg {
    max-width: 780px;
}
.modal-sm {
    max-width: 440px;
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
    text-decoration: none;
}
.btn-process:hover {
    background: #ea580c;
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
    .stats-row {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 640px) {
    .payment-card {
        flex-direction: column;
    }
    .pc-right {
        align-items: flex-start;
    }
    .pc-actions {
        justify-content: flex-start;
    }
    .filters-bar {
        flex-direction: column;
        align-items: stretch;
    }
    .search-wrap {
        min-width: unset;
    }
}
</style>

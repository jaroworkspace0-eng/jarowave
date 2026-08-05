<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import {
    CheckCircle,
    Clock,
    Download,
    FileText,
    RefreshCw,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

const auth = useAuthStore();

const breadcrumbs: BreadcrumbItem[] = [];

onMounted(() => {
    if (auth.user?.role !== 'estate_billing') {
        router.visit('/dashboard');
    }
});

interface Invoice {
    id: number;
    invoice_number: string;
    status: string;
    total: number;
    subtotal: number;
    currency: string;
    invoice_type: string;
    issued_at: string | null;
    due_date: string | null;
    created_at: string;
    channel_subscription: {
        id: number;
        current_period_start: string | null;
        current_period_end: string | null;
        household_count: number;
        amount_per_household: number;
        channel: { name: string };
    } | null;
    channel_subscription_payment: {
        id: number;
        merchant_reference: string | null;
        payment_method: string;
        household_count: number;
        paid_at: string | null;
    } | null;
}

const invoices = ref<Invoice[]>([]);
const isLoading = ref(true);
const flash = ref<{ msg: string; type: 'success' | 'error' } | null>(null);

const getHeaders = () => ({
    headers: { Authorization: `Bearer ${localStorage.getItem('token')}` },
});

const showFlash = (msg: string, type: 'success' | 'error' = 'success') => {
    flash.value = { msg, type };
    setTimeout(() => (flash.value = null), 6000);
};

const fmt = (val: number | null | undefined) => {
    if (val == null) return '—';
    // total is stored in cents
    return `R${Number(val).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const formatDate = (d: string | null) =>
    d
        ? new Date(d).toLocaleDateString('en-ZA', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '—';

const statusConfig: Record<string, { label: string; cls: string; icon: any }> =
    {
        paid: {
            label: 'Paid',
            cls: 'bg-emerald-50 text-emerald-700',
            icon: CheckCircle,
        },
        void: {
            label: 'Void',
            cls: 'bg-slate-100 text-slate-500',
            icon: Clock,
        },
    };

const fetchInvoices = async () => {
    isLoading.value = true;
    try {
        const res = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/estate/invoices`,
            getHeaders(),
        );
        invoices.value = res.data.invoices.data ?? res.data.invoices;
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to load invoices.',
            'error',
        );
    } finally {
        isLoading.value = false;
    }
};

const downloadInvoice = async (invoice: Invoice) => {
    try {
        const res = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/estate/invoices/${invoice.id}/download-link`,
            getHeaders(),
        );
        window.open(res.data.url, '_blank');
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to generate download link.',
            'error',
        );
    }
};

onMounted(fetchInvoices);

// ── Computed ──────────────────────────────────────────────────────────────
const totals = computed(() => {
    const paid = invoices.value.filter((i) => i.status === 'paid');
    const outstanding = invoices.value.filter((i) => i.status !== 'paid');
    return {
        count: invoices.value.length,
        paidCount: paid.length,
        outstandingCount: outstanding.length,
        paidAmount: paid.reduce((sum, i) => sum + Number(i.total ?? 0), 0),
        outstandingAmount: outstanding.reduce(
            (sum, i) => sum + Number(i.total ?? 0),
            0,
        ),
    };
});
</script>

<template>
    <Head title="Estate Invoices" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="page-root">
            <!-- PAGE HEADER -->
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Billing</div>
                    <h1 class="page-header__title">Invoices</h1>
                    <p class="page-header__sub">Your estate billing invoices</p>
                </div>
                <div class="page-header__right">
                    <button class="btn-secondary" @click="fetchInvoices">
                        <RefreshCw :size="14" stroke-width="2" />
                        Refresh
                    </button>
                </div>
            </div>

            <!-- STAT CARDS -->
            <div class="stat-row">
                <div class="stat-card">
                    <div class="stat-card__label">Total Invoices</div>
                    <div class="stat-card__value">{{ totals.count }}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Paid</div>
                    <div class="stat-card__value stat-card__value--green">
                        {{ totals.paidCount }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Outstanding</div>
                    <div class="stat-card__value stat-card__value--orange">
                        {{ totals.outstandingCount }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Paid Amount</div>
                    <div class="stat-card__value stat-card__value--green">
                        {{ fmt(totals.paidAmount) }}
                    </div>
                </div>
            </div>

            <!-- TABLE CARD -->
            <div class="table-card">
                <div v-if="isLoading" class="empty-state">
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
                        >Loading invoices…</span
                    >
                </div>

                <div v-else-if="!invoices.length" class="empty-state">
                    <div class="empty-state__icon">
                        <FileText :size="26" stroke-width="1.4" />
                    </div>
                    <p class="empty-state__title">No invoices yet</p>
                    <p class="empty-state__sub">
                        Invoices will appear here once your EFT payment has been
                        approved
                    </p>
                </div>

                <table v-else class="data-table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Channel</th>
                            <th>Period</th>
                            <th>Reference</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="invoice in invoices" :key="invoice.id">
                            <td>
                                <div class="ticket-cell">
                                    <span class="ticket-cell__number">{{
                                        invoice.invoice_number
                                    }}</span>
                                    <span class="ticket-cell__subject"
                                        >Issued
                                        {{
                                            formatDate(
                                                invoice.issued_at ??
                                                    invoice.created_at,
                                            )
                                        }}
                                        <template
                                            v-if="
                                                invoice
                                                    .channel_subscription_payment
                                                    ?.paid_at
                                            "
                                        >
                                            · Paid
                                            {{
                                                formatDate(
                                                    invoice
                                                        .channel_subscription_payment
                                                        .paid_at,
                                                )
                                            }}
                                        </template>
                                    </span>
                                </div>
                            </td>
                            <td class="td-time">
                                <div>
                                    {{
                                        invoice.channel_subscription?.channel
                                            ?.name ?? '—'
                                    }}
                                </div>
                                <div class="td-subtext">
                                    {{
                                        invoice.channel_subscription
                                            ?.household_count ?? '—'
                                    }}
                                    households ·
                                    {{
                                        invoice.channel_subscription_payment?.payment_method?.toUpperCase() ??
                                        '—'
                                    }}
                                </div>
                            </td>
                            <td class="td-time">
                                <template
                                    v-if="
                                        invoice.channel_subscription
                                            ?.current_period_start
                                    "
                                >
                                    {{
                                        formatDate(
                                            invoice.channel_subscription
                                                .current_period_start,
                                        )
                                    }}
                                    →
                                    {{
                                        formatDate(
                                            invoice.channel_subscription
                                                .current_period_end,
                                        )
                                    }}
                                </template>
                                <template v-else>—</template>
                            </td>
                            <td class="td-time">
                                {{
                                    invoice.channel_subscription_payment
                                        ?.merchant_reference ?? '—'
                                }}
                            </td>
                            <td>
                                <span class="amount-cell">{{
                                    fmt(invoice.total)
                                }}</span>
                            </td>
                            <td>
                                <span
                                    class="type-badge type-badge--icon"
                                    :class="
                                        statusConfig[invoice.status]?.cls ??
                                        'bg-slate-100 text-slate-500'
                                    "
                                >
                                    <component
                                        :is="
                                            statusConfig[invoice.status]
                                                ?.icon ?? Clock
                                        "
                                        :size="12"
                                        stroke-width="2.5"
                                    />
                                    {{
                                        statusConfig[invoice.status]?.label ??
                                        invoice.status
                                    }}
                                </span>
                            </td>
                            <td>
                                <button
                                    class="row-action-btn"
                                    @click="downloadInvoice(invoice)"
                                >
                                    <Download :size="12" stroke-width="2" />
                                    PDF
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ═══════════════ FLASH TOAST ═══════════════ -->
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
.toast {
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
.btn-secondary:hover:not(:disabled) {
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
.stat-card__value--orange {
    color: #ea580c;
}
.stat-card__value--green {
    color: #059669;
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
    max-width: 340px;
    text-align: center;
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
.data-table td {
    padding: 13px 16px;
    vertical-align: middle;
}

.ticket-cell {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.ticket-cell__number {
    font-family: ui-monospace, SFMono-Regular, monospace;
    font-size: 11px;
    font-weight: 700;
    color: #94a3b8;
}
.ticket-cell__subject {
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
}

.td-time {
    color: #64748b;
    white-space: nowrap;
    font-size: 12px;
}
.td-subtext {
    color: #94a3b8;
    font-size: 11px;
    margin-top: 2px;
}

.amount-cell {
    font-size: 14px;
    font-weight: 800;
    color: #ea580c;
    white-space: nowrap;
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
.type-badge--icon {
    padding: 4px 10px 4px 8px;
}

.row-action-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
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

/* TOAST */
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

/* TRANSITIONS */
.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.22s ease;
}
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
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
        min-width: 760px;
    }
    .table-card {
        overflow-x: auto;
    }
}
</style>

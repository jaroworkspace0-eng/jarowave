<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import {
    CheckCircle,
    Clock,
    Download,
    FileText,
    RefreshCw,
} from 'lucide-vue-next';
import { onMounted, ref } from 'vue';

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
    return `R${(Number(val) / 100).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const formatDate = (d: string | null) =>
    d
        ? new Date(d).toLocaleDateString('en-ZA', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '—';

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

const downloadInvoice = (invoice: Invoice) => {
    window.open(
        `${import.meta.env.VITE_APP_URL}/api/estate/invoices/${invoice.id}/download`,
        '_blank',
    );
};

onMounted(fetchInvoices);
</script>

<template>
    <Head title="Estate Invoices" />
    <AppLayout>
        <div class="ei-root">
            <!-- HEADER -->
            <div class="ei-header">
                <div class="ei-header-left">
                    <div class="ei-icon">
                        <FileText :size="20" stroke-width="2" />
                    </div>
                    <div>
                        <h1 class="ei-title">Invoices</h1>
                        <p class="ei-sub">Your estate billing invoices</p>
                    </div>
                </div>
                <button class="btn-icon" @click="fetchInvoices" title="Refresh">
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
                <p>Loading invoices…</p>
            </div>

            <!-- EMPTY -->
            <div v-else-if="!invoices.length" class="empty-card">
                <FileText :size="32" stroke-width="1.5" color="#bbb" />
                <div class="empty-title">No invoices yet</div>
                <div class="empty-desc">
                    Invoices will appear here once your EFT payment has been
                    approved.
                </div>
            </div>

            <!-- INVOICE LIST -->
            <div v-else class="invoice-list">
                <div
                    v-for="invoice in invoices"
                    :key="invoice.id"
                    class="invoice-card"
                >
                    <!-- Left -->
                    <div class="ic-left">
                        <div class="ic-icon">
                            <FileText :size="20" stroke-width="1.5" />
                        </div>
                        <div class="ic-info">
                            <div class="ic-number">
                                {{ invoice.invoice_number }}
                            </div>
                            <div class="ic-meta">
                                {{
                                    invoice.channel_subscription?.channel?.name
                                }}
                                ·
                                {{
                                    invoice.channel_subscription
                                        ?.household_count
                                }}
                                households ·
                                {{
                                    invoice.channel_subscription_payment?.payment_method?.toUpperCase()
                                }}
                            </div>
                            <div class="ic-meta">
                                Ref:
                                {{
                                    invoice.channel_subscription_payment
                                        ?.merchant_reference ?? '—'
                                }}
                            </div>
                            <div
                                class="ic-period"
                                v-if="
                                    invoice.channel_subscription
                                        ?.current_period_start
                                "
                            >
                                Period:
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
                            </div>
                            <div class="ic-dates">
                                Issued:
                                {{
                                    formatDate(
                                        invoice.issued_at ?? invoice.created_at,
                                    )
                                }}
                                <span
                                    v-if="
                                        invoice.channel_subscription_payment
                                            ?.paid_at
                                    "
                                >
                                    · Paid:
                                    {{
                                        formatDate(
                                            invoice.channel_subscription_payment
                                                .paid_at,
                                        )
                                    }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Right -->
                    <div class="ic-right">
                        <div class="ic-amount">{{ fmt(invoice.total) }}</div>
                        <span :class="['ic-badge', invoice.status]">
                            <component
                                :is="
                                    invoice.status === 'paid'
                                        ? CheckCircle
                                        : Clock
                                "
                                :size="11"
                                stroke-width="2.5"
                            />
                            {{
                                invoice.status === 'paid'
                                    ? 'Paid'
                                    : invoice.status
                            }}
                        </span>
                        <button
                            class="btn-download"
                            @click="downloadInvoice(invoice)"
                        >
                            <Download :size="13" stroke-width="2" />
                            Download PDF
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.ei-root {
    max-width: 1400px;
    margin: 0 auto;
    padding: 36px 24px 64px;
    font-family: 'Segoe UI', sans-serif;
    color: #111;
}

.ei-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
}
.ei-header-left {
    display: flex;
    align-items: center;
    gap: 14px;
}
.ei-icon {
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
.ei-title {
    font-size: 22px;
    font-weight: 800;
    letter-spacing: -0.5px;
    margin: 0 0 2px;
}
.ei-sub {
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

.invoice-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.invoice-card {
    background: #fff;
    border: 1.5px solid #ebebeb;
    border-radius: 16px;
    padding: 20px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    border-left: 4px solid #16a34a;
}

.ic-left {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    flex: 1;
    min-width: 0;
}
.ic-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: rgba(249, 115, 22, 0.08);
    color: #f97316;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.ic-info {
    flex: 1;
    min-width: 0;
}
.ic-number {
    font-size: 15px;
    font-weight: 800;
    color: #111;
    margin-bottom: 4px;
}
.ic-meta {
    font-size: 12px;
    color: #888;
    margin-top: 2px;
}
.ic-period {
    font-size: 12px;
    color: #555;
    font-weight: 600;
    margin-top: 4px;
}
.ic-dates {
    font-size: 11px;
    color: #aaa;
    margin-top: 3px;
}

.ic-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
    flex-shrink: 0;
}
.ic-amount {
    font-size: 22px;
    font-weight: 800;
    color: #f97316;
    letter-spacing: -0.5px;
}

.ic-badge {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 11px;
    font-weight: 700;
}
.ic-badge.paid {
    background: rgba(22, 163, 74, 0.1);
    color: #16a34a;
    border: 1px solid rgba(22, 163, 74, 0.2);
}
.ic-badge.void {
    background: rgba(100, 100, 100, 0.1);
    color: #888;
    border: 1px solid #e5e5e5;
}

.btn-download {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: #fff;
    color: #f97316;
    border: 1.5px solid #f97316;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    font-family: 'Segoe UI', sans-serif;
    transition: all 0.15s;
}
.btn-download:hover {
    background: rgba(249, 115, 22, 0.06);
}

.spinner {
    width: 28px;
    height: 28px;
    border: 3px solid #f0f0f0;
    border-top-color: #f97316;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

@media (max-width: 640px) {
    .invoice-card {
        flex-direction: column;
    }
    .ic-right {
        align-items: flex-start;
    }
}
</style>

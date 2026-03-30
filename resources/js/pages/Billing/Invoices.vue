<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { onMounted, ref } from 'vue';

const invoices = ref<any[]>([]);
const isLoading = ref(true);
const sending = ref<number | null>(null);
const flash = ref<{ msg: string; type: 'success' | 'error' } | null>(null);

const headers = {
    headers: { Authorization: `Bearer ${localStorage.getItem('token')}` },
};

const showFlash = (msg: string, type: 'success' | 'error' = 'success') => {
    flash.value = { msg, type };
    setTimeout(() => (flash.value = null), 4000);
};

const formatDate = (d: string) =>
    d
        ? new Date(d).toLocaleDateString('en-ZA', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '—';

const isOverdue = (inv: any) =>
    inv.status === 'issued' &&
    inv.due_date &&
    new Date(inv.due_date) < new Date();

const resolvedStatus = (inv: any) => (isOverdue(inv) ? 'overdue' : inv.status);

onMounted(async () => {
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/invoices`,
            headers,
        );
        invoices.value = data.invoices.data;
    } finally {
        isLoading.value = false;
    }
});

const downloadPdf = async (invoice: any) => {
    try {
        const response = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/invoices/${invoice.id}/pdf`,
            {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                },
                responseType: 'blob',
            },
        );
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `invoice-${invoice.invoice_number}.pdf`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    } catch {
        showFlash('Failed to download invoice.', 'error');
    }
};

const printInvoice = async (invoice: any) => {
    try {
        const response = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/invoices/${invoice.id}/print`,
            {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                },
                responseType: 'blob',
            },
        );
        const url = window.URL.createObjectURL(
            new Blob([response.data], { type: 'application/pdf' }),
        );
        window.open(url, '_blank');
    } catch {
        showFlash('Failed to open invoice.', 'error');
    }
};

const sendInvoice = async (invoice: any) => {
    try {
        sending.value = invoice.id;
        const { data } = await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/invoices/${invoice.id}/send`,
            {},
            headers,
        );
        showFlash(data.message);
    } catch {
        showFlash('Failed to send invoice.', 'error');
    } finally {
        sending.value = null;
    }
};
</script>

<template>
    <Head title="Invoices" />
    <AppLayout>
        <div class="inv-root">
            <div class="inv-header">
                <h1 class="inv-title">Invoices</h1>
                <p class="inv-sub">Download, print or email your invoices</p>
            </div>

            <!-- FLASH -->
            <div v-if="flash" :class="['flash', flash.type]">
                {{ flash.type === 'success' ? '✓' : '⚠' }} {{ flash.msg }}
            </div>

            <!-- LOADING -->
            <div v-if="isLoading" class="inv-loading">
                <div class="spinner"></div>
            </div>

            <!-- TABLE -->
            <div v-else-if="invoices.length" class="inv-card">
                <table class="inv-table">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Period</th>
                            <th>Amount</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th>Issued</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="inv in invoices"
                            :key="inv.id"
                            :class="{ 'row-overdue': isOverdue(inv) }"
                        >
                            <td class="mono">{{ inv.invoice_number }}</td>
                            <td class="period">
                                {{
                                    formatDate(
                                        inv.payment?.billing_period_start,
                                    )
                                }}
                                <span class="period-arrow">→</span>
                                {{
                                    formatDate(inv.payment?.billing_period_end)
                                }}
                            </td>
                            <td class="bold">{{ inv.total_in_rands }}</td>
                            <td>
                                <span
                                    :class="{ 'due-overdue': isOverdue(inv) }"
                                >
                                    {{ formatDate(inv.due_date) }}
                                </span>
                            </td>
                            <td>
                                <span
                                    :class="['inv-status', resolvedStatus(inv)]"
                                >
                                    {{ resolvedStatus(inv) }}
                                </span>
                            </td>
                            <td>{{ formatDate(inv.issued_at) }}</td>
                            <td>
                                <div class="inv-actions">
                                    <button
                                        class="act-btn"
                                        @click="downloadPdf(inv)"
                                        title="Download PDF"
                                    >
                                        ⬇ PDF
                                    </button>
                                    <button
                                        class="act-btn"
                                        @click="printInvoice(inv)"
                                        title="Print"
                                    >
                                        🖨 Print
                                    </button>
                                    <button
                                        class="act-btn"
                                        :disabled="sending === inv.id"
                                        @click="sendInvoice(inv)"
                                        title="Send via email"
                                    >
                                        <span v-if="sending === inv.id"
                                            >Sending...</span
                                        >
                                        <span v-else>✉ Email</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="inv-empty">
                No invoices yet. They are generated automatically after each
                payment.
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.inv-root {
    max-width: 1000px;
    margin: 0 auto;
    padding: 40px 24px;
    font-family: 'Segoe UI', sans-serif;
}
.inv-header {
    margin-bottom: 28px;
}
.inv-title {
    font-size: 24px;
    font-weight: 800;
    color: #111;
    letter-spacing: -0.5px;
    margin: 0 0 4px;
}
.inv-sub {
    font-size: 14px;
    color: #888;
    margin: 0;
}

.flash {
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 20px;
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

.inv-loading {
    display: flex;
    justify-content: center;
    padding: 80px 0;
}
.spinner {
    width: 32px;
    height: 32px;
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

.inv-card {
    background: #fff;
    border: 1.5px solid #e5e5e5;
    border-radius: 16px;
    overflow: hidden;
}
.inv-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.inv-table th {
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    color: #999;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 14px 16px;
    background: #fafafa;
    border-bottom: 1.5px solid #f0f0f0;
    white-space: nowrap;
}
.inv-table td {
    padding: 14px 16px;
    border-bottom: 1px solid #f9f9f9;
    color: #333;
}
.inv-table tr:last-child td {
    border-bottom: none;
}
.inv-table tr.row-overdue td {
    background: rgba(220, 38, 38, 0.02);
}

.mono {
    font-family: monospace;
    font-size: 12px;
    color: #555;
}
.bold {
    font-weight: 700;
}
.period {
    font-size: 12px;
    color: #666;
    white-space: nowrap;
}
.period-arrow {
    color: #ccc;
    margin: 0 4px;
}
.due-overdue {
    color: #dc2626;
    font-weight: 600;
}

.inv-status {
    font-size: 11px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 100px;
    text-transform: capitalize;
}
.inv-status.issued {
    background: rgba(249, 115, 22, 0.1);
    color: #f97316;
}
.inv-status.paid {
    background: rgba(22, 163, 74, 0.1);
    color: #16a34a;
}
.inv-status.overdue {
    background: rgba(220, 38, 38, 0.1);
    color: #dc2626;
}
.inv-status.void {
    background: #f5f5f5;
    color: #888;
}
.inv-status.draft {
    background: #f5f5f5;
    color: #aaa;
}

.inv-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}
.act-btn {
    padding: 5px 10px;
    font-size: 11px;
    font-weight: 600;
    font-family: 'Segoe UI', sans-serif;
    border: 1.5px solid #e5e5e5;
    border-radius: 8px;
    background: #fff;
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
}
.act-btn:hover:not(:disabled) {
    border-color: #f97316;
    color: #f97316;
}
.act-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.inv-empty {
    text-align: center;
    padding: 60px 24px;
    color: #aaa;
    font-size: 14px;
    background: #fff;
    border: 1.5px solid #e5e5e5;
    border-radius: 16px;
}

@media (max-width: 768px) {
    .inv-table {
        font-size: 12px;
    }
    .period {
        display: none;
    }
    .inv-table th:nth-child(2),
    .inv-table td:nth-child(2) {
        display: none;
    }
}
</style>

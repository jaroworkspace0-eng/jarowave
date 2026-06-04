<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import {
    AlertTriangle,
    Banknote,
    Bell,
    CheckCircle,
    ChevronDown,
    ChevronUp,
    CircleDollarSign,
    Hourglass,
    RefreshCw,
    Users,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

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
    withheld_amount: number;
    total_amount: number;
    earning_count: number;
    pending_count: number;
    earliest_period: string;
    latest_period: string;
    has_bank_details: boolean;
    bank_details: BankDetails | null;
}
interface Earning {
    id: number;
    household_name: string;
    resident_amount: number;
    earned_amount: number;
    platform_amount: number;
    commission_percentage: number;
    status: string;
    period_start: string;
    period_end: string;
    payout_at: string | null;
    payout_reference: string | null;
}
interface Totals {
    total_pending: number;
    total_paid: number;
    total_clients: number;
    clients_no_bank: number;
}

// ── State ─────────────────────────────────────────────────────────────────
const clients = ref<Client[]>([]);
const totals = ref<Totals | null>(null);
const isLoading = ref(true);
const isProcessing = ref(false);
const flash = ref<{ msg: string; type: 'success' | 'error' } | null>(null);

// Filters
const filterMonth = ref(new Date().getMonth() + 1);
const filterYear = ref(new Date().getFullYear());
const filterStatus = ref('pending');

// Expanded client drawer
const expandedClient = ref<number | null>(null);
const clientEarnings = ref<Record<number, Earning[]>>({});
const loadingEarnings = ref<number | null>(null);

// Selected earnings per client for processing
const selectedEarnings = ref<Record<number, Set<number>>>({});

// Process modal
const showProcessModal = ref(false);
const processingClient = ref<Client | null>(null);
const eftReference = ref('');

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

// ── Data ──────────────────────────────────────────────────────────────────
const fetchClients = async () => {
    isLoading.value = true;
    try {
        const params: any = { status: filterStatus.value };
        if (filterMonth.value) params.month = filterMonth.value;
        if (filterYear.value) params.year = filterYear.value;

        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/admin/payouts/clients`,
            { ...getHeaders(), params },
        );
        clients.value = data.clients;
        totals.value = data.totals;
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to load clients.',
            'error',
        );
    } finally {
        isLoading.value = false;
    }
};

const fetchClientEarnings = async (clientId: number) => {
    if (clientEarnings.value[clientId]) return;
    loadingEarnings.value = clientId;
    try {
        const params: any = { status: 'pending' };
        if (filterMonth.value) params.month = filterMonth.value;
        if (filterYear.value) params.year = filterYear.value;

        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/admin/payouts/clients/${clientId}/earnings`,
            { ...getHeaders(), params },
        );
        clientEarnings.value[clientId] = data.earnings;
        // Pre-select all pending earnings
        selectedEarnings.value[clientId] = new Set(
            data.earnings
                .filter((e: Earning) => e.status === 'pending')
                .map((e: Earning) => e.id),
        );
    } catch {
        showFlash('Failed to load earnings.', 'error');
    } finally {
        loadingEarnings.value = null;
    }
};

onMounted(fetchClients);

// ── Expand/collapse client drawer ─────────────────────────────────────────
const toggleClient = async (clientId: number) => {
    if (expandedClient.value === clientId) {
        expandedClient.value = null;
    } else {
        expandedClient.value = clientId;
        await fetchClientEarnings(clientId);
    }
};

// ── Selection ────────────────────────────────────────────────────────────
const toggleEarning = (clientId: number, earningId: number) => {
    if (!selectedEarnings.value[clientId]) {
        selectedEarnings.value[clientId] = new Set();
    }
    const set = selectedEarnings.value[clientId];
    set.has(earningId) ? set.delete(earningId) : set.add(earningId);
    selectedEarnings.value = { ...selectedEarnings.value };
};

const selectAll = (clientId: number) => {
    const earnings = clientEarnings.value[clientId] ?? [];
    selectedEarnings.value[clientId] = new Set(
        earnings.filter((e) => e.status === 'pending').map((e) => e.id),
    );
    selectedEarnings.value = { ...selectedEarnings.value };
};

const deselectAll = (clientId: number) => {
    selectedEarnings.value[clientId] = new Set();
    selectedEarnings.value = { ...selectedEarnings.value };
};

const selectedCount = (clientId: number) =>
    selectedEarnings.value[clientId]?.size ?? 0;

const selectedTotal = (clientId: number) => {
    const ids = selectedEarnings.value[clientId];
    if (!ids?.size) return 0;
    return (clientEarnings.value[clientId] ?? [])
        .filter((e) => ids.has(e.id))
        .reduce((sum, e) => sum + e.earned_amount, 0);
};

// ── Process payout ────────────────────────────────────────────────────────
const openProcessModal = (client: Client) => {
    processingClient.value = client;
    eftReference.value = '';
    showProcessModal.value = true;
};

const confirmProcess = async () => {
    if (!processingClient.value || !eftReference.value.trim()) return;

    const clientId = processingClient.value.client_id;
    const earningIds = Array.from(selectedEarnings.value[clientId] ?? []);

    if (!earningIds.length) {
        showFlash('No earnings selected.', 'error');
        return;
    }

    isProcessing.value = true;
    try {
        await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/admin/payouts/process`,
            {
                client_id: clientId,
                earning_ids: earningIds,
                eft_reference: eftReference.value.trim(),
            },
            getHeaders(),
        );
        showFlash(
            `Payout processed for ${processingClient.value.name}. Email sent.`,
        );
        showProcessModal.value = false;
        // Refresh
        delete clientEarnings.value[clientId];
        delete selectedEarnings.value[clientId];
        await fetchClients();
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to process payout.',
            'error',
        );
    } finally {
        isProcessing.value = false;
    }
};

// ── Notify no bank details ────────────────────────────────────────────────
const notifyNoBankDetails = async (client: Client) => {
    try {
        await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/admin/payouts/notify-bank-details`,
            { client_id: client.client_id },
            getHeaders(),
        );
        showFlash(`Notification sent to ${client.email}`);
    } catch {
        showFlash('Failed to send notification.', 'error');
    }
};

const statusColour = (s: string) =>
    ({ pending: 'orange', paid: 'green', withheld: 'red', approved: 'blue' })[
        s
    ] ?? 'gray';
</script>

<template>
    <Head title="Admin · Payouts" />
    <AppLayout>
        <div class="ap-root">
            <!-- HEADER -->
            <div class="ap-header">
                <div>
                    <h1 class="ap-title">Process Payouts</h1>
                    <p class="ap-sub">
                        Review pending earnings per client and process EFT
                        disbursements
                    </p>
                </div>
                <button class="btn-icon" @click="fetchClients" title="Refresh">
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
                <p>Loading payout data…</p>
            </div>

            <template v-else>
                <!-- FILTER BAR -->
                <div class="filter-bar">
                    <div class="filter-group">
                        <label class="filter-label">Month</label>
                        <select class="filter-select" v-model="filterMonth">
                            <option
                                v-for="(m, i) in months"
                                :key="i"
                                :value="i + 1"
                            >
                                {{ m }}
                            </option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Year</label>
                        <select class="filter-select" v-model="filterYear">
                            <option v-for="y in years" :key="y" :value="y">
                                {{ y }}
                            </option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Status</label>
                        <select class="filter-select" v-model="filterStatus">
                            <option value="pending">Pending</option>
                            <option value="all">All</option>
                        </select>
                    </div>
                    <button class="btn-primary sm" @click="fetchClients">
                        Apply
                    </button>
                </div>

                <!-- SUMMARY CARDS -->
                <div class="summary-row" v-if="totals">
                    <div class="sum-card">
                        <div class="sum-icon orange">
                            <Hourglass :size="18" stroke-width="2" />
                        </div>
                        <div>
                            <div class="sum-val">
                                {{ fmt(totals.total_pending) }}
                            </div>
                            <div class="sum-lbl">Total Pending</div>
                        </div>
                    </div>
                    <div class="sum-card">
                        <div class="sum-icon green">
                            <CircleDollarSign :size="18" stroke-width="2" />
                        </div>
                        <div>
                            <div class="sum-val">
                                {{ fmt(totals.total_paid) }}
                            </div>
                            <div class="sum-lbl">Total Paid Out</div>
                        </div>
                    </div>
                    <div class="sum-card">
                        <div class="sum-icon blue">
                            <Users :size="18" stroke-width="2" />
                        </div>
                        <div>
                            <div class="sum-val">
                                {{ totals.total_clients }}
                            </div>
                            <div class="sum-lbl">Clients with Earnings</div>
                        </div>
                    </div>
                    <div
                        class="sum-card"
                        :class="{ warn: totals.clients_no_bank > 0 }"
                    >
                        <div class="sum-icon red">
                            <AlertTriangle :size="18" stroke-width="2" />
                        </div>
                        <div>
                            <div class="sum-val">
                                {{ totals.clients_no_bank }}
                            </div>
                            <div class="sum-lbl">Missing Bank Details</div>
                        </div>
                    </div>
                </div>

                <!-- EMPTY -->
                <div v-if="!clients.length" class="empty-card">
                    <CircleDollarSign
                        :size="32"
                        stroke-width="1.5"
                        color="#bbb"
                    />
                    <div class="empty-title">No pending payouts</div>
                    <div class="empty-desc">
                        All clients have been paid out for the selected period.
                    </div>
                </div>

                <!-- CLIENT LIST -->
                <div
                    v-for="client in clients"
                    :key="client.client_id"
                    class="client-card"
                >
                    <!-- Client row header -->
                    <div
                        class="client-row"
                        @click="toggleClient(client.client_id)"
                    >
                        <div class="cr-left">
                            <div class="cr-avatar">
                                {{ client.name.charAt(0).toUpperCase() }}
                            </div>
                            <div>
                                <div class="cr-name">
                                    {{ client.organisation }}
                                </div>
                                <div class="cr-email">{{ client.email }}</div>
                            </div>
                        </div>

                        <div class="cr-middle">
                            <div class="cr-stat">
                                <div class="crs-val orange-text">
                                    {{ fmt(client.pending_amount) }}
                                </div>
                                <div class="crs-lbl">Pending</div>
                            </div>
                            <div class="cr-stat">
                                <div class="crs-val">
                                    {{ client.pending_count }}
                                </div>
                                <div class="crs-lbl">Earnings</div>
                            </div>
                            <div class="cr-stat">
                                <div class="crs-val green-text">
                                    {{ fmt(client.paid_amount) }}
                                </div>
                                <div class="crs-lbl">Paid</div>
                            </div>
                        </div>

                        <div class="cr-right">
                            <!-- No bank details warning -->
                            <div
                                v-if="!client.has_bank_details"
                                class="no-bank-tag"
                                @click.stop
                            >
                                <AlertTriangle :size="13" />
                                No bank details
                                <button
                                    class="notify-btn"
                                    @click.stop="notifyNoBankDetails(client)"
                                >
                                    <Bell :size="12" /> Notify
                                </button>
                            </div>

                            <!-- Bank details pill -->
                            <div v-else class="bank-tag">
                                <Banknote :size="13" />
                                {{ client.bank_details?.bank_name }}
                                ···{{
                                    client.bank_details?.account_number.slice(
                                        -4,
                                    )
                                }}
                            </div>

                            <button
                                v-if="
                                    client.has_bank_details &&
                                    client.pending_count > 0
                                "
                                class="btn-process"
                                @click.stop="openProcessModal(client)"
                            >
                                Process Payout
                            </button>

                            <button class="btn-chevron">
                                <ChevronUp
                                    v-if="expandedClient === client.client_id"
                                    :size="16"
                                />
                                <ChevronDown v-else :size="16" />
                            </button>
                        </div>
                    </div>

                    <!-- Expanded earnings drawer -->
                    <div
                        v-if="expandedClient === client.client_id"
                        class="earnings-drawer"
                    >
                        <div
                            v-if="loadingEarnings === client.client_id"
                            class="drawer-loading"
                        >
                            <div class="spinner sm"></div>
                            Loading earnings…
                        </div>

                        <template
                            v-else-if="clientEarnings[client.client_id]?.length"
                        >
                            <!-- Selection toolbar -->
                            <div class="drawer-toolbar">
                                <div class="dt-left">
                                    <button
                                        class="btn-ghost xs"
                                        @click="selectAll(client.client_id)"
                                    >
                                        Select all pending
                                    </button>
                                    <button
                                        class="btn-ghost xs"
                                        @click="deselectAll(client.client_id)"
                                    >
                                        Deselect all
                                    </button>
                                    <span class="sel-count">
                                        {{
                                            selectedCount(client.client_id)
                                        }}
                                        selected ·
                                        {{
                                            fmt(selectedTotal(client.client_id))
                                        }}
                                    </span>
                                </div>
                                <button
                                    v-if="
                                        selectedCount(client.client_id) > 0 &&
                                        client.has_bank_details
                                    "
                                    class="btn-process sm"
                                    @click="openProcessModal(client)"
                                >
                                    Process Selected
                                </button>
                            </div>

                            <!-- Earnings table -->
                            <div class="table-wrap">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th></th>
                                            <th>Household</th>
                                            <th>Period</th>
                                            <th>Household Paid</th>
                                            <th>Platform Fee</th>
                                            <th>Your Share</th>
                                            <th>Commission</th>
                                            <th>Status</th>
                                            <th>Payout Ref</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="e in clientEarnings[
                                                client.client_id
                                            ]"
                                            :key="e.id"
                                            :class="{
                                                selected: selectedEarnings[
                                                    client.client_id
                                                ]?.has(e.id),
                                            }"
                                        >
                                            <td>
                                                <input
                                                    v-if="
                                                        e.status === 'pending'
                                                    "
                                                    type="checkbox"
                                                    :checked="
                                                        selectedEarnings[
                                                            client.client_id
                                                        ]?.has(e.id)
                                                    "
                                                    @change="
                                                        toggleEarning(
                                                            client.client_id,
                                                            e.id,
                                                        )
                                                    "
                                                    class="chk"
                                                />
                                                <CheckCircle
                                                    v-else
                                                    :size="14"
                                                    color="#16a34a"
                                                />
                                            </td>
                                            <td class="fw6">
                                                {{ e.household_name }}
                                            </td>
                                            <td class="muted small">
                                                {{ formatDate(e.period_start) }}
                                                <span class="arrow">→</span>
                                                {{ formatDate(e.period_end) }}
                                            </td>
                                            <td>
                                                {{ fmt(e.resident_amount) }}
                                            </td>
                                            <td class="red-text">
                                                {{ fmt(e.platform_amount) }}
                                            </td>
                                            <td class="fw7 green-text">
                                                {{ fmt(e.earned_amount) }}
                                            </td>
                                            <td class="muted">
                                                {{ e.commission_percentage }}%
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
                                            <td class="mono small muted">
                                                {{ e.payout_reference ?? '—' }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </template>

                        <div v-else class="drawer-empty">
                            No earnings found for this period.
                        </div>
                    </div>
                </div>
            </template>

            <!-- ── PROCESS MODAL ─────────────────────────────────────── -->
            <div
                v-if="showProcessModal && processingClient"
                class="modal-overlay"
                @click.self="showProcessModal = false"
            >
                <div class="modal">
                    <div class="modal-title">Confirm Payout</div>
                    <p class="modal-note">
                        You are about to mark
                        {{
                            selectedCount(processingClient.client_id)
                        }}
                        earning(s) as paid for
                        <strong>{{ processingClient.organisation }}</strong
                        >.
                    </p>

                    <!-- Payout summary -->
                    <div class="payout-summary">
                        <div class="ps-row">
                            <span>Selected earnings</span>
                            <span>{{
                                selectedCount(processingClient.client_id)
                            }}</span>
                        </div>
                        <div class="ps-row">
                            <span>Amount to transfer</span>
                            <span class="fw7 green-text">{{
                                fmt(selectedTotal(processingClient.client_id))
                            }}</span>
                        </div>
                    </div>

                    <!-- Bank details confirmation -->
                    <div
                        class="bank-confirm"
                        v-if="processingClient.bank_details"
                    >
                        <div class="bc-title">
                            <Banknote :size="14" /> Transfer to:
                        </div>
                        <div class="bc-grid">
                            <div>
                                <span class="bc-lbl">Bank</span
                                ><span class="bc-val">{{
                                    processingClient.bank_details.bank_name
                                }}</span>
                            </div>
                            <div>
                                <span class="bc-lbl">Account</span
                                ><span class="bc-val mono">{{
                                    processingClient.bank_details.account_number
                                }}</span>
                            </div>
                            <div>
                                <span class="bc-lbl">Branch</span
                                ><span class="bc-val mono">{{
                                    processingClient.bank_details.branch_code
                                }}</span>
                            </div>
                            <div>
                                <span class="bc-lbl">Holder</span
                                ><span class="bc-val">{{
                                    processingClient.bank_details.account_holder
                                }}</span>
                            </div>
                            <div>
                                <span class="bc-lbl">Type</span
                                ><span class="bc-val">{{
                                    processingClient.bank_details.account_type
                                }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- EFT Reference -->
                    <div class="mf">
                        <label class="ml">EFT / Bank Transfer Reference</label>
                        <input
                            class="mi"
                            type="text"
                            v-model="eftReference"
                            placeholder="e.g. FNB-20260601-001"
                            autofocus
                        />
                        <p class="mi-hint">
                            Enter the reference from your bank after completing
                            the transfer.
                        </p>
                    </div>

                    <div class="modal-actions">
                        <button
                            class="btn-ghost"
                            @click="showProcessModal = false"
                        >
                            Cancel
                        </button>
                        <button
                            class="btn-process"
                            :disabled="isProcessing || !eftReference.trim()"
                            @click="confirmProcess"
                        >
                            <span
                                v-if="isProcessing"
                                class="btn-spinner"
                            ></span>
                            <span v-else>Confirm &amp; Mark Paid</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.ap-root {
    max-width: 1100px;
    margin: 0 auto;
    padding: 36px 24px 64px;
    font-family: 'Segoe UI', sans-serif;
    color: #111;
}
.ap-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 24px;
}
.ap-title {
    font-size: 22px;
    font-weight: 800;
    letter-spacing: -0.5px;
    margin: 0 0 4px;
}
.ap-sub {
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

/* Filter bar */
.filter-bar {
    display: flex;
    align-items: flex-end;
    gap: 12px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.filter-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.filter-label {
    font-size: 11px;
    font-weight: 700;
    color: #888;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.filter-select {
    padding: 8px 12px;
    border: 1.5px solid #e5e5e5;
    border-radius: 10px;
    font-size: 13px;
    color: #111;
    outline: none;
    font-family: 'Segoe UI', sans-serif;
    background: #fff;
}
.filter-select:focus {
    border-color: #f97316;
}

/* Summary cards */
.summary-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 20px;
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
.sum-card.warn {
    border-color: rgba(220, 38, 38, 0.2);
    background: rgba(220, 38, 38, 0.02);
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
.sum-icon.orange {
    background: rgba(249, 115, 22, 0.1);
    color: #f97316;
}
.sum-icon.green {
    background: rgba(22, 163, 74, 0.1);
    color: #16a34a;
}
.sum-icon.blue {
    background: rgba(37, 99, 235, 0.1);
    color: #2563eb;
}
.sum-icon.red {
    background: rgba(220, 38, 38, 0.1);
    color: #dc2626;
}
.sum-val {
    font-size: 18px;
    font-weight: 800;
    color: #111;
    letter-spacing: -0.5px;
}
.sum-lbl {
    font-size: 11px;
    color: #aaa;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 2px;
}

/* Client cards */
.client-card {
    background: #fff;
    border: 1.5px solid #ebebeb;
    border-radius: 16px;
    margin-bottom: 12px;
    overflow: hidden;
}
.client-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 24px;
    cursor: pointer;
    gap: 16px;
    flex-wrap: wrap;
    transition: background 0.15s;
}
.client-row:hover {
    background: #fafafa;
}
.cr-left {
    display: flex;
    align-items: center;
    gap: 14px;
    flex: 1;
    min-width: 200px;
}
.cr-avatar {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    background: linear-gradient(135deg, #f97316, #ea580c);
    color: #fff;
    font-size: 16px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.cr-name {
    font-size: 14px;
    font-weight: 700;
    color: #111;
}
.cr-email {
    font-size: 12px;
    color: #888;
}
.cr-middle {
    display: flex;
    align-items: center;
    gap: 24px;
}
.cr-stat {
    text-align: center;
}
.crs-val {
    font-size: 16px;
    font-weight: 800;
    color: #111;
}
.crs-lbl {
    font-size: 11px;
    color: #aaa;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.cr-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.no-bank-tag {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 700;
    color: #dc2626;
    background: rgba(220, 38, 38, 0.08);
    border: 1px solid rgba(220, 38, 38, 0.2);
    padding: 4px 10px;
    border-radius: 100px;
}
.bank-tag {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 600;
    color: #16a34a;
    background: rgba(22, 163, 74, 0.08);
    border: 1px solid rgba(22, 163, 74, 0.2);
    padding: 4px 10px;
    border-radius: 100px;
}
.notify-btn {
    display: flex;
    align-items: center;
    gap: 3px;
    background: #dc2626;
    color: #fff;
    border: none;
    border-radius: 6px;
    padding: 2px 7px;
    font-size: 10px;
    font-weight: 700;
    cursor: pointer;
    margin-left: 4px;
}
.btn-chevron {
    background: none;
    border: none;
    cursor: pointer;
    color: #aaa;
    display: flex;
    padding: 4px;
}

.btn-process {
    padding: 8px 16px;
    background: #f97316;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 6px;
    font-family: 'Segoe UI', sans-serif;
    white-space: nowrap;
}
.btn-process:hover:not(:disabled) {
    background: #ea580c;
}
.btn-process:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.btn-process.sm {
    font-size: 12px;
    padding: 6px 12px;
}

/* Drawer */
.earnings-drawer {
    border-top: 1.5px solid #f0f0f0;
    padding: 16px 24px 20px;
}
.drawer-loading {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #888;
    font-size: 13px;
    padding: 16px 0;
}
.drawer-empty {
    color: #aaa;
    font-size: 13px;
    padding: 16px 0;
}
.drawer-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
    flex-wrap: wrap;
    gap: 8px;
}
.dt-left {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.sel-count {
    font-size: 12px;
    color: #888;
    font-weight: 600;
}

/* Table */
.table-wrap {
    overflow-x: auto;
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
    padding: 0 12px 8px 0;
    border-bottom: 1.5px solid #f0f0f0;
    white-space: nowrap;
}
.data-table td {
    padding: 10px 12px 10px 0;
    color: #333;
    border-bottom: 1px solid #f7f7f7;
}
.data-table tr:last-child td {
    border-bottom: none;
}
.data-table tr.selected td {
    background: rgba(249, 115, 22, 0.03);
}
.chk {
    width: 15px;
    height: 15px;
    accent-color: #f97316;
    cursor: pointer;
}

/* Badges */
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
.badge.blue {
    background: rgba(37, 99, 235, 0.1);
    color: #2563eb;
}
.badge.gray {
    background: #f0f0f0;
    color: #888;
}

/* Utility */
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
.green-text {
    color: #16a34a;
}
.red-text {
    color: #dc2626;
}
.orange-text {
    color: #f97316;
}
.arrow {
    color: #ddd;
    margin: 0 4px;
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

.payout-summary {
    background: #f9f9f9;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 18px;
}
.ps-row {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    color: #555;
    padding: 4px 0;
}

.bank-confirm {
    background: rgba(22, 163, 74, 0.04);
    border: 1.5px solid rgba(22, 163, 74, 0.15);
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 18px;
}
.bc-title {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 700;
    color: #16a34a;
    margin-bottom: 10px;
}
.bc-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}
.bc-lbl {
    font-size: 11px;
    color: #aaa;
    display: block;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.bc-val {
    font-size: 13px;
    font-weight: 600;
    color: #111;
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
.btn-ghost.xs {
    padding: 5px 10px;
    font-size: 12px;
    border-radius: 8px;
}

.btn-primary {
    padding: 9px 18px;
    background: #f97316;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    font-family: 'Segoe UI', sans-serif;
    transition: all 0.2s;
}
.btn-primary:hover {
    background: #ea580c;
}
.btn-primary.sm {
    font-size: 12px;
    padding: 7px 14px;
}

.spinner {
    width: 28px;
    height: 28px;
    border: 3px solid #f0f0f0;
    border-top-color: #f97316;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
}
.spinner.sm {
    width: 16px;
    height: 16px;
    border-width: 2px;
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
    .client-row {
        flex-direction: column;
        align-items: flex-start;
    }
    .cr-middle {
        flex-wrap: wrap;
    }
    .summary-row {
        grid-template-columns: 1fr 1fr;
    }
    .bc-grid {
        grid-template-columns: 1fr;
    }
}
</style>

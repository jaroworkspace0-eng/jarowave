<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import {
    AlertTriangle,
    Banknote,
    Bell,
    CircleDollarSign,
    Copy,
    Hourglass,
    RefreshCw,
    Shield,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

const auth = useAuthStore();

onMounted(() => {
    if (auth.user?.role !== 'admin') {
        router.visit('/dashboard'); // redirect non-admins away
    }
});

// ── Types ─────────────────────────────────────────────────────────────────
interface BankDetails {
    bank_name: string;
    account_holder: string;
    account_number: string;
    account_type: string;
    branch_code: string;
}
interface Guard {
    user_id: number;
    name: string;
    email: string;
    pending_amount: number;
    paid_amount: number;
    total_amount: number;
    earning_count: number;
    pending_count: number;
    earliest_period: string;
    latest_period: string;
    has_bank_details: boolean;
    bank_details: BankDetails | null;
}
interface Totals {
    total_pending: number;
    total_paid: number;
    total_guards: number;
    guards_no_bank: number;
}

// ── State ─────────────────────────────────────────────────────────────────
const guards = ref<Guard[]>([]);
const totals = ref<Totals | null>(null);
const isLoading = ref(true);
const isProcessing = ref(false);
const flash = ref<{ msg: string; type: 'success' | 'error' } | null>(null);

const filterMonth = ref(new Date().getMonth() + 1);
const filterYear = ref(new Date().getFullYear());
const filterStatus = ref('pending');

const selectedGuards = ref<Set<number>>(new Set());

const showProcessModal = ref(false);
const eftReference = ref('');
const generatedPayoutRef = ref('');
const copiedRef = ref(false);
const copiedAccount = ref<number | null>(null);

const notifying = ref<number | null>(null);

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

const generatePayoutRef = () => {
    const now = new Date();
    const yyyy = now.getFullYear();
    const mm = String(now.getMonth() + 1).padStart(2, '0');
    const seq = String(Math.floor(Math.random() * 900) + 100);
    return `GPAY-${yyyy}-${mm}-${seq}`;
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

const copyPayoutRef = () => {
    copyToClipboard(generatedPayoutRef.value, () => {
        copiedRef.value = true;
        setTimeout(() => (copiedRef.value = false), 2000);
    });
};

const copyAccountNumber = (userId: number, accountNumber: string) => {
    copyToClipboard(accountNumber, () => {
        copiedAccount.value = userId;
        setTimeout(() => (copiedAccount.value = null), 2000);
    });
};

// ── Computed ──────────────────────────────────────────────────────────────
const eligibleGuards = computed(() =>
    guards.value.filter((g) => g.has_bank_details && g.pending_count > 0),
);

const selectedList = computed(() =>
    guards.value.filter((g) => selectedGuards.value.has(g.user_id)),
);

const selectedTotal = computed(() =>
    selectedList.value.reduce((sum, g) => sum + g.pending_amount, 0),
);

const selectedEarningCount = computed(() =>
    selectedList.value.reduce((sum, g) => sum + g.pending_count, 0),
);

const allEligibleSelected = computed(
    () =>
        eligibleGuards.value.length > 0 &&
        eligibleGuards.value.every((g) => selectedGuards.value.has(g.user_id)),
);

// ── Data ──────────────────────────────────────────────────────────────────
const fetchGuards = async () => {
    isLoading.value = true;
    selectedGuards.value = new Set();
    try {
        const params: any = { status: filterStatus.value };
        if (filterMonth.value) params.month = filterMonth.value;
        if (filterYear.value) params.year = filterYear.value;

        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/admin/payouts/guards`,
            { ...getHeaders(), params },
        );
        guards.value = data.guards;
        totals.value = data.totals;
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to load guards.',
            'error',
        );
    } finally {
        isLoading.value = false;
    }
};

onMounted(fetchGuards);

// ── Selection ─────────────────────────────────────────────────────────────
const toggleGuard = (userId: number) => {
    const s = new Set(selectedGuards.value);
    s.has(userId) ? s.delete(userId) : s.add(userId);
    selectedGuards.value = s;
};

const toggleSelectAll = () => {
    if (allEligibleSelected.value) {
        selectedGuards.value = new Set();
    } else {
        selectedGuards.value = new Set(
            eligibleGuards.value.map((g) => g.user_id),
        );
    }
};

// ── Process ───────────────────────────────────────────────────────────────
const openProcessModal = () => {
    generatedPayoutRef.value = generatePayoutRef();
    eftReference.value = generatedPayoutRef.value;
    copiedRef.value = false;
    copiedAccount.value = null;
    showProcessModal.value = true;
};

const confirmProcess = async () => {
    if (!eftReference.value.trim() || !selectedList.value.length) return;
    isProcessing.value = true;

    try {
        for (const guard of selectedList.value) {
            const params: any = { status: 'pending' };
            if (filterMonth.value) params.month = filterMonth.value;
            if (filterYear.value) params.year = filterYear.value;

            const { data } = await axios.get(
                `${import.meta.env.VITE_APP_URL}/api/admin/payouts/guards/${guard.user_id}/earnings`,
                { ...getHeaders(), params },
            );

            const earningIds = data.earnings
                .filter((e: any) => e.status === 'pending')
                .map((e: any) => e.id);

            if (!earningIds.length) continue;

            await axios.post(
                `${import.meta.env.VITE_APP_URL}/api/admin/payouts/guards/process`,
                {
                    user_id: guard.user_id,
                    earning_ids: earningIds,
                    eft_reference: eftReference.value.trim(),
                },
                getHeaders(),
            );
        }

        showFlash(
            `Payout processed for ${selectedList.value.length} guard(s). Confirmation emails sent.`,
        );
        showProcessModal.value = false;
        await fetchGuards();
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
const notifyNoBankDetails = async (guard: Guard) => {
    notifying.value = guard.user_id;
    try {
        await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/admin/payouts/guards/notify-bank-details`,
            { user_id: guard.user_id },
            getHeaders(),
        );
        showFlash(`Notification sent to ${guard.email}`);
    } catch {
        showFlash('Failed to send notification.', 'error');
    } finally {
        notifying.value = null;
    }
};
</script>

<template>
    <Head title="Admin · Gate Guard Payouts" />
    <AppLayout>
        <div class="ap-root">
            <!-- HEADER -->
            <div class="ap-header">
                <div>
                    <h1 class="ap-title">Gate Guard Payouts</h1>
                    <p class="ap-sub">
                        Select guards to process EFT disbursements · paid on the
                        1st of each month
                    </p>
                </div>
                <button class="btn-icon" @click="fetchGuards" title="Refresh">
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
                        <label class="filter-label">Show</label>
                        <select class="filter-select" v-model="filterStatus">
                            <option value="pending">Pending only</option>
                            <option value="all">All</option>
                        </select>
                    </div>
                    <button class="btn-apply" @click="fetchGuards">
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
                            <Shield :size="18" stroke-width="2" />
                        </div>
                        <div>
                            <div class="sum-val">{{ totals.total_guards }}</div>
                            <div class="sum-lbl">Guards with Earnings</div>
                        </div>
                    </div>
                    <div
                        class="sum-card"
                        :class="{ warn: totals.guards_no_bank > 0 }"
                    >
                        <div class="sum-icon red">
                            <AlertTriangle :size="18" stroke-width="2" />
                        </div>
                        <div>
                            <div class="sum-val">
                                {{ totals.guards_no_bank }}
                            </div>
                            <div class="sum-lbl">Missing Bank Details</div>
                        </div>
                    </div>
                </div>

                <!-- EMPTY -->
                <div v-if="!guards.length" class="empty-card">
                    <Shield :size="32" stroke-width="1.5" color="#bbb" />
                    <div class="empty-title">No pending payouts</div>
                    <div class="empty-desc">
                        All guards have been paid out for the selected period.
                    </div>
                </div>

                <template v-else>
                    <!-- BULK ACTION BAR -->
                    <div class="bulk-bar">
                        <div class="bulk-left">
                            <label class="select-all-wrap">
                                <input
                                    type="checkbox"
                                    class="chk"
                                    :checked="allEligibleSelected"
                                    :indeterminate="
                                        selectedGuards.size > 0 &&
                                        !allEligibleSelected
                                    "
                                    @change="toggleSelectAll"
                                />
                                <span class="sal-label">
                                    {{
                                        allEligibleSelected
                                            ? 'Deselect all'
                                            : 'Select all eligible'
                                    }}
                                </span>
                            </label>
                            <span
                                v-if="selectedGuards.size > 0"
                                class="sel-summary"
                            >
                                {{ selectedGuards.size }} guard{{
                                    selectedGuards.size !== 1 ? 's' : ''
                                }}
                                selected ·
                                <strong>{{ fmt(selectedTotal) }}</strong> ·
                                {{ selectedEarningCount }} earning{{
                                    selectedEarningCount !== 1 ? 's' : ''
                                }}
                            </span>
                        </div>
                        <button
                            v-if="selectedGuards.size > 0"
                            class="btn-process"
                            @click="openProcessModal"
                        >
                            Process {{ selectedGuards.size }} Payout{{
                                selectedGuards.size !== 1 ? 's' : ''
                            }}
                        </button>
                    </div>

                    <!-- GUARD LIST -->
                    <div class="client-list">
                        <div
                            v-for="guard in guards"
                            :key="guard.user_id"
                            :class="[
                                'client-card',
                                {
                                    selected: selectedGuards.has(guard.user_id),
                                    'no-bank': !guard.has_bank_details,
                                    ineligible:
                                        !guard.has_bank_details ||
                                        guard.pending_count === 0,
                                },
                            ]"
                            @click="
                                guard.has_bank_details &&
                                guard.pending_count > 0 &&
                                toggleGuard(guard.user_id)
                            "
                        >
                            <div class="cc-check">
                                <input
                                    v-if="
                                        guard.has_bank_details &&
                                        guard.pending_count > 0
                                    "
                                    type="checkbox"
                                    class="chk"
                                    :checked="selectedGuards.has(guard.user_id)"
                                    @change="toggleGuard(guard.user_id)"
                                    @click.stop
                                />
                                <div v-else class="chk-disabled"></div>
                            </div>

                            <div class="cc-avatar">
                                {{ guard.name.charAt(0).toUpperCase() }}
                            </div>

                            <div class="cc-info">
                                <div class="cc-name">{{ guard.name }}</div>
                                <div class="cc-email">{{ guard.email }}</div>
                                <div
                                    class="cc-period"
                                    v-if="guard.earliest_period"
                                >
                                    {{ formatDate(guard.earliest_period) }} →
                                    {{ formatDate(guard.latest_period) }}
                                </div>
                            </div>

                            <div class="cc-stats">
                                <div class="cc-stat">
                                    <div class="ccs-val orange-text">
                                        {{ fmt(guard.pending_amount) }}
                                    </div>
                                    <div class="ccs-lbl">Pending</div>
                                </div>
                                <div class="cc-stat">
                                    <div class="ccs-val">
                                        {{ guard.pending_count }}
                                    </div>
                                    <div class="ccs-lbl">Earnings</div>
                                </div>
                                <div class="cc-stat">
                                    <div class="ccs-val green-text">
                                        {{ fmt(guard.paid_amount) }}
                                    </div>
                                    <div class="ccs-lbl">Paid to date</div>
                                </div>
                            </div>

                            <div class="cc-right">
                                <div
                                    v-if="guard.has_bank_details"
                                    class="bank-tag"
                                >
                                    <Banknote :size="13" />
                                    {{ guard.bank_details?.bank_name }}
                                    ···{{
                                        guard.bank_details?.account_number.slice(
                                            -4,
                                        )
                                    }}
                                </div>
                                <div v-else class="no-bank-tag">
                                    No bank details
                                    <button
                                        class="notify-btn"
                                        :disabled="notifying === guard.user_id"
                                        @click.stop="notifyNoBankDetails(guard)"
                                    >
                                        <Bell :size="11" />
                                        {{
                                            notifying === guard.user_id
                                                ? 'Sending…'
                                                : 'Notify'
                                        }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </template>

            <!-- ── PROCESS MODAL ───────────────────────────────────────────── -->
            <div
                v-if="showProcessModal"
                class="modal-overlay"
                @click.self="showProcessModal = false"
            >
                <div class="modal">
                    <div class="modal-title">Confirm Payouts</div>
                    <p class="modal-note">
                        You are about to process payouts for
                        <strong
                            >{{ selectedList.length }} guard{{
                                selectedList.length !== 1 ? 's' : ''
                            }}</strong
                        >. Use the reference below when making each transfer in
                        your banking app.
                    </p>

                    <div class="payout-ref-banner">
                        <div class="prb-top">
                            <span class="prb-label">Payout Reference</span>
                            <span class="prb-hint"
                                >Use this as the Beneficiary Reference in your
                                banking app</span
                            >
                        </div>
                        <div class="prb-row">
                            <span class="prb-value">{{
                                generatedPayoutRef
                            }}</span>
                            <button class="prb-copy-btn" @click="copyPayoutRef">
                                <Copy :size="13" />
                                {{ copiedRef ? 'Copied!' : 'Copy' }}
                            </button>
                        </div>
                    </div>

                    <div class="client-summary-list">
                        <div
                            v-for="g in selectedList"
                            :key="g.user_id"
                            class="cs-row"
                        >
                            <div class="cs-left">
                                <div class="cs-name">{{ g.name }}</div>
                                <div class="cs-bank-row">
                                    <span class="cs-bank muted small">
                                        {{ g.bank_details?.bank_name }} ·
                                        {{ g.bank_details?.account_number }} ·
                                        {{ g.bank_details?.account_type }} ·
                                        Branch {{ g.bank_details?.branch_code }}
                                    </span>
                                    <button
                                        class="acct-copy-btn"
                                        @click.stop="
                                            copyAccountNumber(
                                                g.user_id,
                                                g.bank_details
                                                    ?.account_number ?? '',
                                            )
                                        "
                                        title="Copy account number"
                                    >
                                        <Copy :size="11" />
                                        {{
                                            copiedAccount === g.user_id
                                                ? 'Copied!'
                                                : 'Copy acc.'
                                        }}
                                    </button>
                                </div>
                            </div>
                            <div class="cs-amount">
                                {{ fmt(g.pending_amount) }}
                            </div>
                        </div>
                        <div class="cs-total">
                            <span>Total to transfer</span>
                            <span class="fw7 green-text">{{
                                fmt(selectedTotal)
                            }}</span>
                        </div>
                    </div>

                    <div class="mf">
                        <label class="ml"
                            >Beneficiary Reference (used when making the
                            transfer)</label
                        >
                        <input
                            class="mi"
                            type="text"
                            v-model="eftReference"
                            placeholder="e.g. GPAY-2026-06-001"
                            autofocus
                        />
                        <p class="mi-hint">
                            This is pre-filled with the generated reference
                            above. Edit only if you used a different reference
                            in your banking app.
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
/* identical to AdminPayouts.vue — no changes needed */
.ap-root {
    max-width: 1400px;
    margin: 0 auto;
    padding: 36px 24px 64px;
    font-family: 'Segoe UI', sans-serif;
    color: #111;
    width: 100%;
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
.btn-apply {
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
    align-self: flex-end;
}
.btn-apply:hover {
    background: #ea580c;
}
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
.bulk-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    background: #fff;
    border: 1.5px solid #ebebeb;
    border-radius: 12px;
    margin-bottom: 12px;
    flex-wrap: wrap;
    gap: 10px;
}
.bulk-left {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
.select-all-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}
.sal-label {
    font-size: 13px;
    font-weight: 600;
    color: #555;
}
.sel-summary {
    font-size: 13px;
    color: #888;
}
.client-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.client-card {
    background: #fff;
    border: 1.5px solid #ebebeb;
    border-radius: 14px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    cursor: pointer;
    transition: all 0.15s;
    flex-wrap: wrap;
}
.client-card:hover:not(.ineligible) {
    border-color: #f97316;
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.06);
}
.client-card.selected {
    border-color: #f97316;
    background: rgba(249, 115, 22, 0.02);
}
.client-card.ineligible {
    cursor: default;
    opacity: 0.7;
}
.client-card.no-bank {
    border-color: rgba(220, 38, 38, 0.2);
    background: rgba(220, 38, 38, 0.01);
}
.cc-check {
    flex-shrink: 0;
}
.chk {
    width: 16px;
    height: 16px;
    accent-color: #f97316;
    cursor: pointer;
}
.chk-disabled {
    width: 16px;
    height: 16px;
    border: 1.5px solid #ddd;
    border-radius: 4px;
    background: #f9f9f9;
}
.cc-avatar {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: linear-gradient(135deg, #1d4ed8, #1e40af);
    color: #fff;
    font-size: 18px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.cc-info {
    flex: 1;
    min-width: 160px;
}
.cc-name {
    font-size: 14px;
    font-weight: 700;
    color: #111;
}
.cc-email {
    font-size: 12px;
    color: #888;
}
.cc-period {
    font-size: 11px;
    color: #aaa;
    margin-top: 2px;
}
.cc-stats {
    display: flex;
    gap: 24px;
    flex-wrap: wrap;
}
.cc-stat {
    text-align: center;
}
.ccs-val {
    font-size: 16px;
    font-weight: 800;
    color: #111;
}
.ccs-lbl {
    font-size: 11px;
    color: #aaa;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.cc-right {
    margin-left: auto;
    display: flex;
    align-items: center;
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
    padding: 5px 12px;
    border-radius: 100px;
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
    padding: 5px 12px;
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
    padding: 3px 8px;
    font-size: 10px;
    font-weight: 700;
    cursor: pointer;
    margin-left: 6px;
    transition: all 0.15s;
}
.notify-btn:hover:not(:disabled) {
    background: #b91c1c;
}
.notify-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
.btn-process {
    padding: 10px 20px;
    background: #f97316;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 8px;
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
.client-summary-list {
    border: 1.5px solid #f0f0f0;
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 20px;
}
.cs-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-bottom: 1px solid #f7f7f7;
    gap: 12px;
}
.cs-left {
    flex: 1;
    min-width: 0;
}
.cs-name {
    font-size: 13px;
    font-weight: 700;
    color: #111;
}
.cs-bank-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 3px;
    flex-wrap: wrap;
}
.cs-bank {
    flex-shrink: 1;
    min-width: 0;
}
.cs-amount {
    font-size: 14px;
    font-weight: 800;
    color: #f97316;
    white-space: nowrap;
}
.cs-total {
    display: flex;
    justify-content: space-between;
    padding: 12px 16px;
    background: #f9f9f9;
    font-size: 13px;
    color: #555;
}
.acct-copy-btn {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 2px 8px;
    background: transparent;
    color: #888;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 600;
    cursor: pointer;
    font-family: 'Segoe UI', sans-serif;
    transition: all 0.15s;
    white-space: nowrap;
    flex-shrink: 0;
}
.acct-copy-btn:hover {
    border-color: #f97316;
    color: #f97316;
    background: rgba(249, 115, 22, 0.04);
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
.fw7 {
    font-weight: 700;
}
.muted {
    color: #888;
}
.small {
    font-size: 12px;
}
.green-text {
    color: #16a34a;
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
    .client-card {
        flex-direction: column;
        align-items: flex-start;
    }
    .cc-right {
        margin-left: 0;
    }
    .cc-stats {
        gap: 16px;
    }
    .summary-row {
        grid-template-columns: 1fr 1fr;
    }
    .prb-value {
        font-size: 15px;
    }
}
</style>

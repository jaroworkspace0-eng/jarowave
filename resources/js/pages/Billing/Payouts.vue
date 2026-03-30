<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';

// ── State ──
const summary = ref<any>(null);
const payouts = ref<any[]>([]);
const households = ref<any[]>([]);
const bankDetails = ref<any>(null);
const isLoading = ref(true);
const isSavingBank = ref(false);
const showBankModal = ref(false);
const flash = ref<{ msg: string; type: 'success' | 'error' } | null>(null);

const bankForm = ref({
    bank_name: '',
    account_holder: '',
    account_number: '',
    account_type: '',
    branch_code: '',
});

// ── Helpers ──
const getHeaders = () => ({
    headers: { Authorization: `Bearer ${localStorage.getItem('token')}` },
});

const showFlash = (msg: string, type: 'success' | 'error' = 'success') => {
    flash.value = { msg, type };
    setTimeout(() => (flash.value = null), 5000);
};

const formatDate = (d: string) =>
    d
        ? new Date(d).toLocaleDateString('en-ZA', {
              day: 'numeric',
              month: 'long',
              year: 'numeric',
          })
        : '—';

const formatRands = (val: number | string) =>
    val !== null && val !== undefined
        ? `R${Number(val).toLocaleString('en-ZA')}`
        : '—';

// ── Computed ──
const nextPayoutDate = computed(() => {
    const now = new Date();
    const next = new Date(now.getFullYear(), now.getMonth() + 1, 1);
    return next.toLocaleDateString('en-ZA', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
});

const activeHouseholds = computed(() =>
    households.value.filter((h) => h.status === 'active'),
);
const pendingHouseholds = computed(() =>
    households.value.filter((h) => h.status === 'pending'),
);
const failedHouseholds = computed(() =>
    households.value.filter((h) => h.status === 'failed'),
);

// ── Data Fetching ──
onMounted(async () => {
    try {
        const [summaryRes, payoutsRes, householdsRes, bankRes] =
            await Promise.all([
                axios.get(
                    `${import.meta.env.VITE_APP_URL}/api/earnings/summary`,
                    getHeaders(),
                ),
                axios.get(
                    `${import.meta.env.VITE_APP_URL}/api/payouts`,
                    getHeaders(),
                ),
                axios.get(
                    `${import.meta.env.VITE_APP_URL}/api/households`,
                    getHeaders(),
                ),
                axios.get(
                    `${import.meta.env.VITE_APP_URL}/api/bank-details`,
                    getHeaders(),
                ),
            ]);

        summary.value = summaryRes.data.summary;
        payouts.value = payoutsRes.data.payouts.data ?? [];
        households.value = householdsRes.data.households.data ?? [];
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
    } catch (err: any) {
        if (err.response?.status === 401) {
            router.visit('/login');
            return;
        }
        console.error('Failed to load payout data', err);
    } finally {
        isLoading.value = false;
    }
});

// ── Save Bank Details ──
const saveBankDetails = async () => {
    try {
        isSavingBank.value = true;
        const { data } = await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/bank-details`,
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

const payoutStatusColour = (status: string) => {
    if (status === 'paid') return 'green';
    if (status === 'pending') return 'orange';
    if (status === 'failed') return 'red';
    return 'gray';
};
</script>

<template>
    <Head title="Payouts" />
    <AppLayout>
        <div class="po-root">
            <!-- LOADING -->
            <div v-if="isLoading" class="po-loading">
                <div class="spinner"></div>
                <p>Loading payout data...</p>
            </div>

            <template v-else>
                <div class="po-header">
                    <div>
                        <h1 class="po-title">Payouts</h1>
                        <p class="po-sub">
                            Your earnings from active households, paid on the
                            1st of every month
                        </p>
                    </div>
                </div>

                <!-- FLASH -->
                <div v-if="flash" :class="['flash', flash.type]">
                    {{ flash.type === 'success' ? '✓' : '⚠' }} {{ flash.msg }}
                </div>

                <!-- PENDING PAYOUT BANNER -->
                <div v-if="summary?.pending_amount" class="payout-banner">
                    <div class="pb-left">
                        <div class="pb-label">Pending Payout</div>
                        <div class="pb-amount">
                            {{ formatRands(summary.pending_amount) }}
                        </div>
                        <div class="pb-date">
                            Pays out on {{ nextPayoutDate }}
                        </div>
                    </div>
                    <div class="pb-right">
                        <div class="pb-stat">
                            <div class="pbs-val">
                                {{ activeHouseholds.length }}
                            </div>
                            <div class="pbs-lbl">Active households</div>
                        </div>
                        <div class="pb-divider"></div>
                        <div class="pb-stat">
                            <div class="pbs-val">
                                {{ formatRands(summary.total_earned) }}
                            </div>
                            <div class="pbs-lbl">Total earned</div>
                        </div>
                        <div class="pb-divider"></div>
                        <div class="pb-stat">
                            <div class="pbs-val">
                                {{ formatRands(summary.paid_amount) }}
                            </div>
                            <div class="pbs-lbl">Total paid out</div>
                        </div>
                    </div>
                </div>

                <!-- NO PENDING -->
                <div v-else class="card card-flat">
                    <div class="no-pending">
                        <span class="np-icon">💸</span>
                        <div class="np-title">No pending payout</div>
                        <div class="np-desc">
                            Payouts are calculated on the last day of each month
                            and disbursed on the 1st. Onboard more households to
                            start earning.
                        </div>
                    </div>
                </div>

                <!-- SUMMARY STATS -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="sc-icon green">🏠</div>
                        <div class="sc-val">{{ activeHouseholds.length }}</div>
                        <div class="sc-lbl">Active Households</div>
                    </div>
                    <div class="stat-card">
                        <div class="sc-icon orange">⏳</div>
                        <div class="sc-val">{{ pendingHouseholds.length }}</div>
                        <div class="sc-lbl">Pending Payment</div>
                    </div>
                    <div class="stat-card">
                        <div class="sc-icon red">⚠️</div>
                        <div class="sc-val">{{ failedHouseholds.length }}</div>
                        <div class="sc-lbl">Failed Payment</div>
                    </div>
                    <div class="stat-card">
                        <div class="sc-icon blue">📅</div>
                        <div class="sc-val">{{ nextPayoutDate }}</div>
                        <div class="sc-lbl">Next Payout Date</div>
                    </div>
                </div>

                <!-- HOUSEHOLD BREAKDOWN -->
                <div class="card" v-if="households.length">
                    <div class="card-head">
                        <div class="card-title">Household Breakdown</div>
                        <div class="hh-legend">
                            <span class="leg-dot green"></span> Active
                            <span class="leg-dot orange"></span> Pending
                            <span class="leg-dot red"></span> Failed
                        </div>
                    </div>
                    <div class="hh-table-wrap">
                        <table class="hh-table">
                            <thead>
                                <tr>
                                    <th>Household</th>
                                    <th>Address</th>
                                    <th>Monthly Fee</th>
                                    <th>Your Share (65%)</th>
                                    <th>Joined</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="hh in households" :key="hh.id">
                                    <td class="hh-name">{{ hh.name }}</td>
                                    <td class="hh-addr">
                                        {{ hh.address ?? '—' }}
                                    </td>
                                    <td>R80</td>
                                    <td class="bold green-text">R52</td>
                                    <td class="date-col">
                                        {{ formatDate(hh.created_at) }}
                                    </td>
                                    <td>
                                        <span
                                            :class="['hh-status', hh.status]"
                                            >{{ hh.status }}</span
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-else class="card card-flat empty-state">
                    <div class="empty-icon">🏠</div>
                    <div class="empty-title">No households yet</div>
                    <div class="empty-desc">
                        Share your invite link with households in your area to
                        start earning.
                    </div>
                </div>

                <!-- PAYOUT HISTORY -->
                <div class="card" v-if="payouts.length">
                    <div class="card-title" style="margin-bottom: 16px">
                        Payout History
                    </div>
                    <table class="po-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Period</th>
                                <th>Households</th>
                                <th>Gross</th>
                                <th>Platform Fee</th>
                                <th>Your Payout</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in payouts" :key="p.id">
                                <td class="mono">{{ p.reference ?? '—' }}</td>
                                <td class="date-col">
                                    {{ formatDate(p.period_start) }}
                                    <span class="period-arrow">→</span>
                                    {{ formatDate(p.period_end) }}
                                </td>
                                <td>{{ p.household_count ?? '—' }}</td>
                                <td>{{ formatRands(p.gross_amount) }}</td>
                                <td class="red-text">
                                    {{ formatRands(p.platform_fee) }}
                                </td>
                                <td class="bold green-text">
                                    {{ formatRands(p.net_amount) }}
                                </td>
                                <td class="date-col">
                                    {{ formatDate(p.paid_at) }}
                                </td>
                                <td>
                                    <span
                                        :class="[
                                            'po-status',
                                            payoutStatusColour(p.status),
                                        ]"
                                        >{{ p.status }}</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else class="card card-flat empty-state">
                    <div class="empty-icon">📋</div>
                    <div class="empty-title">No payout history yet</div>
                    <div class="empty-desc">
                        Your first payout will appear here after the 1st of next
                        month.
                    </div>
                </div>

                <!-- BANK DETAILS -->
                <div class="card">
                    <div class="card-head">
                        <div class="card-title">Bank Details</div>
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
                        <div class="bank-item">
                            <div class="bank-lbl">Bank</div>
                            <div class="bank-val">
                                {{ bankDetails.bank_name }}
                            </div>
                        </div>
                        <div class="bank-item">
                            <div class="bank-lbl">Account Holder</div>
                            <div class="bank-val">
                                {{ bankDetails.account_holder }}
                            </div>
                        </div>
                        <div class="bank-item">
                            <div class="bank-lbl">Account Number</div>
                            <div class="bank-val mono">
                                {{ bankDetails.account_number }}
                            </div>
                        </div>
                        <div class="bank-item">
                            <div class="bank-lbl">Account Type</div>
                            <div class="bank-val">
                                {{ bankDetails.account_type }}
                            </div>
                        </div>
                        <div class="bank-item">
                            <div class="bank-lbl">Branch Code</div>
                            <div class="bank-val mono">
                                {{ bankDetails.branch_code }}
                            </div>
                        </div>
                    </div>

                    <div v-else class="bank-empty">
                        <span>⚠️</span>
                        <div>
                            <div class="be-title">No bank details on file</div>
                            <div class="be-desc">
                                Add your banking details to receive monthly
                                payouts. Without this, payouts will be held
                                until details are provided.
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- BANK MODAL -->
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

                    <div class="modal-field">
                        <label class="modal-label">Bank Name</label>
                        <select
                            class="modal-input"
                            v-model="bankForm.bank_name"
                        >
                            <option value="">Select bank</option>
                            <option>ABSA</option>
                            <option>Capitec</option>
                            <option>FNB</option>
                            <option>Nedbank</option>
                            <option>Standard Bank</option>
                            <option>TymeBank</option>
                            <option>African Bank</option>
                            <option>Investec</option>
                            <option>Discovery Bank</option>
                            <option>Other</option>
                        </select>
                    </div>

                    <div class="modal-field">
                        <label class="modal-label">Account Holder Name</label>
                        <input
                            class="modal-input"
                            type="text"
                            v-model="bankForm.account_holder"
                            placeholder="Full name as on account"
                        />
                    </div>

                    <div class="modal-row">
                        <div class="modal-field">
                            <label class="modal-label">Account Number</label>
                            <input
                                class="modal-input mono"
                                type="text"
                                v-model="bankForm.account_number"
                                placeholder="e.g. 1234567890"
                            />
                        </div>
                        <div class="modal-field">
                            <label class="modal-label">Branch Code</label>
                            <input
                                class="modal-input mono"
                                type="text"
                                v-model="bankForm.branch_code"
                                placeholder="e.g. 632005"
                            />
                        </div>
                    </div>

                    <div class="modal-field">
                        <label class="modal-label">Account Type</label>
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
.po-root {
    max-width: 1000px;
    margin: 0 auto;
    padding: 40px 24px;
    font-family: 'Segoe UI', sans-serif;
}
.po-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
    padding: 80px 0;
    color: #888;
}
.po-header {
    margin-bottom: 28px;
}
.po-title {
    font-size: 24px;
    font-weight: 800;
    color: #111;
    letter-spacing: -0.5px;
    margin: 0 0 4px;
}
.po-sub {
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

/* PENDING PAYOUT BANNER */
.payout-banner {
    background: linear-gradient(135deg, #1c2333, #243047);
    border-radius: 18px;
    padding: 28px 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}
.pb-left {
}
.pb-label {
    font-size: 11px;
    font-weight: 700;
    color: #f97316;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    margin-bottom: 8px;
}
.pb-amount {
    font-size: 48px;
    font-weight: 800;
    color: #fff;
    letter-spacing: -2px;
    line-height: 1;
    margin-bottom: 6px;
}
.pb-date {
    font-size: 13px;
    color: #4a5e7a;
}
.pb-right {
    display: flex;
    align-items: center;
    gap: 28px;
    flex-wrap: wrap;
}
.pb-stat {
    text-align: center;
}
.pbs-val {
    font-size: 20px;
    font-weight: 800;
    color: #fff;
    margin-bottom: 4px;
}
.pbs-lbl {
    font-size: 11px;
    color: #4a5e7a;
    font-weight: 500;
}
.pb-divider {
    width: 1px;
    height: 40px;
    background: #2d3d5a;
}

/* STATS GRID */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
.stat-card {
    background: #fff;
    border: 1.5px solid #e5e5e5;
    border-radius: 14px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.sc-icon {
    font-size: 20px;
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 4px;
}
.sc-icon.green {
    background: rgba(22, 163, 74, 0.08);
}
.sc-icon.orange {
    background: rgba(249, 115, 22, 0.08);
}
.sc-icon.red {
    background: rgba(220, 38, 38, 0.08);
}
.sc-icon.blue {
    background: rgba(37, 99, 235, 0.08);
}
.sc-val {
    font-size: 20px;
    font-weight: 800;
    color: #111;
    letter-spacing: -0.5px;
}
.sc-lbl {
    font-size: 11px;
    color: #999;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* CARDS */
.card {
    background: #fff;
    border: 1.5px solid #e5e5e5;
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 20px;
}
.card-flat {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 120px;
}
.card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
}
.card-title {
    font-size: 15px;
    font-weight: 800;
    color: #111;
}

/* HOUSEHOLD TABLE */
.hh-legend {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 12px;
    color: #888;
}
.leg-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 4px;
}
.leg-dot.green {
    background: #16a34a;
}
.leg-dot.orange {
    background: #f97316;
}
.leg-dot.red {
    background: #dc2626;
}
.hh-table-wrap {
    overflow-x: auto;
}
.hh-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.hh-table th {
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    color: #999;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 0 16px 10px 0;
    border-bottom: 1.5px solid #f0f0f0;
    white-space: nowrap;
}
.hh-table td {
    padding: 12px 16px 12px 0;
    color: #333;
    border-bottom: 1px solid #f9f9f9;
}
.hh-table tr:last-child td {
    border-bottom: none;
}
.hh-name {
    font-weight: 600;
    color: #111;
}
.hh-addr {
    font-size: 12px;
    color: #888;
    max-width: 200px;
}
.hh-status {
    font-size: 11px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 100px;
    text-transform: capitalize;
}
.hh-status.active {
    background: rgba(22, 163, 74, 0.1);
    color: #16a34a;
}
.hh-status.pending {
    background: rgba(249, 115, 22, 0.1);
    color: #f97316;
}
.hh-status.failed {
    background: rgba(220, 38, 38, 0.1);
    color: #dc2626;
}

/* PAYOUT HISTORY TABLE */
.po-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.po-table th {
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    color: #999;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 0 16px 10px 0;
    border-bottom: 1.5px solid #f0f0f0;
    white-space: nowrap;
}
.po-table td {
    padding: 12px 16px 12px 0;
    color: #333;
    border-bottom: 1px solid #f9f9f9;
}
.po-table tr:last-child td {
    border-bottom: none;
}
.po-status {
    font-size: 11px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 100px;
    text-transform: capitalize;
}
.po-status.green {
    background: rgba(22, 163, 74, 0.1);
    color: #16a34a;
}
.po-status.orange {
    background: rgba(249, 115, 22, 0.1);
    color: #f97316;
}
.po-status.red {
    background: rgba(220, 38, 38, 0.1);
    color: #dc2626;
}
.po-status.gray {
    background: #f5f5f5;
    color: #888;
}

.mono {
    font-family: monospace;
    font-size: 12px;
}
.bold {
    font-weight: 700;
}
.date-col {
    font-size: 12px;
    color: #666;
    white-space: nowrap;
}
.period-arrow {
    color: #ccc;
    margin: 0 4px;
}
.green-text {
    color: #16a34a;
}
.red-text {
    color: #dc2626;
}

/* BANK DETAILS */
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
    color: #999;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.bank-val {
    font-size: 14px;
    font-weight: 600;
    color: #111;
}
.bank-empty {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    background: rgba(249, 115, 22, 0.04);
    border: 1.5px solid rgba(249, 115, 22, 0.15);
    border-radius: 12px;
    padding: 16px;
    font-size: 22px;
}
.be-title {
    font-size: 14px;
    font-weight: 700;
    color: #111;
    margin-bottom: 4px;
}
.be-desc {
    font-size: 13px;
    color: #888;
    line-height: 1.5;
}

/* EMPTY STATE */
.no-pending {
    text-align: center;
    padding: 16px;
}
.np-icon {
    font-size: 36px;
    display: block;
    margin-bottom: 12px;
}
.np-title {
    font-size: 15px;
    font-weight: 700;
    color: #111;
    margin-bottom: 6px;
}
.np-desc {
    font-size: 13px;
    color: #888;
    max-width: 360px;
    margin: 0 auto;
    line-height: 1.6;
}
.empty-state {
    flex-direction: column;
    gap: 8px;
    padding: 48px 24px;
}
.empty-icon {
    font-size: 36px;
}
.empty-title {
    font-size: 15px;
    font-weight: 700;
    color: #111;
}
.empty-desc {
    font-size: 13px;
    color: #888;
    text-align: center;
    max-width: 360px;
}

/* MODAL */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.4);
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
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
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
    margin-bottom: 24px;
    line-height: 1.5;
}
.modal-field {
    margin-bottom: 16px;
}
.modal-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 16px;
}
.modal-label {
    font-size: 12px;
    font-weight: 700;
    color: #555;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: block;
    margin-bottom: 6px;
}
.modal-input {
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
.modal-input:focus {
    border-color: #f97316;
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
}
.modal-input.mono {
    font-family: monospace;
    letter-spacing: 1px;
}
.modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 24px;
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

/* BUTTONS */
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

.spinner {
    width: 32px;
    height: 32px;
    border: 3px solid #f0f0f0;
    border-top-color: #f97316;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
}
.btn-spinner {
    width: 16px;
    height: 16px;
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

@media (max-width: 768px) {
    .payout-banner {
        flex-direction: column;
    }
    .pb-amount {
        font-size: 36px;
    }
    .pb-right {
        justify-content: flex-start;
    }
    .stats-grid {
        grid-template-columns: 1fr 1fr;
    }
    .bank-grid {
        grid-template-columns: 1fr 1fr;
    }
    .modal-row {
        grid-template-columns: 1fr;
    }
    .type-toggle {
        flex-wrap: wrap;
    }
}

@media (max-width: 480px) {
    .stats-grid {
        grid-template-columns: 1fr 1fr;
    }
    .bank-grid {
        grid-template-columns: 1fr;
    }
    .pb-right {
        flex-direction: column;
        gap: 16px;
    }
    .pb-divider {
        display: none;
    }
}
</style>

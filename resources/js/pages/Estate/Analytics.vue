<script setup lang="ts">
import StatCard from '@/components/StatCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [];

const page = usePage();
const isAdmin = computed(
    () => (page.props.auth as any)?.user?.role === 'admin',
);

const data = ref<any>(null);
const loading = ref(false);
const flash = ref<{ msg: string; type: 'success' | 'error' } | null>(null);

const rangeOptions = [
    { value: 'all', label: 'All' },
    { value: 'this_month', label: 'This Month' },
    { value: 'last_30', label: 'Last 30 Days' },
    { value: 'last_month', label: 'Last Month' },
    { value: 'custom', label: 'Custom' },
] as const;
const range = ref<(typeof rangeOptions)[number]['value']>('this_month');

const today = new Date().toISOString().split('T')[0];
const customFrom = ref(today);
const customTo = ref(today);
const dateError = ref('');

// Admin-only manual channel override — estate_billing users are scoped
// server-side via channel_billing_contacts and never need this field.
const channelId = ref('');

function getHeaders() {
    return {
        headers: { Authorization: `Bearer ${localStorage.getItem('token')}` },
    };
}

function showFlash(msg: string, type: 'success' | 'error' = 'success') {
    flash.value = { msg, type };
    setTimeout(() => (flash.value = null), 5000);
}

function validateCustomRange(): boolean {
    if (range.value !== 'custom') {
        dateError.value = '';
        return true;
    }
    if (!customFrom.value || !customTo.value) {
        dateError.value = 'Both dates are required.';
        return false;
    }
    if (new Date(customTo.value) < new Date(customFrom.value)) {
        dateError.value = '"To" date must be after "From" date.';
        return false;
    }
    dateError.value = '';
    return true;
}

function dateRangeParams() {
    const now = new Date();

    if (range.value === 'all') {
        // No real lower bound on alert history — 2000-01-01 is just a
        // safely-early floor so the backend's whereBetween still works
        // without needing an "unbounded" special case server-side.
        return { from: '2000-01-01', to: now.toISOString().slice(0, 10) };
    }
    if (range.value === 'custom') {
        return { from: customFrom.value, to: customTo.value };
    }
    if (range.value === 'last_30') {
        const from = new Date(now);
        from.setDate(from.getDate() - 30);
        return {
            from: from.toISOString().slice(0, 10),
            to: now.toISOString().slice(0, 10),
        };
    }
    if (range.value === 'last_month') {
        const from = new Date(now.getFullYear(), now.getMonth() - 1, 1);
        const to = new Date(now.getFullYear(), now.getMonth(), 0);
        return {
            from: from.toISOString().slice(0, 10),
            to: to.toISOString().slice(0, 10),
        };
    }
    const from = new Date(now.getFullYear(), now.getMonth(), 1);
    return {
        from: from.toISOString().slice(0, 10),
        to: now.toISOString().slice(0, 10),
    };
}

async function load() {
    if (!validateCustomRange()) return;
    loading.value = true;
    try {
        const params: Record<string, string> = { ...dateRangeParams() };
        if (isAdmin.value && channelId.value)
            params.channel_id = channelId.value;

        const { data: res } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/estate/analytics`,
            { params, ...getHeaders() },
        );
        data.value = res;
    } catch {
        showFlash('Failed to load analytics.', 'error');
    } finally {
        loading.value = false;
    }
}

function setRange(v: (typeof rangeOptions)[number]['value']) {
    range.value = v;
    if (v !== 'custom') load();
}

function fmtDuration(v: number | null | undefined) {
    if (v === null || v === undefined) return '—';
    if (v < 60) return `${Math.round(v)} sec`;
    if (v < 3600) {
        const mins = Math.floor(v / 60);
        const secs = Math.round(v % 60);
        return secs > 0 ? `${mins} min ${secs} sec` : `${mins} min`;
    }
    const hrs = Math.floor(v / 3600);
    const mins = Math.round((v % 3600) / 60);
    return mins > 0 ? `${hrs} hr ${mins} min` : `${hrs} hr`;
}

function fmtPct(v: number | null | undefined) {
    return v === null || v === undefined ? '—' : `${v}%`;
}

onMounted(() => load());
</script>

<template>
    <Head title="Estate Analytics" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="page-root">
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Reports</div>
                    <h1 class="page-header__title">Estate Analytics</h1>
                    <p class="page-header__sub">
                        Incidents, patrol coverage, households, and tickets for
                        the selected period
                    </p>
                </div>
            </div>

            <div class="filter-card">
                <div class="filter-card__top">
                    <div class="filter-bar__chips">
                        <button
                            v-for="r in rangeOptions"
                            :key="r.value"
                            class="chip"
                            :class="{ 'chip--active': range === r.value }"
                            @click="setRange(r.value)"
                        >
                            {{ r.label }}
                        </button>
                    </div>

                    <div v-if="isAdmin" class="date-field">
                        <label class="date-field__label">Channel ID</label>
                        <input
                            v-model="channelId"
                            type="number"
                            class="field__input field__input--date"
                            placeholder="e.g. 17"
                            @change="load"
                        />
                    </div>

                    <button
                        class="btn-secondary btn-secondary--compact"
                        :disabled="loading"
                        @click="load()"
                    >
                        {{ loading ? 'Loading…' : 'Refresh' }}
                    </button>
                </div>

                <div v-if="range === 'custom'" class="date-range">
                    <div class="date-field">
                        <label class="date-field__label">From</label>
                        <input
                            v-model="customFrom"
                            type="date"
                            :max="customTo"
                            class="field__input field__input--date"
                        />
                    </div>
                    <div class="date-field">
                        <label class="date-field__label">To</label>
                        <input
                            v-model="customTo"
                            type="date"
                            :min="customFrom"
                            :max="today"
                            class="field__input field__input--date"
                        />
                    </div>
                    <button
                        class="btn-secondary btn-secondary--compact"
                        :disabled="loading"
                        @click="load()"
                    >
                        Apply
                    </button>
                </div>
                <p v-if="dateError" class="date-error">{{ dateError }}</p>
            </div>

            <div v-if="loading" class="table-card">
                <div class="empty-state">
                    <span class="text-sm text-slate-400"
                        >Loading analytics…</span
                    >
                </div>
            </div>

            <template v-else-if="data">
                <div class="stat-grid">
                    <StatCard label="Incidents" :value="data.incidents.total" />
                    <StatCard
                        label="Avg Response"
                        :value="
                            fmtDuration(data.incidents.avg_response_seconds)
                        "
                        accent
                    />
                    <StatCard
                        label="Resolved"
                        :value="`${data.incidents.resolved}/${data.incidents.total}`"
                    />
                    <StatCard
                        label="Unresolved"
                        :value="data.incidents.unresolved"
                        :accent="data.incidents.unresolved > 0"
                    />
                    <StatCard
                        label="Patrol Coverage"
                        :value="fmtPct(data.patrol.coverage_pct)"
                        accent
                    />
                </div>

                <!-- Cancellations — prioritized above the regular panel grid.
                     resolved_at being set does NOT mean a guard actually
                     responded; a household can self-cancel, and a duress
                     cancellation in particular needs to stay visible on its
                     own, never folded silently into "Resolved". -->
                <div
                    class="cancel-panel"
                    :class="{
                        'cancel-panel--alert':
                            data.incidents.cancellations.duress > 0,
                    }"
                >
                    <div class="cancel-panel__header">
                        <span class="field__label">Alert Cancellations</span>
                        <span
                            v-if="data.incidents.cancellations.duress > 0"
                            class="type-badge bg-red-50 text-red-600"
                        >
                            {{ data.incidents.cancellations.duress }} Duress
                        </span>
                    </div>
                    <div class="detail-grid detail-grid--pad">
                        <div>
                            <div class="field__label">Safe Cancel</div>
                            <div class="detail-grid__value">
                                {{ data.incidents.cancellations.safe_cancel }}
                            </div>
                        </div>
                        <div>
                            <div class="field__label">Duress</div>
                            <div
                                class="detail-grid__value detail-grid__value--danger"
                            >
                                {{ data.incidents.cancellations.duress }}
                            </div>
                        </div>
                        <div>
                            <div class="field__label">Total Cancelled</div>
                            <div class="detail-grid__value">
                                {{ data.incidents.cancellations.total }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="panel-grid">
                    <div class="review-info-panel">
                        <div class="field__label">Incidents by Trigger</div>
                        <div class="detail-grid detail-grid--pad">
                            <div>
                                <div class="field__label">Manual</div>
                                <div class="detail-grid__value">
                                    {{
                                        data.incidents.by_trigger_source.manual
                                    }}
                                </div>
                            </div>
                            <div>
                                <div class="field__label">Auto-detected</div>
                                <div class="detail-grid__value">
                                    {{
                                        data.incidents.by_trigger_source
                                            .auto_detected
                                    }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="review-info-panel">
                        <div class="field__label">Households</div>
                        <div class="detail-grid detail-grid--pad">
                            <div>
                                <div class="field__label">Active</div>
                                <div class="detail-grid__value">
                                    {{ data.households.active_households }}
                                </div>
                            </div>
                            <div>
                                <div class="field__label">Linked Accounts</div>
                                <div class="detail-grid__value">
                                    {{ data.households.linked_accounts }}
                                </div>
                            </div>
                            <div>
                                <div class="field__label">New This Period</div>
                                <div class="detail-grid__value">
                                    {{ data.households.new_this_period }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="review-info-panel">
                        <div class="field__label">Patrol</div>
                        <div class="detail-grid detail-grid--pad">
                            <div>
                                <div class="field__label">Checkpoints</div>
                                <div class="detail-grid__value">
                                    {{ data.patrol.checkpoint_count }}
                                </div>
                            </div>
                            <div>
                                <div class="field__label">Scans</div>
                                <div class="detail-grid__value">
                                    {{ data.patrol.scan_count }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="review-info-panel">
                        <div class="field__label">Tickets</div>
                        <div class="detail-grid detail-grid--pad">
                            <div>
                                <div class="field__label">Open</div>
                                <div class="detail-grid__value">
                                    {{ data.tickets.open }}
                                </div>
                            </div>
                            <div>
                                <div class="field__label">Resolved</div>
                                <div class="detail-grid__value">
                                    {{ data.tickets.resolved }}
                                </div>
                            </div>
                            <div>
                                <div class="field__label">Avg Resolution</div>
                                <div class="detail-grid__value">
                                    {{
                                        data.tickets.avg_resolution_hours ??
                                        '—'
                                    }}h
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <div v-else class="table-card">
                <div class="empty-state">
                    <p class="empty-state__title">No data</p>
                    <p class="empty-state__sub">
                        Try a different period or channel
                    </p>
                </div>
            </div>
        </div>

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
    --c-primary: #ea580c;
    --c-primary-h: #c2410c;
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
    gap: 16px;
    flex-wrap: wrap;
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
.date-error {
    font-size: 12px;
    color: #dc2626;
    margin: 0;
}
.field__input {
    box-sizing: border-box;
    background: #f8fafc;
    border: 1.5px solid #e4e8ef;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 14px;
    font-family: inherit;
    color: #1a2332;
    outline: none;
}
.field__input:focus {
    border-color: #ea580c;
    background: #fff;
}
.field__input--date {
    background: #fff;
    padding: 8px 12px;
    font-size: 13px;
    width: 120px;
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
    margin-left: auto;
}
.btn-secondary:hover:not(:disabled) {
    border-color: #ea580c;
    color: #ea580c;
    background: #fff7ed;
}
.btn-secondary--compact {
    padding: 8px 14px;
    font-size: 12px;
}
.btn-secondary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.stat-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 14px;
}
.panel-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 14px;
}

.cancel-panel {
    background: #f8fafc;
    border: 1.5px solid #e4e8ef;
    border-radius: 10px;
    padding: 14px 16px;
}
.cancel-panel--alert {
    background: #fef2f2;
    border-color: #fecaca;
}
.cancel-panel__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
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

.review-info-panel {
    background: #f8fafc;
    border: 1.5px solid #e4e8ef;
    border-radius: 10px;
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
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
.detail-grid__value--danger {
    color: #dc2626;
}

.field__label {
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    letter-spacing: 0.3px;
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
    background: #f97316;
}

.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.22s ease;
}
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}

@media (max-width: 1100px) {
    .stat-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}
@media (max-width: 900px) {
    .stat-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .panel-grid {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 640px) {
    .page-root {
        padding: 16px;
    }
}
</style>

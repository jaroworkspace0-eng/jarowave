<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import {
    AlertTriangle,
    Clock,
    Eye,
    Flag,
    ShieldOff,
    User as UserIcon,
    X,
} from 'lucide-vue-next';
import { onMounted, ref } from 'vue';

const props = defineProps<{
    allowedRole: string;
    canClear: boolean;
}>();

const stored = localStorage.getItem('user');
const user = stored ? JSON.parse(stored) : null;

onMounted(() => {
    const allowed =
        props.allowedRole === 'gate_guard'
            ? !!user?.is_gate_guard
            : user?.role === props.allowedRole;

    if (!allowed) router.visit('/dashboard');
    load();
});

const households = ref<any[]>([]);
const loading = ref(true);
const flash = ref<{ msg: string; type: 'success' | 'error' } | null>(null);

const authHeaders = () => ({
    Authorization: `Bearer ${localStorage.getItem('token')}`,
});

function showFlash(msg: string, type: 'success' | 'error' = 'success') {
    flash.value = { msg, type };
    setTimeout(() => (flash.value = null), 3500);
}

async function load() {
    loading.value = true;
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/flagged-households`,
            { headers: authHeaders() },
        );
        households.value = data.data;
    } catch (e) {
        console.error(e);
        showFlash('Failed to load flagged households.', 'error');
    } finally {
        loading.value = false;
    }
}

function fmtDateTime(ts: string | null | undefined) {
    if (!ts) return '—';
    return new Date(ts).toLocaleString('en-ZA', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function alertStatusLabel(a: any) {
    if (a.cancel_pin_used === 'safe_cancel') return 'Cancelled';
    if (a.is_resolved) return 'Resolved';
    return 'Active';
}
function alertStatusClass(a: any) {
    if (a.cancel_pin_used === 'safe_cancel')
        return 'alert-timeline__status--cancelled';
    if (a.is_resolved) return 'alert-timeline__status--resolved';
    return 'alert-timeline__status--active';
}
function alertIconClass(a: any) {
    if (a.cancel_pin_used === 'safe_cancel')
        return 'alert-timeline__icon--cancelled';
    if (a.is_resolved) return 'alert-timeline__icon--resolved';
    return '';
}

// ══════════ Clear-flag modal (with note) ══════════
const clearTarget = ref<any>(null);
const clearNote = ref('');
const clearing = ref(false);

function openClearModal(household: any) {
    clearTarget.value = household;
    clearNote.value = '';
}
function closeClearModal() {
    clearTarget.value = null;
    clearNote.value = '';
}

async function confirmClear() {
    if (!clearTarget.value) return;
    clearing.value = true;
    try {
        await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/users/${clearTarget.value.id}/clear-alert-flag`,
            { note: clearNote.value || null },
            { headers: authHeaders() },
        );
        households.value = households.value.filter(
            (h) => h.id !== clearTarget.value.id,
        );
        showFlash('Flag cleared.');
        closeClearModal();
    } catch (e) {
        console.error(e);
        showFlash('Failed to clear flag.', 'error');
    } finally {
        clearing.value = false;
    }
}

// ══════════ Detail modal (recent alerts + flag/clear event log) ══════════
const detailTarget = ref<any>(null);
const detailEvents = ref<any[]>([]);
const detailAlerts = ref<any[]>([]);
const detailLoading = ref(false);

async function openDetail(household: any) {
    detailTarget.value = household;
    detailEvents.value = [];
    detailAlerts.value = [];
    detailLoading.value = true;
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/users/${household.id}/alert-flag-history`,
            { headers: authHeaders() },
        );
        detailEvents.value = data.data ?? [];
        detailAlerts.value = data.recent_alerts ?? [];
    } catch (e) {
        console.error(e);
        showFlash('Failed to load history.', 'error');
    } finally {
        detailLoading.value = false;
    }
}
function closeDetail() {
    detailTarget.value = null;
    detailEvents.value = [];
    detailAlerts.value = [];
}

function actorLabel(event: any) {
    if (event.event_type === 'flagged') return 'System (auto)';
    if (event.actor?.name) return `${event.actor.name} (${event.actor_role})`;
    return event.actor_role ?? 'Unknown';
}

function alertTypeLabel(type: string) {
    return type === 'domestic_violence' ? 'DV' : 'SOS';
}
</script>

<template>
    <Head title="Flagged Households" />
    <AppLayout>
        <div class="page-root">
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Safety</div>
                    <h1 class="page-header__title">Flagged Households</h1>
                    <p class="page-header__sub">
                        Households flagged for elevated emergency-alert activity
                    </p>
                </div>
                <button class="btn-secondary" @click="load()">↻ Refresh</button>
            </div>

            <div class="table-card">
                <div v-if="loading" class="empty-state">
                    <span class="text-sm text-slate-400">Loading…</span>
                </div>
                <div v-else-if="households.length === 0" class="empty-state">
                    <ShieldOff :size="28" class="empty-state__icon" />
                    <p class="empty-state__title">No flagged households</p>
                    <p class="empty-state__sub">
                        Nothing currently needs review
                    </p>
                </div>
                <div v-else class="table-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Household</th>
                                <th>Unit</th>
                                <th>Phone</th>
                                <th>Estate</th>
                                <th>Last Alert</th>
                                <th>Flagged At</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="h in households"
                                :key="h.id"
                                class="clickable-row"
                                @click="openDetail(h)"
                            >
                                <td>
                                    <div class="reporter-cell__name">
                                        {{ h.name }}
                                    </div>
                                    <div class="reporter-cell__sub">
                                        {{ h.email }}
                                    </div>
                                </td>
                                <td class="td-time">
                                    {{ h.unit_number || '—' }}
                                </td>
                                <td class="td-time">{{ h.phone || '—' }}</td>
                                <td class="td-time">
                                    {{ h.complex_name || '—' }}
                                </td>
                                <td class="td-time">
                                    {{
                                        h.last_alert_at
                                            ? fmtDateTime(h.last_alert_at)
                                            : '—'
                                    }}
                                </td>
                                <td class="td-time">
                                    {{ fmtDateTime(h.alert_flagged_at) }}
                                </td>
                                <td @click.stop>
                                    <div class="row-actions">
                                        <button
                                            class="row-action-btn"
                                            @click="openDetail(h)"
                                        >
                                            <Eye :size="12" />
                                            View
                                        </button>
                                        <button
                                            v-if="canClear"
                                            class="row-action-btn row-action-btn--primary"
                                            @click="openClearModal(h)"
                                        >
                                            <Flag :size="12" />
                                            Clear Flag
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- DETAIL MODAL — recent alerts + flag history, scrollable -->
        <Teleport to="body">
            <transition name="modal">
                <div
                    v-if="detailTarget"
                    class="modal-backdrop"
                    @click.self="closeDetail"
                >
                    <div class="modal-sheet modal-sheet--wide">
                        <div class="modal-sheet__header">
                            <div>
                                <h3 class="confirm-title">
                                    {{ detailTarget.name }}
                                </h3>
                                <p class="confirm-sub">
                                    <span v-if="detailTarget.unit_number"
                                        >Unit {{ detailTarget.unit_number }} ·
                                    </span>
                                    Flagged
                                    {{
                                        fmtDateTime(
                                            detailTarget.alert_flagged_at,
                                        )
                                    }}
                                </p>
                            </div>
                            <div class="modal-sheet__header-actions">
                                <button
                                    v-if="canClear"
                                    class="row-action-btn row-action-btn--primary"
                                    @click="openClearModal(detailTarget)"
                                >
                                    <Flag :size="12" />
                                    Clear Flag
                                </button>
                                <button class="close-btn" @click="closeDetail">
                                    <X :size="16" />
                                </button>
                            </div>
                        </div>

                        <div class="modal-sheet__body">
                            <div v-if="detailLoading" class="history-loading">
                                Loading…
                            </div>

                            <template v-else>
                                <div
                                    class="field__label"
                                    style="margin-bottom: 8px"
                                >
                                    Recent Alerts (last 30 days)
                                </div>
                                <div
                                    v-if="detailAlerts.length"
                                    class="alert-timeline"
                                >
                                    <div
                                        v-for="a in detailAlerts"
                                        :key="a.id"
                                        class="alert-timeline__row"
                                    >
                                        <AlertTriangle
                                            :size="12"
                                            class="alert-timeline__icon"
                                            :class="alertIconClass(a)"
                                        />
                                        <span class="alert-timeline__type">{{
                                            alertTypeLabel(a.alert_type)
                                        }}</span>
                                        <span class="alert-timeline__time">{{
                                            fmtDateTime(a.created_at)
                                        }}</span>
                                        <span
                                            class="alert-timeline__status"
                                            :class="alertStatusClass(a)"
                                        >
                                            {{ alertStatusLabel(a) }}
                                        </span>
                                    </div>
                                </div>
                                <div
                                    v-else
                                    class="history-empty"
                                    style="margin-bottom: 8px"
                                >
                                    No alerts in the last 30 days.
                                </div>

                                <div
                                    class="field__label"
                                    style="margin: 20px 0 8px"
                                >
                                    Flag History
                                </div>
                                <div
                                    v-if="detailEvents.length"
                                    class="history-list"
                                >
                                    <div
                                        v-for="event in detailEvents"
                                        :key="event.id"
                                        class="history-item"
                                    >
                                        <span
                                            class="history-item__badge"
                                            :class="
                                                event.event_type === 'flagged'
                                                    ? 'history-item__badge--flagged'
                                                    : 'history-item__badge--cleared'
                                            "
                                        >
                                            {{
                                                event.event_type === 'flagged'
                                                    ? 'Flagged'
                                                    : 'Cleared'
                                            }}
                                        </span>
                                        <div class="history-item__body">
                                            <div class="history-item__meta">
                                                <span
                                                    class="history-item__actor"
                                                >
                                                    <UserIcon :size="11" />
                                                    {{ actorLabel(event) }}
                                                </span>
                                                <span
                                                    class="history-item__time"
                                                >
                                                    <Clock :size="11" />
                                                    {{
                                                        fmtDateTime(
                                                            event.created_at,
                                                        )
                                                    }}
                                                </span>
                                                <span
                                                    v-if="
                                                        event.alert_count_at_event
                                                    "
                                                    class="history-item__count"
                                                >
                                                    {{
                                                        event.alert_count_at_event
                                                    }}
                                                    alerts
                                                </span>
                                            </div>
                                            <div
                                                v-if="event.note"
                                                class="history-item__note"
                                            >
                                                "{{ event.note }}"
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="history-empty">
                                    No flag events recorded.
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>

        <!-- CLEAR FLAG MODAL (with note) -->
        <Teleport to="body">
            <transition name="modal">
                <div
                    v-if="clearTarget"
                    class="modal-backdrop"
                    @click.self="closeClearModal"
                >
                    <div class="modal-sheet modal-sheet--sm">
                        <div
                            class="modal-sheet__header modal-sheet__header--plain"
                        >
                            <div>
                                <h3 class="confirm-title">Clear Alert Flag</h3>
                                <p class="confirm-sub">
                                    {{ clearTarget.name }}
                                    <span v-if="clearTarget.unit_number"
                                        >· Unit
                                        {{ clearTarget.unit_number }}</span
                                    >
                                </p>
                            </div>
                            <button class="close-btn" @click="closeClearModal">
                                <X :size="16" />
                            </button>
                        </div>

                        <div class="modal-body-padded">
                            <div class="confirm-note confirm-note--info">
                                This clears the flag and starts a fresh 30-day
                                window for this household. It does not affect
                                any alerts already sent.
                            </div>

                            <label class="field__label" for="clear-note">
                                Reason (optional, visible in history)
                            </label>
                            <textarea
                                id="clear-note"
                                v-model="clearNote"
                                class="note-textarea"
                                rows="3"
                                maxlength="500"
                                placeholder="e.g. Called the household, alerts were legitimate — elderly resident, frequent falls."
                            ></textarea>

                            <div class="confirm-actions">
                                <button
                                    class="btn-secondary"
                                    @click="closeClearModal"
                                >
                                    Cancel
                                </button>
                                <button
                                    class="btn-primary btn-primary--success"
                                    :disabled="clearing"
                                    @click="confirmClear"
                                >
                                    {{ clearing ? 'Clearing…' : 'Clear Flag' }}
                                </button>
                            </div>
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

.btn-primary {
    flex: 1;
    border: none;
    border-radius: 12px;
    padding: 12px 18px;
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
.btn-primary:disabled {
    opacity: 0.5;
    cursor: default;
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
.empty-state__icon {
    color: #cbd5e1;
    margin-bottom: 4px;
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
    min-width: 880px;
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

.row-actions {
    display: flex;
    gap: 6px;
}
.row-action-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 12px;
    border-radius: 8px;
    border: 1.5px solid #e4e8ef;
    background: #fff;
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    cursor: pointer;
    font-family: inherit;
    white-space: nowrap;
}
.row-action-btn:hover {
    border-color: #ea580c;
    color: #ea580c;
    background: #fff7ed;
}
.row-action-btn--primary {
    border-color: #fed7aa;
    color: #ea580c;
    background: #fff7ed;
}
.row-action-btn--primary:hover {
    background: #ffedd5;
}

.field__label {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    letter-spacing: 0.4px;
    text-transform: uppercase;
}
.history-loading,
.history-empty {
    font-size: 12px;
    color: #94a3b8;
}

/* ── Alert timeline ── */
.alert-timeline {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.alert-timeline__row {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 12.5px;
    padding: 8px 10px;
    background: #f8fafc;
    border: 1px solid #e4e8ef;
    border-radius: 8px;
}
.alert-timeline__icon {
    color: #dc2626;
    flex-shrink: 0;
}
.alert-timeline__icon--resolved {
    color: #059669;
}
.alert-timeline__icon--cancelled {
    color: #94a3b8;
}
.alert-timeline__type {
    font-weight: 700;
    color: #1a2332;
    width: 32px;
    flex-shrink: 0;
}
.alert-timeline__time {
    color: #64748b;
    flex: 1;
}
.alert-timeline__status {
    font-size: 10.5px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 20px;
    flex-shrink: 0;
}
.alert-timeline__status--active {
    background: #fef2f2;
    color: #dc2626;
}
.alert-timeline__status--resolved {
    background: #ecfdf5;
    color: #059669;
}
.alert-timeline__status--cancelled {
    background: #f1f5f9;
    color: #64748b;
}

/* ── Flag/clear event log ── */
.history-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.history-item {
    display: flex;
    gap: 10px;
    align-items: flex-start;
}
.history-item__badge {
    flex-shrink: 0;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 10.5px;
    font-weight: 700;
    white-space: nowrap;
    margin-top: 1px;
}
.history-item__badge--flagged {
    background: #fef2f2;
    color: #dc2626;
}
.history-item__badge--cleared {
    background: #ecfdf5;
    color: #059669;
}
.history-item__body {
    flex: 1;
    min-width: 0;
}
.history-item__meta {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
.history-item__actor,
.history-item__time {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11.5px;
    color: #64748b;
    font-weight: 600;
}
.history-item__count {
    font-size: 11px;
    color: #94a3b8;
}
.history-item__note {
    margin-top: 4px;
    font-size: 12.5px;
    color: #475569;
    font-style: italic;
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
    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.18);
    border: 1px solid #e4e8ef;
}
.modal-sheet--sm {
    max-width: 440px;
}
.modal-sheet--wide {
    max-width: 640px;
    max-height: 82vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.modal-sheet__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    padding: 22px 24px;
    border-bottom: 1px solid #e4e8ef;
    flex-shrink: 0;
}
.modal-sheet__header-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}
.modal-sheet__header--plain {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    padding: 22px 24px 0;
}
.modal-sheet__body {
    padding: 20px 24px 24px;
    overflow-y: auto;
    flex: 1;
}
.modal-body-padded {
    padding: 16px 24px 24px;
    display: flex;
    flex-direction: column;
    gap: 12px;
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
.confirm-note {
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 12.5px;
    line-height: 1.5;
}
.confirm-note--info {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1d4ed8;
}
.note-textarea {
    width: 100%;
    border: 1.5px solid #e4e8ef;
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 13px;
    font-family: inherit;
    color: #1a2332;
    resize: vertical;
    outline: none;
}
.note-textarea:focus {
    border-color: #ea580c;
}
.confirm-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 4px;
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

.table-scroll {
    overflow-x: auto;
}

@media (max-width: 640px) {
    .page-root {
        padding: 16px;
    }
    .data-table {
        min-width: 700px;
    }
    .modal-sheet--wide {
        max-height: 90vh;
    }
}
</style>

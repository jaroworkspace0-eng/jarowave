<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { Flag, ShieldOff } from 'lucide-vue-next';
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
const clearingId = ref<any>(null);
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

async function clearFlag(id: any) {
    clearingId.value = id;
    try {
        await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/users/${id}/clear-alert-flag`,
            {},
            { headers: authHeaders() },
        );
        households.value = households.value.filter((h) => h.id !== id);
        showFlash('Flag cleared.');
    } catch (e) {
        console.error(e);
        showFlash('Failed to clear flag.', 'error');
    } finally {
        clearingId.value = null;
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
                                <th>Flagged At</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="h in households" :key="h.id">
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
                                    {{ fmtDateTime(h.alert_flagged_at) }}
                                </td>
                                <td>
                                    <button
                                        v-if="canClear"
                                        class="row-action-btn"
                                        :disabled="clearingId === h.id"
                                        @click="clearFlag(h.id)"
                                    >
                                        <Flag :size="12" />
                                        {{
                                            clearingId === h.id
                                                ? 'Clearing…'
                                                : 'Clear Flag'
                                        }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
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
.page-root {
    --c-primary: #ea580c;
    --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
    --shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.14);
    font-family: 'DM Sans', system-ui, sans-serif;
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
    min-width: 800px;
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
.row-action-btn:hover:not(:disabled) {
    border-color: #ea580c;
    color: #ea580c;
    background: #fff7ed;
}
.row-action-btn:disabled {
    opacity: 0.5;
    cursor: default;
}
.table-scroll {
    overflow-x: auto;
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
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
@media (max-width: 640px) {
    .page-root {
        padding: 16px;
    }
}
</style>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import QRCode from 'qrcode';
import { computed, nextTick, onMounted, ref } from 'vue';
const auth = useAuthStore();

// ── Get client ID from URL ─────────────────────────────────────────────────
const clientId = window.location.pathname.split('/')[2];

// ── State ──────────────────────────────────────────────────────────────────
const client = ref<any>(null);
const checkpoints = ref<any[]>([]);
const isLoading = ref(false);
const isProcessing = ref(false);
const flashMessage = ref<string | null>(null);
const errors = ref<Record<string, string[]>>({});

// Modal state
const showCreateModal = ref(false);
const showScansModal = ref(false);
const showQrModal = ref(false);
const isEditing = ref(false);
const selectedCheckpoint = ref<any>(null);
const scans = ref<any>(null);
const scansLoading = ref(false);

const qrDataUrl = ref<string | null>(null);

async function openQr(cp: any) {
    selectedCheckpoint.value = cp;
    qrDataUrl.value = null;
    showQrModal.value = true;
    await nextTick();
    qrDataUrl.value = await QRCode.toDataURL(cp.token, {
        width: 260,
        margin: 2,
        color: { dark: '#111111', light: '#ffffff' },
    });
}

function printQr(cp: any) {
    const win = window.open('', '_blank');
    if (!win || !qrDataUrl.value) return;
    win.document.write(`
        <html><head><title>Checkpoint QR - ${cp.name}</title>
        <style>
            body { font-family: sans-serif; display:flex; flex-direction:column; align-items:center; padding:40px; }
            .card { border:2px solid #333; border-radius:12px; padding:32px; text-align:center; max-width:320px; }
            img { width:220px; height:220px; }
            h2 { margin:16px 0 4px; font-size:18px; }
            p { margin:2px 0; color:#555; font-size:13px; }
            .token { margin-top:12px; font-family:monospace; font-size:12px; color:#888; }
        </style></head><body>
        <div class="card">
            <img src="${qrDataUrl.value}" />
            <h2>${cp.name}</h2>
            <p>${client.value?.user?.name ?? ''}</p>
            <p class="token">Token: ${cp.token}</p>
        </div>
        </body></html>
    `);
    win.document.close();
    win.focus();
    setTimeout(() => win.print(), 500);
}

async function toggleActive(cp: any) {
    try {
        await axios.patch(
            `${import.meta.env.VITE_APP_URL}/api/clients/${clientId}/checkpoints/${cp.id}`,
            { is_active: !cp.is_active },
            { headers: apiHeaders() },
        );
        showMsg(
            cp.is_active ? 'Checkpoint deactivated.' : 'Checkpoint activated.',
        );
        await load();
    } catch (e) {
        console.error('Toggle failed', e);
    }
}

const form = ref({
    id: null as number | null,
    name: '',
    description: '',
});

// ── Auth guard ─────────────────────────────────────────────────────────────
onMounted(() => {
    if (auth.user?.role !== 'admin') {
        router.visit('/dashboard');
    }
    load();
});

// ── API helpers ────────────────────────────────────────────────────────────
const apiHeaders = () => ({
    Authorization: `Bearer ${localStorage.getItem('token')}`,
});

function showMsg(msg: string) {
    flashMessage.value = msg;
    setTimeout(() => (flashMessage.value = null), 3500);
}

// ── Load checkpoints ───────────────────────────────────────────────────────
async function load() {
    isLoading.value = true;
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/clients/${clientId}/checkpoints`,
            { headers: apiHeaders() },
        );
        client.value = data.client;
        checkpoints.value = data.checkpoints;
    } catch (e) {
        console.error('Failed to load checkpoints', e);
    } finally {
        isLoading.value = false;
    }
}

// ── Create / Edit modal ────────────────────────────────────────────────────
function openCreate() {
    isEditing.value = false;
    form.value = { id: null, name: '', description: '' };
    errors.value = {};
    showCreateModal.value = true;
}

function openEdit(cp: any) {
    isEditing.value = true;
    form.value = {
        id: cp.id,
        name: cp.name,
        description: cp.description ?? '',
    };
    errors.value = {};
    showCreateModal.value = true;
}

function closeCreateModal() {
    showCreateModal.value = false;
}

async function handleSubmit() {
    isProcessing.value = true;
    errors.value = {};
    try {
        let response;
        if (isEditing.value && form.value.id) {
            response = await axios.patch(
                `${import.meta.env.VITE_APP_URL}/api/clients/${clientId}/checkpoints/${form.value.id}`,
                form.value,
                { headers: apiHeaders() },
            );
        } else {
            response = await axios.post(
                `${import.meta.env.VITE_APP_URL}/api/clients/${clientId}/checkpoints`,
                form.value,
                { headers: apiHeaders() },
            );
        }
        showMsg(response.data.message);
        closeCreateModal();
        await load();
    } catch (e: any) {
        errors.value = e.response?.data?.errors ?? {};
    } finally {
        isProcessing.value = false;
    }
}

// ── Delete ─────────────────────────────────────────────────────────────────
const confirmDeleteTarget = ref<any>(null);

function confirmDelete(cp: any) {
    confirmDeleteTarget.value = cp;
}

async function proceedDelete() {
    if (!confirmDeleteTarget.value) return;
    isProcessing.value = true;
    try {
        const response = await axios.delete(
            `${import.meta.env.VITE_APP_URL}/api/clients/${clientId}/checkpoints/${confirmDeleteTarget.value.id}`,
            { headers: apiHeaders() },
        );
        showMsg(response.data.message);
        confirmDeleteTarget.value = null;
        await load();
    } catch (e) {
        console.error('Delete failed', e);
    } finally {
        isProcessing.value = false;
    }
}

// ── Scan logs modal ────────────────────────────────────────────────────────
async function openScans(cp: any) {
    selectedCheckpoint.value = cp;
    showScansModal.value = true;
    scansLoading.value = true;
    scans.value = null;
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/clients/${clientId}/checkpoints/${cp.id}/scans`,
            { headers: apiHeaders() },
        );
        scans.value = data.scans;
    } catch (e) {
        console.error('Failed to load scans', e);
    } finally {
        scansLoading.value = false;
    }
}

async function loadScansPage(url: string) {
    scansLoading.value = true;
    try {
        const { data } = await axios.get(url, { headers: apiHeaders() });
        scans.value = data.scans;
    } finally {
        scansLoading.value = false;
    }
}

function formatDate(date: string) {
    return new Date(date).toLocaleString('en-ZA', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

// ── Stats ──────────────────────────────────────────────────────────────────
const activeCount = computed(
    () => checkpoints.value.filter((c) => c.is_active).length,
);
const inactiveCount = computed(
    () => checkpoints.value.filter((c) => !c.is_active).length,
);
const totalScans = computed(() =>
    checkpoints.value.reduce((sum, c) => sum + (c.scans_count || 0), 0),
);
</script>

<template>
    <Head title="Checkpoints" />

    <AppLayout>
        <div class="page-root">
            <!-- PAGE HEADER -->
            <div class="page-header">
                <div class="page-header__left">
                    <button class="back-link" @click="router.visit('/clients')">
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-3.5 w-3.5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="2.5"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M15 19l-7-7 7-7"
                            />
                        </svg>
                        Back to Clients
                    </button>
                    <div class="page-header__eyebrow">Guard Patrol</div>
                    <h1 class="page-header__title">
                        {{ client?.user?.name ?? 'Loading…' }} — Checkpoints
                    </h1>
                </div>
                <div class="page-header__right">
                    <button class="btn-primary" @click="openCreate">
                        Add Checkpoint
                    </button>
                </div>
            </div>

            <!-- STAT CARDS -->
            <div class="stat-row">
                <div class="stat-card">
                    <div class="stat-card__label">Total Checkpoints</div>
                    <div class="stat-card__value">
                        {{ checkpoints.length }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Active</div>
                    <div class="stat-card__value stat-card__value--green">
                        {{ activeCount }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Inactive</div>
                    <div class="stat-card__value stat-card__value--red">
                        {{ inactiveCount }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Total Scans</div>
                    <div class="stat-card__value stat-card__value--orange">
                        {{ totalScans }}
                    </div>
                </div>
            </div>

            <!-- TABLE CARD -->
            <div class="table-card">
                <!-- Loading -->
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
                        >Loading checkpoints…</span
                    >
                </div>

                <!-- Empty -->
                <div v-else-if="checkpoints.length === 0" class="empty-state">
                    <div class="empty-state__icon">
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-8 w-8"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="1.2"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"
                            />
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"
                            />
                        </svg>
                    </div>
                    <p class="empty-state__title">No checkpoints yet</p>
                    <p class="empty-state__sub">
                        Add your first checkpoint to get started
                    </p>
                </div>

                <!-- Table -->
                <table v-else class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Token</th>
                            <th>Description</th>
                            <th>Total Scans</th>
                            <th>Last Scanned</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="cp in checkpoints" :key="cp.id">
                            <td class="td-announce">
                                <div class="td-announce__title">
                                    {{ cp.name }}
                                </div>
                            </td>
                            <td>
                                <span class="token-text">{{ cp.token }}</span>
                            </td>
                            <td class="td-muted">
                                {{ cp.description ?? '—' }}
                            </td>
                            <td class="td-muted">
                                {{ cp.scans_count }}
                            </td>
                            <td class="td-muted" style="font-size: 12px">
                                <template v-if="cp.latest_scan">
                                    {{ formatDate(cp.latest_scan.scanned_at) }}
                                    <br />
                                    <span style="color: #94a3b8">{{
                                        cp.latest_scan.guard?.user?.name ?? '—'
                                    }}</span>
                                </template>
                                <span v-else style="color: #94a3b8">Never</span>
                            </td>
                            <td>
                                <span
                                    class="type-badge"
                                    :class="
                                        cp.is_active
                                            ? 'bg-emerald-50 text-emerald-700'
                                            : 'bg-red-50 text-red-600'
                                    "
                                >
                                    {{ cp.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td>
                                <div style="display: flex; gap: 2px">
                                    <button
                                        @click="openQr(cp)"
                                        title="View / Print QR"
                                        class="icon-btn icon-btn--qr"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            class="h-4 w-4"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                            stroke-width="2"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"
                                            />
                                        </svg>
                                    </button>
                                    <button
                                        @click="openScans(cp)"
                                        title="View Scan Logs"
                                        class="icon-btn icon-btn--scans"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            class="h-4 w-4"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                            stroke-width="2"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"
                                            />
                                        </svg>
                                    </button>
                                    <button
                                        @click="openEdit(cp)"
                                        title="Edit"
                                        class="icon-btn icon-btn--edit"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            class="h-4 w-4"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                            stroke-width="2"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
                                            />
                                        </svg>
                                    </button>
                                    <button
                                        @click="toggleActive(cp)"
                                        :title="
                                            cp.is_active
                                                ? 'Deactivate'
                                                : 'Activate'
                                        "
                                        class="icon-btn"
                                        :class="
                                            cp.is_active
                                                ? 'icon-btn--toggle-on'
                                                : 'icon-btn--toggle-off'
                                        "
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            class="h-4 w-4"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                            stroke-width="2"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M5.636 5.636a9 9 0 1012.728 0M12 3v9"
                                            />
                                        </svg>
                                    </button>
                                    <button
                                        @click="confirmDelete(cp)"
                                        title="Delete"
                                        class="icon-btn icon-btn--danger"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            class="h-4 w-4"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                            stroke-width="2"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                            />
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ═══════════════ CREATE / EDIT MODAL ═══════════════ -->
        <transition name="modal">
            <div
                v-if="showCreateModal"
                class="modal-backdrop"
                @click.self="closeCreateModal"
            >
                <div class="modal-sheet">
                    <div class="modal-sheet__header">
                        <div class="modal-sheet__header-left">
                            <div>
                                <div class="modal-sheet__title">
                                    {{
                                        isEditing
                                            ? 'Edit Checkpoint'
                                            : 'Add Checkpoint'
                                    }}
                                </div>
                                <div class="modal-sheet__sub">
                                    Configure a QR patrol checkpoint
                                </div>
                            </div>
                        </div>
                        <button class="close-btn" @click="closeCreateModal">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-4 w-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M6 18L18 6M6 6l12 12"
                                />
                            </svg>
                        </button>
                    </div>

                    <form
                        @submit.prevent="handleSubmit"
                        class="modal-sheet__body"
                    >
                        <div class="field">
                            <label class="field__label">Name / Title</label>
                            <input
                                v-model="form.name"
                                type="text"
                                class="field__input"
                                :class="{ 'field__input--error': errors.name }"
                                placeholder="e.g. Gate A, Block 1, Parking Lot B"
                            />
                            <span v-if="errors.name" class="field__error">{{
                                errors.name[0]
                            }}</span>
                        </div>

                        <div class="field">
                            <label class="field__label">
                                Description
                                <span class="field__hint">optional</span>
                            </label>
                            <textarea
                                v-model="form.description"
                                class="field__input field__textarea"
                                rows="2"
                                placeholder="e.g. Main entrance gate, north side"
                            ></textarea>
                        </div>

                        <div class="modal-actions">
                            <button
                                type="button"
                                class="btn-ghost"
                                @click="closeCreateModal"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                class="btn-primary"
                                :disabled="isProcessing || !form.name.trim()"
                            >
                                <svg
                                    v-if="isProcessing"
                                    class="spin h-4 w-4"
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
                                {{
                                    isProcessing
                                        ? 'Saving…'
                                        : isEditing
                                          ? 'Update'
                                          : 'Create Checkpoint'
                                }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </transition>

        <!-- ═══════════════ QR MODAL ═══════════════ -->
        <transition name="modal">
            <div
                v-if="showQrModal && selectedCheckpoint"
                class="modal-backdrop"
                @click.self="showQrModal = false"
            >
                <div class="qr-modal">
                    <h2 class="qr-modal__title">
                        {{ selectedCheckpoint.name }}
                    </h2>
                    <p class="qr-modal__sub">{{ client?.user?.name }}</p>

                    <div class="qr-modal__image-wrap">
                        <div v-if="!qrDataUrl" class="qr-modal__loading">
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
                        </div>
                        <img v-else :src="qrDataUrl" class="qr-modal__image" />
                    </div>

                    <p class="token-text" style="margin-bottom: 20px">
                        {{ selectedCheckpoint.token }}
                    </p>

                    <div class="modal-actions">
                        <button class="btn-ghost" @click="showQrModal = false">
                            Close
                        </button>
                        <button
                            class="btn-primary"
                            :disabled="!qrDataUrl"
                            @click="printQr(selectedCheckpoint)"
                        >
                            Print QR Code
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- ═══════════════ SCAN LOGS MODAL ═══════════════ -->
        <transition name="modal">
            <div
                v-if="showScansModal && selectedCheckpoint"
                class="modal-backdrop"
                @click.self="showScansModal = false"
            >
                <div class="ca-modal">
                    <div class="ca-modal__header">
                        <div class="ca-modal__header-left">
                            <div>
                                <div class="ca-modal__title">
                                    Scan Logs — {{ selectedCheckpoint.name }}
                                </div>
                                <div class="ca-modal__sub">
                                    All guard scans at this checkpoint
                                </div>
                            </div>
                        </div>
                        <button
                            class="close-btn"
                            @click="showScansModal = false"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-4 w-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M6 18L18 6M6 6l12 12"
                                />
                            </svg>
                        </button>
                    </div>

                    <div class="ca-modal__body">
                        <div v-if="scansLoading" class="empty-state">
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
                                >Loading…</span
                            >
                        </div>

                        <div
                            v-else-if="!scans || scans.data.length === 0"
                            class="empty-state"
                        >
                            <p class="empty-state__title">
                                No scans recorded yet
                            </p>
                        </div>

                        <table v-else class="data-table">
                            <thead>
                                <tr>
                                    <th>Guard</th>
                                    <th>Scanned At</th>
                                    <th>Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="scan in scans.data" :key="scan.id">
                                    <td class="td-announce__title">
                                        {{ scan.security_guard?.name ?? '—' }}
                                    </td>
                                    <td class="td-muted">
                                        {{ formatDate(scan.scanned_at) }}
                                    </td>
                                    <td
                                        class="td-muted"
                                        style="font-style: italic"
                                    >
                                        {{ scan.note ?? '—' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="pagination-bar" v-if="scans && scans.links">
                        <span class="pagination-bar__info"></span>
                        <div class="pagination-bar__pages">
                            <template v-for="(link, i) in scans.links" :key="i">
                                <button
                                    v-if="link.url"
                                    @click="loadScansPage(link.url)"
                                    v-html="link.label"
                                    class="page-btn"
                                    :class="{
                                        'page-btn--active': link.active,
                                    }"
                                />
                                <span
                                    v-else
                                    v-html="link.label"
                                    class="page-btn page-btn--disabled"
                                />
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </transition>

        <!-- ═══════════════ DELETE CONFIRM MODAL ═══════════════ -->
        <transition name="modal">
            <div
                v-if="confirmDeleteTarget"
                class="modal-backdrop"
                @click.self="confirmDeleteTarget = null"
            >
                <div class="confirm-modal">
                    <div class="confirm-modal__icon">
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-7 w-7 text-red-500"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="1.5"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                            />
                        </svg>
                    </div>
                    <h2 class="confirm-modal__title">Delete Checkpoint?</h2>
                    <p class="confirm-modal__body">
                        This will permanently delete
                        <strong>{{ confirmDeleteTarget.name }}</strong> and all
                        its scan history. This cannot be undone.
                    </p>
                    <div class="confirm-modal__actions">
                        <button
                            @click="confirmDeleteTarget = null"
                            class="btn-ghost"
                        >
                            Cancel
                        </button>
                        <button
                            @click="proceedDelete"
                            class="btn-danger"
                            :disabled="isProcessing"
                        >
                            {{ isProcessing ? 'Deleting…' : 'Yes, Delete' }}
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- Flash toast -->
        <transition name="toast">
            <div v-if="flashMessage" class="toast">
                {{ flashMessage }}
            </div>
        </transition>
    </AppLayout>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap');

.page-root,
.modal-backdrop,
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
}
.back-link {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: none;
    border: none;
    padding: 0;
    margin-bottom: 8px;
    font-size: 12px;
    font-weight: 600;
    color: #94a3b8;
    cursor: pointer;
    font-family: inherit;
    transition: color 0.15s;
}
.back-link:hover {
    color: #ea580c;
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
.page-header__right {
    display: flex;
    align-items: center;
    gap: 10px;
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
.stat-card__value--red {
    color: #dc2626;
}
.stat-card__value--green {
    color: #16a34a;
}
.stat-card__value--orange {
    color: #ea580c;
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
.data-table tbody tr:hover {
    background: #fafbfc;
}
.data-table td {
    padding: 13px 16px;
    vertical-align: middle;
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
.token-text {
    font-family: ui-monospace, monospace;
    font-size: 11px;
    color: #94a3b8;
}

.td-announce__title {
    font-weight: 600;
    color: #1a2332;
}
.td-muted {
    color: #64748b;
}

.icon-btn {
    padding: 7px;
    border-radius: 8px;
    border: none;
    background: transparent;
    cursor: pointer;
    transition: all 0.15s;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #94a3b8;
}
.icon-btn--qr:hover {
    background: #fff7ed;
    color: #ea580c;
}
.icon-btn--scans:hover {
    background: #eff6ff;
    color: #2563eb;
}
.icon-btn--edit:hover {
    background: #f1f5f9;
    color: #475569;
}
.icon-btn--toggle-on {
    color: #16a34a;
}
.icon-btn--toggle-on:hover {
    background: #f0fdf4;
}
.icon-btn--toggle-off:hover {
    background: #f1f5f9;
    color: #475569;
}
.icon-btn--danger:hover {
    background: #fef2f2;
    color: #dc2626;
}

/* PAGINATION */
.pagination-bar {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding: 12px 16px;
    border-top: 1px solid #e4e8ef;
}
.pagination-bar__info {
    font-size: 12px;
    color: #94a3b8;
}
.pagination-bar__pages {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}
.page-btn {
    min-width: 34px;
    height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 8px;
    border: 1px solid #e4e8ef;
    border-radius: 8px;
    background: #ffffff;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
    transition: all 0.15s;
}
.page-btn:hover:not(.page-btn--disabled) {
    border-color: #ea580c;
    color: #ea580c;
}
.page-btn--active {
    background: #ea580c;
    border-color: #ea580c;
    color: #fff;
}
.page-btn--disabled {
    background: #f8fafc;
    color: #94a3b8;
    cursor: default;
}

/* BUTTONS */
.btn-primary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    background: #ea580c !important;
    color: #ffffff !important;
    border: none;
    border-radius: 12px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.18s;
    box-shadow: 0 2px 8px rgba(234, 88, 12, 0.3);
    white-space: nowrap;
    font-family: 'DM Sans', system-ui, sans-serif;
}
.btn-primary:hover:not(:disabled) {
    background: #c2410c !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(234, 88, 12, 0.35);
}
.btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

.btn-ghost {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    background: #f1f5f9;
    color: #64748b;
    border: none;
    border-radius: 12px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
}
.btn-ghost:hover {
    background: #e2e8f0;
}

.btn-danger {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    background: #dc2626;
    color: #fff;
    border: none;
    border-radius: 12px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s;
    box-shadow: 0 2px 8px rgba(220, 38, 38, 0.2);
}
.btn-danger:hover:not(:disabled) {
    background: #b91c1c;
    transform: translateY(-1px);
}
.btn-danger:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

/* MODAL */
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(10, 18, 30, 0.55) !important;
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 24px;
}
.modal-sheet {
    background: #ffffff !important;
    border-radius: 20px;
    width: 100%;
    max-width: 480px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.18);
    border: 1px solid #e4e8ef;
}
.modal-sheet__header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 22px 24px;
    border-bottom: 1px solid #e4e8ef;
    position: sticky;
    top: 0;
    background: #ffffff !important;
    z-index: 2;
}
.modal-sheet__header-left {
    display: flex;
    align-items: center;
    gap: 14px;
    flex: 1;
    min-width: 0;
}
.modal-sheet__title {
    font-size: 15px;
    font-weight: 700;
    color: #1a2332;
}
.modal-sheet__sub {
    font-size: 12px;
    color: #94a3b8;
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
    transition: background 0.15s;
}
.close-btn:hover {
    background: #e2e8f0;
}
.modal-sheet__body {
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 18px;
}

/* FIELDS */
.field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.field__label {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    letter-spacing: 0.3px;
}
.field__hint {
    font-weight: 500;
    color: #94a3b8;
    font-style: italic;
}
.field__error {
    font-size: 11px;
    color: #dc2626;
    font-weight: 600;
    margin-top: 2px;
}
.field__input {
    width: 100%;
    box-sizing: border-box;
    background: #f8fafc;
    border: 1.5px solid #e4e8ef;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 14px;
    font-family: inherit;
    color: #1a2332;
    outline: none;
    transition:
        border-color 0.15s,
        background 0.15s;
}
.field__input:focus {
    border-color: #ea580c;
    background: #fff;
}
.field__input--error {
    border-color: #fca5a5;
    background: #fff;
}
.field__textarea {
    resize: vertical;
    min-height: 64px;
    line-height: 1.6;
}

/* QR MODAL */
.qr-modal {
    background: #ffffff !important;
    border-radius: 20px;
    width: 100%;
    max-width: 380px;
    padding: 28px 28px 24px;
    text-align: center;
    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.18);
    border: 1px solid #e4e8ef;
}
.qr-modal__title {
    font-size: 17px;
    font-weight: 800;
    color: #1a2332;
    margin: 0 0 2px;
}
.qr-modal__sub {
    font-size: 13px;
    color: #94a3b8;
    margin-bottom: 18px;
}
.qr-modal__image-wrap {
    display: flex;
    justify-content: center;
    margin-bottom: 16px;
}
.qr-modal__loading {
    height: 192px;
    width: 192px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.qr-modal__image {
    width: 208px;
    height: 208px;
    border-radius: 12px;
    border: 1px solid #e4e8ef;
}

/* SCAN LOGS MODAL (reuse client-announcements pattern) */
.ca-modal {
    background: #ffffff;
    border-radius: 20px;
    width: 100%;
    max-width: 760px;
    max-height: 88vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.18);
    border: 1px solid #e4e8ef;
    overflow: hidden;
}
.ca-modal__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 20px 24px;
    border-bottom: 1px solid #e4e8ef;
    flex-shrink: 0;
}
.ca-modal__header-left {
    display: flex;
    align-items: center;
    gap: 12px;
}
.ca-modal__title {
    font-size: 15px;
    font-weight: 700;
    color: #1a2332;
}
.ca-modal__sub {
    font-size: 12px;
    color: #94a3b8;
    margin-top: 1px;
}
.ca-modal__body {
    flex: 1;
    overflow-y: auto;
}

/* MODAL ACTIONS */
.modal-actions {
    display: flex;
    gap: 10px;
    padding-top: 4px;
}
.modal-actions .btn-ghost {
    flex: 1;
}
.modal-actions .btn-primary {
    flex: 2;
}

/* CONFIRM MODAL */
.confirm-modal {
    background: #ffffff !important;
    border-radius: 20px;
    width: 100%;
    max-width: 400px;
    padding: 32px 28px 26px;
    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.18);
    border: 1px solid #e4e8ef;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 10px;
}
.confirm-modal__icon {
    width: 60px;
    height: 60px;
    background: #fef2f2;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 6px;
}
.confirm-modal__title {
    font-size: 17px;
    font-weight: 800;
    color: #1a2332;
    margin: 0;
}
.confirm-modal__body {
    font-size: 13px;
    color: #64748b;
    line-height: 1.6;
    margin-bottom: 8px;
}
.confirm-modal__actions {
    display: flex;
    gap: 10px;
    width: 100%;
    margin-top: 4px;
}
.confirm-modal__actions .btn-ghost {
    flex: 1;
}
.confirm-modal__actions .btn-danger {
    flex: 1.4;
}

/* TOAST */
.toast {
    position: fixed;
    bottom: 28px;
    right: 28px;
    background: #1a2332;
    color: #f1f5f9;
    padding: 12px 18px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 600;
    z-index: 99999;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
    display: flex;
    align-items: center;
    gap: 8px;
    border-left: 3px solid #ea580c;
}

/* TRANSITIONS */
.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.22s ease;
}
.modal-enter-active .modal-sheet,
.modal-leave-active .modal-sheet,
.modal-enter-active .confirm-modal,
.modal-leave-active .confirm-modal,
.modal-enter-active .qr-modal,
.modal-leave-active .qr-modal,
.modal-enter-active .ca-modal,
.modal-leave-active .ca-modal {
    transition:
        transform 0.22s ease,
        opacity 0.22s ease;
}
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
.modal-enter-from .modal-sheet,
.modal-leave-to .modal-sheet,
.modal-enter-from .confirm-modal,
.modal-leave-to .confirm-modal,
.modal-enter-from .qr-modal,
.modal-leave-to .qr-modal,
.modal-enter-from .ca-modal,
.modal-leave-to .ca-modal {
    transform: scale(0.97) translateY(12px);
}

.toast-enter-active,
.toast-leave-active {
    transition: all 0.25s ease;
}
.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateY(8px);
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
        min-width: 700px;
    }
    .table-card {
        overflow-x: auto;
    }
}
</style>

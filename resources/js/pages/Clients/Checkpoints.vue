<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import QRCode from 'qrcode';
import { nextTick, onMounted, ref } from 'vue';
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
</script>

<template>
    <AppLayout>
        <div class="flex h-full w-full flex-col rounded-xl bg-white shadow-md">
            <!-- Header -->
            <div class="flex items-center justify-between border-b px-6 py-4">
                <div>
                    <button
                        @click="router.visit('/clients')"
                        class="mb-1 flex items-center gap-1 text-sm text-gray-500 hover:text-gray-800"
                    >
                        ← Back to Clients
                    </button>
                    <h1 class="text-lg font-bold text-gray-900">
                        {{ client?.user?.name ?? 'Loading...' }} — Checkpoints
                    </h1>
                    <p class="text-sm text-gray-500">
                        Guard patrol QR checkpoints for this estate
                    </p>
                </div>
                <button
                    @click="openCreate"
                    class="rounded-lg bg-orange-500 px-4 py-2 text-sm font-bold text-white hover:bg-orange-600"
                >
                    + Add Checkpoint
                </button>
            </div>

            <!-- Flash -->
            <div
                v-if="flashMessage"
                class="mx-6 mt-4 rounded bg-green-100 px-4 py-2 text-sm text-green-800"
            >
                {{ flashMessage }}
            </div>

            <!-- Loading -->
            <div
                v-if="isLoading"
                class="flex items-center justify-center py-20 text-gray-400"
            >
                <span class="loader mr-2"></span> Loading checkpoints...
            </div>

            <!-- Empty -->
            <div
                v-else-if="checkpoints.length === 0"
                class="flex flex-col items-center justify-center py-20 text-gray-400"
            >
                <p class="mb-3 text-4xl">📍</p>
                <p class="font-semibold">No checkpoints yet</p>
                <p class="text-sm">Add your first checkpoint to get started</p>
            </div>

            <!-- Table -->
            <table v-else class="w-full min-w-max table-auto text-left">
                <thead>
                    <tr class="bg-gray-50">
                        <th
                            class="border-y border-gray-200 p-4 text-sm font-semibold text-gray-600"
                        >
                            Name
                        </th>
                        <th
                            class="border-y border-gray-200 p-4 text-sm font-semibold text-gray-600"
                        >
                            Token
                        </th>
                        <th
                            class="border-y border-gray-200 p-4 text-sm font-semibold text-gray-600"
                        >
                            Description
                        </th>
                        <th
                            class="border-y border-gray-200 p-4 text-sm font-semibold text-gray-600"
                        >
                            Total Scans
                        </th>
                        <th
                            class="border-y border-gray-200 p-4 text-sm font-semibold text-gray-600"
                        >
                            Last Scanned
                        </th>
                        <th
                            class="border-y border-gray-200 p-4 text-sm font-semibold text-gray-600"
                        >
                            Status
                        </th>
                        <th
                            class="border-y border-gray-200 p-4 text-sm font-semibold text-gray-600"
                        >
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="cp in checkpoints"
                        :key="cp.id"
                        class="hover:bg-gray-50/50"
                    >
                        <td
                            class="border-b border-gray-100 p-4 font-semibold text-gray-900"
                        >
                            {{ cp.name }}
                        </td>
                        <td class="border-b border-gray-100 p-4">
                            <span class="font-mono text-xs text-gray-500">{{
                                cp.token
                            }}</span>
                        </td>
                        <td
                            class="border-b border-gray-100 p-4 text-sm text-gray-600"
                        >
                            {{ cp.description ?? '—' }}
                        </td>
                        <td
                            class="border-b border-gray-100 p-4 text-sm text-gray-700"
                        >
                            {{ cp.scans_count }}
                        </td>
                        <td
                            class="border-b border-gray-100 p-4 text-sm text-gray-600"
                        >
                            <span v-if="cp.latest_scan">
                                {{ formatDate(cp.latest_scan.scanned_at)
                                }}<br />
                                <span class="text-xs text-gray-400">{{
                                    cp.latest_scan.guard?.user?.name ?? '—'
                                }}</span>
                            </span>
                            <span v-else class="text-gray-400">Never</span>
                        </td>
                        <td class="border-b border-gray-100 p-4">
                            <span
                                :class="[
                                    'rounded-full px-2 py-1 text-xs font-bold uppercase',
                                    cp.is_active
                                        ? 'border border-green-300 bg-green-100 text-green-800'
                                        : 'border border-red-300 bg-red-100 text-red-800',
                                ]"
                            >
                                {{ cp.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="border-b border-gray-100 p-4">
                            <div class="flex items-center gap-1">
                                <!-- QR -->
                                <button
                                    @click="openQr(cp)"
                                    title="View / Print QR"
                                    class="rounded-lg p-2 text-orange-500 hover:bg-orange-50"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="h-4 w-4"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"
                                        />
                                    </svg>
                                </button>
                                <!-- Scans log -->
                                <button
                                    @click="openScans(cp)"
                                    title="View Scan Logs"
                                    class="rounded-lg p-2 text-blue-500 hover:bg-blue-50"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="h-4 w-4"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"
                                        />
                                    </svg>
                                </button>
                                <!-- Edit -->
                                <button
                                    @click="openEdit(cp)"
                                    title="Edit"
                                    class="rounded-lg p-2 text-gray-500 hover:bg-gray-100"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="h-4 w-4"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
                                        />
                                    </svg>
                                </button>
                                <!-- Toggle active/inactive -->
                                <button
                                    @click="toggleActive(cp)"
                                    :title="
                                        cp.is_active ? 'Deactivate' : 'Activate'
                                    "
                                    :class="[
                                        'rounded-lg p-2',
                                        cp.is_active
                                            ? 'text-green-500 hover:bg-green-50'
                                            : 'text-gray-400 hover:bg-gray-100',
                                    ]"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="h-4 w-4"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M5.636 5.636a9 9 0 1012.728 0M12 3v9"
                                        />
                                    </svg>
                                </button>
                                <!-- Delete -->
                                <button
                                    @click="confirmDelete(cp)"
                                    title="Delete"
                                    class="rounded-lg p-2 text-red-500 hover:bg-red-50"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="h-4 w-4"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
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

        <!-- ── Create / Edit Modal ─────────────────────────────────────────── -->
        <div
            v-if="showCreateModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
        >
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h2 class="mb-4 text-lg font-bold text-gray-900">
                    {{ isEditing ? 'Edit Checkpoint' : 'Add Checkpoint' }}
                </h2>
                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <label class="text-sm font-semibold text-gray-700"
                            >Name / Title</label
                        >
                        <input
                            v-model="form.name"
                            class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-orange-400 focus:ring-2 focus:ring-orange-100"
                            placeholder="e.g. Gate A, Block 1, Parking Lot B"
                        />
                        <p v-if="errors.name" class="text-sm text-red-600">
                            {{ errors.name[0] }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <label class="text-sm font-semibold text-gray-700"
                            >Description
                            <span class="font-normal text-gray-400"
                                >(optional)</span
                            ></label
                        >
                        <textarea
                            v-model="form.description"
                            class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-orange-400 focus:ring-2 focus:ring-orange-100"
                            rows="2"
                            placeholder="e.g. Main entrance gate, north side"
                        ></textarea>
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button
                            @click="closeCreateModal"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                        >
                            Cancel
                        </button>
                        <button
                            @click="handleSubmit"
                            :disabled="isProcessing || !form.name.trim()"
                            class="flex items-center gap-2 rounded-lg bg-orange-500 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-600 disabled:opacity-60"
                        >
                            <span v-if="isProcessing" class="loader"></span>
                            {{
                                isProcessing
                                    ? 'Saving...'
                                    : isEditing
                                      ? 'Update'
                                      : 'Create Checkpoint'
                            }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── QR Modal ───────────────────────────────────────────────────── -->
        <div
            v-if="showQrModal && selectedCheckpoint"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
        >
            <div
                class="w-full max-w-sm rounded-xl bg-white p-6 text-center shadow-xl"
            >
                <h2 class="mb-1 text-lg font-bold text-gray-900">
                    {{ selectedCheckpoint.name }}
                </h2>
                <p class="mb-4 text-sm text-gray-500">
                    {{ client?.user?.name }}
                </p>

                <div class="mb-4 flex justify-center">
                    <div
                        v-if="!qrDataUrl"
                        class="flex h-48 w-48 items-center justify-center"
                    >
                        <span class="loader"></span>
                    </div>
                    <img
                        v-else
                        :src="qrDataUrl"
                        class="h-52 w-52 rounded-lg border border-gray-100"
                    />
                </div>

                <p class="mb-6 font-mono text-xs text-gray-400">
                    {{ selectedCheckpoint.token }}
                </p>

                <div class="flex justify-center gap-3">
                    <button
                        @click="printQr(selectedCheckpoint)"
                        :disabled="!qrDataUrl"
                        class="rounded-lg bg-orange-500 px-5 py-2 text-sm font-bold text-white hover:bg-orange-600 disabled:opacity-50"
                    >
                        Print QR Code
                    </button>
                    <button
                        @click="showQrModal = false"
                        class="rounded-lg border border-gray-300 px-5 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>

        <!-- ── Scan Logs Modal ────────────────────────────────────────────── -->
        <div
            v-if="showScansModal && selectedCheckpoint"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
        >
            <div
                class="flex max-h-[85vh] w-full max-w-2xl flex-col rounded-xl bg-white shadow-xl"
            >
                <!-- Header -->
                <div
                    class="flex items-center justify-between border-b px-6 py-4"
                >
                    <div>
                        <h2 class="text-base font-bold text-gray-900">
                            Scan Logs — {{ selectedCheckpoint.name }}
                        </h2>
                        <p class="text-sm text-gray-500">
                            All guard scans at this checkpoint
                        </p>
                    </div>
                    <button
                        @click="showScansModal = false"
                        class="text-xl font-bold text-gray-400 hover:text-gray-700"
                    >
                        ✕
                    </button>
                </div>

                <!-- Body -->
                <div class="flex-1 overflow-y-auto px-6 py-4">
                    <div
                        v-if="scansLoading"
                        class="flex justify-center py-10 text-gray-400"
                    >
                        <span class="loader mr-2"></span> Loading...
                    </div>

                    <div
                        v-else-if="!scans || scans.data.length === 0"
                        class="py-10 text-center text-gray-400"
                    >
                        <p class="mb-2 text-3xl">📭</p>
                        <p>No scans recorded yet</p>
                    </div>

                    <table v-else class="w-full text-left text-sm">
                        <thead>
                            <tr class="bg-gray-50">
                                <th
                                    class="border-y border-gray-200 p-3 font-semibold text-gray-600"
                                >
                                    Guard
                                </th>
                                <th
                                    class="border-y border-gray-200 p-3 font-semibold text-gray-600"
                                >
                                    Scanned At
                                </th>
                                <th
                                    class="border-y border-gray-200 p-3 font-semibold text-gray-600"
                                >
                                    Note
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="scan in scans.data"
                                :key="scan.id"
                                class="hover:bg-gray-50/50"
                            >
                                <td
                                    class="border-b border-gray-100 p-3 font-medium text-gray-900"
                                >
                                    {{ scan.guard?.user?.name ?? '—' }}
                                </td>
                                <td
                                    class="border-b border-gray-100 p-3 text-gray-600"
                                >
                                    {{ formatDate(scan.scanned_at) }}
                                </td>
                                <td
                                    class="border-b border-gray-100 p-3 text-gray-500 italic"
                                >
                                    {{ scan.note ?? '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div
                    v-if="scans && scans.links"
                    class="flex flex-wrap justify-end gap-2 border-t px-6 py-3"
                >
                    <template v-for="(link, i) in scans.links" :key="i">
                        <button
                            v-if="link.url"
                            @click="loadScansPage(link.url)"
                            v-html="link.label"
                            class="min-w-[36px] rounded border px-3 py-1 text-center text-sm transition-all"
                            :class="
                                link.active
                                    ? 'border-orange-500 bg-orange-500 text-white'
                                    : 'border-gray-300 bg-white text-orange-500 hover:bg-gray-50'
                            "
                        />
                        <span
                            v-else
                            v-html="link.label"
                            class="min-w-[36px] cursor-not-allowed rounded border border-gray-200 bg-gray-100 px-3 py-1 text-center text-sm text-gray-400"
                        />
                    </template>
                </div>
            </div>
        </div>

        <!-- ── Delete Confirm Modal ───────────────────────────────────────── -->
        <div
            v-if="confirmDeleteTarget"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
        >
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h2 class="text-lg font-bold text-gray-900">
                    Delete Checkpoint?
                </h2>
                <p class="mt-2 text-sm text-gray-600">
                    This will permanently delete
                    <strong>{{ confirmDeleteTarget.name }}</strong> and all its
                    scan history. This cannot be undone.
                </p>
                <div class="mt-6 flex justify-end gap-3">
                    <button
                        @click="confirmDeleteTarget = null"
                        class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        Cancel
                    </button>
                    <button
                        @click="proceedDelete"
                        :disabled="isProcessing"
                        class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-60"
                    >
                        {{ isProcessing ? 'Deleting...' : 'Yes, Delete' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.loader {
    border: 2px solid #f3f3f3;
    border-top: 2px solid #f97316;
    border-radius: 50%;
    width: 14px;
    height: 14px;
    animation: spin 0.8s linear infinite;
    display: inline-block;
}
@keyframes spin {
    0% {
        transform: rotate(0deg);
    }
    100% {
        transform: rotate(360deg);
    }
}
</style>

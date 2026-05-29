<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import QRCode from 'qrcode';
import { computed, onMounted, ref } from 'vue';

const auth = useAuthStore();

// Get client ID from URL
const clientId = computed(() => {
    const parts = window.location.pathname.split('/');
    return parts[parts.indexOf('clients') + 1];
});

const client = ref<any>(null);
const checkpoints = ref<any[]>([]);
const isLoading = ref(false);
const isProcessing = ref(false);
const flashMessage = ref<string | null>(null);

// Create checkpoint modal
const showCreateModal = ref(false);
const newCheckpointName = ref('');
const createError = ref('');

// QR code modal
const showQrModal = ref(false);
const qrCheckpoint = ref<any>(null);
const qrDataUrl = ref('');

// Scan logs modal
const showScansModal = ref(false);
const scansCheckpoint = ref<any>(null);
const scans = ref<any>({ data: [], total: 0, links: [] });
const isLoadingScans = ref(false);

// Delete confirm
const confirmDeleteCheckpoint = ref<any>(null);

function showMessage(msg: string) {
    flashMessage.value = msg;
    setTimeout(() => (flashMessage.value = null), 3000);
}

async function loadCheckpoints() {
    isLoading.value = true;
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/clients/${clientId.value}/checkpoints`,
            {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                },
            },
        );
        client.value = data.client;
        checkpoints.value = data.checkpoints;
    } catch (err) {
        console.error('Failed to load checkpoints', err);
    } finally {
        isLoading.value = false;
    }
}

async function createCheckpoint() {
    if (!newCheckpointName.value.trim()) {
        createError.value = 'Checkpoint name is required.';
        return;
    }
    isProcessing.value = true;
    try {
        await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/clients/${clientId.value}/checkpoints`,
            { name: newCheckpointName.value },
            {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                },
            },
        );
        showMessage('Checkpoint created successfully.');
        newCheckpointName.value = '';
        createError.value = '';
        showCreateModal.value = false;
        await loadCheckpoints();
    } catch (err) {
        console.error('Failed to create checkpoint', err);
    } finally {
        isProcessing.value = false;
    }
}

async function openQrModal(checkpoint: any) {
    qrCheckpoint.value = checkpoint;
    showQrModal.value = true;
    // Generate QR code containing the token
    qrDataUrl.value = await QRCode.toDataURL(checkpoint.token, {
        width: 220,
        margin: 2,
    });
}

async function openScansModal(checkpoint: any) {
    scansCheckpoint.value = checkpoint;
    showScansModal.value = true;
    await loadScans(checkpoint.id);
}

async function loadScans(checkpointId: number, url?: string) {
    isLoadingScans.value = true;
    try {
        const endpoint =
            url ||
            `${import.meta.env.VITE_APP_URL}/api/checkpoints/${checkpointId}/scans`;
        const { data } = await axios.get(endpoint, {
            headers: {
                Authorization: `Bearer ${localStorage.getItem('token')}`,
            },
        });
        scans.value = data.scans;
    } catch (err) {
        console.error('Failed to load scans', err);
    } finally {
        isLoadingScans.value = false;
    }
}

async function deleteCheckpoint() {
    if (!confirmDeleteCheckpoint.value) return;
    isProcessing.value = true;
    try {
        await axios.delete(
            `${import.meta.env.VITE_APP_URL}/api/checkpoints/${confirmDeleteCheckpoint.value.id}`,
            {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                },
            },
        );
        showMessage('Checkpoint deleted.');
        confirmDeleteCheckpoint.value = null;
        await loadCheckpoints();
    } catch (err) {
        console.error('Failed to delete checkpoint', err);
    } finally {
        isProcessing.value = false;
    }
}

function printQr() {
    const printWindow = window.open('', '_blank');
    if (!printWindow) return;
    printWindow.document.write(`
        <html>
          <head><title>QR Code - ${qrCheckpoint.value.name}</title></head>
          <body style="display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;">
            <div style="text-align:center;border:1px solid #ddd;padding:32px;border-radius:12px;">
              <img src="${qrDataUrl.value}" width="200" />
              <h2 style="margin:12px 0 4px">${qrCheckpoint.value.name}</h2>
              <p style="color:#666;margin:0">${client.value?.user?.name ?? ''}</p>
              <p style="color:#aaa;font-size:12px;margin-top:8px;font-family:monospace">${qrCheckpoint.value.token}</p>
            </div>
          </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
}

function formatDate(dateStr: string) {
    return new Date(dateStr).toLocaleString('en-ZA', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

onMounted(() => {
    if (auth.user?.role !== 'admin') {
        router.visit('/dashboard');
    }
    loadCheckpoints();
});
</script>

<template>
    <AppLayout>
        <div
            class="relative flex h-full w-full flex-col rounded-xl bg-white bg-clip-border text-gray-700 shadow-md"
        >
            <!-- Header -->
            <div
                class="relative mx-4 mt-4 overflow-hidden rounded-none bg-white bg-clip-border text-gray-700"
            >
                <div class="mb-6 flex items-center justify-between gap-8">
                    <div class="flex items-center gap-3">
                        <button
                            @click="router.visit('/clients')"
                            class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50"
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
                                    d="M15 19l-7-7 7-7"
                                />
                            </svg>
                            Clients
                        </button>
                        <div>
                            <p
                                class="font-sans text-base font-semibold text-gray-900"
                            >
                                {{ client?.user?.name ?? 'Loading...' }}
                            </p>
                            <p class="text-xs text-gray-500">QR Checkpoints</p>
                        </div>
                    </div>
                    <button
                        @click="showCreateModal = true"
                        class="rounded-lg border border-gray-900 px-4 py-2 text-center align-middle font-sans text-xs font-bold text-gray-900 uppercase transition-all hover:opacity-75"
                        type="button"
                    >
                        Add Checkpoint
                    </button>
                </div>

                <!-- Flash -->
                <div
                    v-if="flashMessage"
                    class="mb-4 rounded bg-green-100 p-2 text-sm text-green-700"
                >
                    {{ flashMessage }}
                </div>
            </div>

            <!-- Loading -->
            <div
                v-if="isLoading"
                class="flex justify-center py-16 text-sm text-gray-400"
            >
                Loading checkpoints...
            </div>

            <!-- Empty state -->
            <div
                v-else-if="checkpoints.length === 0"
                class="flex flex-col items-center justify-center py-20 text-center"
            >
                <div class="mb-3 text-4xl">📍</div>
                <p class="font-semibold text-gray-700">No checkpoints yet</p>
                <p class="mt-1 text-sm text-gray-400">
                    Add your first checkpoint to get started
                </p>
            </div>

            <!-- Checkpoints Table -->
            <table v-else class="mt-0 w-full min-w-max table-auto text-left">
                <thead>
                    <tr class="bg-gray-50">
                        <th
                            class="border-blue-gray-100 border-y p-4 font-sans text-sm font-normal opacity-70"
                        >
                            Checkpoint Name
                        </th>
                        <th
                            class="border-blue-gray-100 border-y p-4 font-sans text-sm font-normal opacity-70"
                        >
                            Token
                        </th>
                        <th
                            class="border-blue-gray-100 border-y p-4 font-sans text-sm font-normal opacity-70"
                        >
                            Total Scans
                        </th>
                        <th
                            class="border-blue-gray-100 border-y p-4 font-sans text-sm font-normal opacity-70"
                        >
                            Created
                        </th>
                        <th
                            class="border-blue-gray-100 border-y p-4 font-sans text-sm font-normal opacity-70"
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
                        <td class="border-blue-gray-50 border-b p-4">
                            <p
                                class="font-sans text-sm font-semibold text-gray-900"
                            >
                                {{ cp.name }}
                            </p>
                        </td>
                        <td class="border-blue-gray-50 border-b p-4">
                            <span
                                class="rounded bg-gray-100 px-2 py-1 font-mono text-xs text-gray-500"
                                >{{ cp.token }}</span
                            >
                        </td>
                        <td class="border-blue-gray-50 border-b p-4">
                            <span class="text-sm font-semibold text-gray-700">{{
                                cp.scans_count ?? 0
                            }}</span>
                        </td>
                        <td class="border-blue-gray-50 border-b p-4">
                            <span class="text-sm text-gray-500">{{
                                formatDate(cp.created_at)
                            }}</span>
                        </td>
                        <td class="border-blue-gray-50 border-b p-4">
                            <div class="flex items-center gap-2">
                                <!-- View QR -->
                                <button
                                    @click="openQrModal(cp)"
                                    class="rounded-lg bg-orange-50 px-3 py-1.5 text-xs font-semibold text-orange-600 transition-colors hover:bg-orange-100"
                                    title="View QR Code"
                                >
                                    QR Code
                                </button>
                                <!-- View Scans -->
                                <button
                                    @click="openScansModal(cp)"
                                    class="rounded-lg bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-600 transition-colors hover:bg-blue-100"
                                    title="View Scan Logs"
                                >
                                    Scan Logs
                                </button>
                                <!-- Delete -->
                                <button
                                    @click="confirmDeleteCheckpoint = cp"
                                    class="relative h-8 w-8 rounded-lg text-red-600 transition-all hover:bg-red-50"
                                    type="button"
                                    title="Delete Checkpoint"
                                >
                                    <span
                                        class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2"
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
                                    </span>
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>

    <!-- ── Create Checkpoint Modal ── -->
    <div
        v-if="showCreateModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-[2px]"
    >
        <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-lg">
            <h2 class="mb-4 text-lg font-bold text-gray-900">Add Checkpoint</h2>
            <div class="grid gap-3">
                <div>
                    <label class="text-sm font-semibold text-gray-700"
                        >Checkpoint Name</label
                    >
                    <input
                        v-model="newCheckpointName"
                        class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-orange-400 focus:ring-2 focus:ring-orange-100"
                        placeholder="e.g. Block 1, Gate A, Server Room"
                        @keyup.enter="createCheckpoint"
                    />
                    <p v-if="createError" class="mt-1 text-sm text-red-600">
                        {{ createError }}
                    </p>
                </div>
                <p class="text-xs text-gray-400">
                    A unique QR token will be auto-generated for this
                    checkpoint.
                </p>
                <div class="flex items-center justify-end gap-3 pt-2">
                    <button
                        type="button"
                        @click="
                            showCreateModal = false;
                            newCheckpointName = '';
                            createError = '';
                        "
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        @click="createCheckpoint"
                        :disabled="isProcessing"
                        class="flex items-center gap-2 rounded-lg bg-orange-500 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-600 disabled:opacity-60"
                    >
                        <span v-if="isProcessing" class="loader"></span>
                        {{ isProcessing ? 'Creating...' : 'Create Checkpoint' }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── QR Code Modal ── -->
    <div
        v-if="showQrModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-[2px]"
    >
        <div
            class="w-full max-w-sm rounded-lg bg-white p-6 text-center shadow-lg"
        >
            <h2 class="mb-1 text-lg font-bold text-gray-900">
                {{ qrCheckpoint?.name }}
            </h2>
            <p class="mb-4 text-sm text-gray-500">{{ client?.user?.name }}</p>

            <div class="mb-4 flex justify-center">
                <img
                    v-if="qrDataUrl"
                    :src="qrDataUrl"
                    alt="QR Code"
                    class="rounded-lg border border-gray-100 p-2"
                />
            </div>

            <p class="mb-5 font-mono text-xs text-gray-400">
                {{ qrCheckpoint?.token }}
            </p>

            <div class="flex items-center justify-center gap-3">
                <button
                    @click="showQrModal = false"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                >
                    Close
                </button>
                <button
                    @click="printQr"
                    class="rounded-lg bg-orange-500 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-600"
                >
                    Print QR Card
                </button>
            </div>
        </div>
    </div>

    <!-- ── Scan Logs Modal ── -->
    <div
        v-if="showScansModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-[2px]"
    >
        <div
            class="flex max-h-[85vh] w-full max-w-2xl flex-col rounded-lg bg-white shadow-lg"
        >
            <!-- Modal header -->
            <div
                class="flex items-center justify-between border-b border-gray-100 p-5"
            >
                <div>
                    <h2 class="text-base font-bold text-gray-900">Scan Logs</h2>
                    <p class="text-sm text-gray-500">
                        {{ scansCheckpoint?.name }}
                    </p>
                </div>
                <button
                    @click="showScansModal = false"
                    class="text-gray-400 hover:text-gray-600"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M6 18L18 6M6 6l12 12"
                        />
                    </svg>
                </button>
            </div>

            <!-- Modal body -->
            <div class="flex-1 overflow-y-auto p-5">
                <div
                    v-if="isLoadingScans"
                    class="py-10 text-center text-sm text-gray-400"
                >
                    Loading scans...
                </div>
                <div
                    v-else-if="scans.data.length === 0"
                    class="py-10 text-center"
                >
                    <p class="mb-2 text-2xl">🔍</p>
                    <p class="text-sm text-gray-500">
                        No scans recorded yet for this checkpoint.
                    </p>
                </div>
                <table v-else class="w-full text-left text-sm">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="p-3 font-semibold text-gray-600">
                                Guard
                            </th>
                            <th class="p-3 font-semibold text-gray-600">
                                Scanned At
                            </th>
                            <th class="p-3 font-semibold text-gray-600">
                                Note
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="scan in scans.data"
                            :key="scan.id"
                            class="border-t border-gray-50 hover:bg-gray-50/50"
                        >
                            <td class="p-3">
                                <p class="font-semibold text-gray-800">
                                    {{ scan.guard?.name ?? 'Unknown' }}
                                </p>
                                <p class="text-xs text-gray-400">
                                    {{ scan.guard?.phone ?? '' }}
                                </p>
                            </td>
                            <td class="p-3 text-gray-600">
                                {{ formatDate(scan.scanned_at) }}
                            </td>
                            <td class="p-3 text-gray-500 italic">
                                {{ scan.note ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div
                v-if="scans.links?.length > 3"
                class="flex justify-center gap-1 border-t border-gray-100 p-4"
            >
                <template v-for="(link, i) in scans.links" :key="i">
                    <button
                        v-if="link.url"
                        @click="loadScans(scansCheckpoint.id, link.url)"
                        v-html="link.label"
                        class="inline-block min-w-[36px] rounded border px-2 py-1 text-center text-xs transition-all"
                        :class="{
                            'border-blue-500 bg-blue-500 text-white':
                                link.active,
                            'border-gray-300 bg-white text-blue-500 hover:bg-gray-50':
                                !link.active,
                        }"
                    />
                    <span
                        v-else
                        v-html="link.label"
                        class="inline-block min-w-[36px] cursor-not-allowed rounded border border-gray-300 bg-gray-100 px-2 py-1 text-center text-xs text-gray-400"
                    />
                </template>
            </div>
        </div>
    </div>

    <!-- ── Delete Confirm Modal ── -->
    <div
        v-if="confirmDeleteCheckpoint"
        class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50"
    >
        <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
            <h2 class="text-lg font-bold text-gray-900">Delete Checkpoint?</h2>
            <p class="mt-2 text-sm text-gray-600">
                You are about to delete
                <strong>{{ confirmDeleteCheckpoint.name }}</strong
                >. All scan logs for this checkpoint will also be permanently
                deleted.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <button
                    @click="confirmDeleteCheckpoint = null"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100"
                >
                    Cancel
                </button>
                <button
                    @click="deleteCheckpoint"
                    :disabled="isProcessing"
                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
                >
                    {{ isProcessing ? 'Deleting...' : 'Yes, Delete' }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.loader {
    border: 2px solid #f3f3f3;
    border-top: 2px solid #3498db;
    border-radius: 50%;
    width: 16px;
    height: 16px;
    animation: spin 1s linear infinite;
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

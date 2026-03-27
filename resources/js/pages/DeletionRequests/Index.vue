<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';

const auth = useAuthStore();

onMounted(() => {
    if (auth.user?.role !== 'admin') {
        router.visit('/dashboard');
        return;
    }
    load();
});

const requests = ref<any[]>([]);
const loading = ref(true);
const filter = ref<'all' | 'pending' | 'processing' | 'deleted' | 'cancelled'>(
    'all',
);
const search = ref('');
const selectedRequest = ref<any>(null);
const showPanel = ref(false);
const isProcessing = ref(false);
const flashMsg = ref('');
const flashType = ref<'success' | 'error'>('success');
const confirmAction = ref<{ type: 'delete' | 'cancel'; request: any } | null>(
    null,
);

const pagination = ref({
    current_page: 1,
    last_page: 1,
    total: 0,
    from: 0,
    to: 0,
    links: [] as any[],
});

const authHeaders = () => ({
    Authorization: `Bearer ${localStorage.getItem('token')}`,
});

async function load(url?: string) {
    loading.value = true;
    try {
        const endpoint =
            url ||
            `${import.meta.env.VITE_APP_URL}/api/account/deletion-requests`;
        const { data } = await axios.get(endpoint, { headers: authHeaders() });
        requests.value = data.data;
        pagination.value = {
            current_page: data.current_page,
            last_page: data.last_page,
            total: data.total,
            from: data.from,
            to: data.to,
            links: data.links,
        };
    } catch (e) {
        console.error(e);
    } finally {
        loading.value = false;
    }
}

const filteredRequests = computed(() => {
    return requests.value
        .filter((r) => filter.value === 'all' || r.status === filter.value)
        .filter((r) => {
            if (!search.value) return true;
            const s = search.value.toLowerCase();
            return (
                r.name?.toLowerCase().includes(s) ||
                r.email?.toLowerCase().includes(s) ||
                r.phone?.toLowerCase().includes(s)
            );
        });
});

const stats = computed(() => ({
    total: requests.value.length,
    pending: requests.value.filter((r) => r.status === 'pending').length,
    deleted: requests.value.filter((r) => r.status === 'deleted').length,
    cancelled: requests.value.filter((r) => r.status === 'cancelled').length,
}));

async function proceedDelete() {
    if (!confirmAction.value) return;
    isProcessing.value = true;
    try {
        await axios.delete(
            `${import.meta.env.VITE_APP_URL}/api/account/deletion-requests/${confirmAction.value.request.id}`,
            { headers: authHeaders() },
        );
        flash('Account deleted successfully', 'success');
        if (selectedRequest.value?.id === confirmAction.value.request.id)
            showPanel.value = false;
        await load();
    } catch (e) {
        flash('Failed to delete account', 'error');
    } finally {
        isProcessing.value = false;
        confirmAction.value = null;
    }
}

async function proceedCancel() {
    if (!confirmAction.value) return;
    isProcessing.value = true;
    try {
        await axios.patch(
            `${import.meta.env.VITE_APP_URL}/api/account/deletion-requests/${confirmAction.value.request.id}/cancel`,
            {},
            { headers: authHeaders() },
        );
        flash('Deletion request cancelled — account reactivated', 'success');
        await load();
        if (selectedRequest.value?.id === confirmAction.value.request.id) {
            selectedRequest.value = requests.value.find(
                (r) => r.id === confirmAction.value!.request.id,
            );
        }
    } catch (e) {
        flash('Failed to cancel request', 'error');
    } finally {
        isProcessing.value = false;
        confirmAction.value = null;
    }
}

function flash(msg: string, type: 'success' | 'error' = 'success') {
    flashMsg.value = msg;
    flashType.value = type;
    setTimeout(() => (flashMsg.value = ''), 3500);
}

function formatDate(ts: string) {
    if (!ts) return '—';
    return new Date(ts).toLocaleString('en-ZA', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function daysUntil(ts: string) {
    if (!ts) return null;
    const diff = Math.ceil(
        (new Date(ts).getTime() - Date.now()) / (1000 * 60 * 60 * 24),
    );
    return diff;
}

const statusConfig: Record<string, { label: string; classes: string }> = {
    pending: {
        label: 'Pending',
        classes: 'bg-amber-50 text-amber-700 border border-amber-200',
    },
    processing: {
        label: 'Processing',
        classes: 'bg-blue-50 text-blue-700 border border-blue-200',
    },
    deleted: {
        label: 'Deleted',
        classes: 'bg-red-50 text-red-700 border border-red-200',
    },
    cancelled: {
        label: 'Cancelled',
        classes: 'bg-green-50 text-green-700 border border-green-200',
    },
};
</script>

<template>
    <Head title="Deletion Requests" />
    <AppLayout>
        <div class="flex h-full flex-col">
            <!-- Header -->
            <div class="border-b border-gray-100 bg-white px-6 py-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-lg font-bold text-gray-900">
                            Account Deletion Requests
                        </h1>
                        <p class="text-sm text-gray-500">
                            Manage user data deletion requests — POPIA compliant
                        </p>
                    </div>
                    <button
                        @click="load()"
                        class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-50"
                    >
                        ↻ Refresh
                    </button>
                </div>

                <!-- Stats -->
                <div class="mt-4 grid grid-cols-4 gap-4">
                    <div class="rounded-xl bg-gray-50 p-4">
                        <div class="text-2xl font-bold text-gray-900">
                            {{ stats.total }}
                        </div>
                        <div
                            class="text-xs font-semibold text-gray-400 uppercase"
                        >
                            Total
                        </div>
                    </div>
                    <div class="rounded-xl bg-amber-50 p-4">
                        <div class="text-2xl font-bold text-amber-600">
                            {{ stats.pending }}
                        </div>
                        <div
                            class="text-xs font-semibold text-amber-400 uppercase"
                        >
                            Pending
                        </div>
                    </div>
                    <div class="rounded-xl bg-red-50 p-4">
                        <div class="text-2xl font-bold text-red-600">
                            {{ stats.deleted }}
                        </div>
                        <div
                            class="text-xs font-semibold text-red-400 uppercase"
                        >
                            Deleted
                        </div>
                    </div>
                    <div class="rounded-xl bg-green-50 p-4">
                        <div class="text-2xl font-bold text-green-600">
                            {{ stats.cancelled }}
                        </div>
                        <div
                            class="text-xs font-semibold text-green-400 uppercase"
                        >
                            Cancelled
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div
                class="flex items-center gap-3 border-b border-gray-100 bg-white px-6 py-3"
            >
                <div class="flex gap-2">
                    <button
                        v-for="f in [
                            'all',
                            'pending',
                            'processing',
                            'deleted',
                            'cancelled',
                        ]"
                        :key="f"
                        @click="filter = f as any"
                        :class="[
                            'rounded-full px-4 py-1.5 text-xs font-bold uppercase transition-all',
                            filter === f
                                ? 'bg-gray-900 text-white'
                                : 'bg-gray-100 text-gray-500 hover:bg-gray-200',
                        ]"
                    >
                        {{ f }}
                    </button>
                </div>
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search by name, email, phone..."
                    class="ml-auto w-64 rounded-lg border border-gray-200 px-3 py-1.5 text-sm outline-none focus:border-gray-400"
                />
            </div>

            <!-- Main content -->
            <div class="flex flex-1 overflow-hidden">
                <!-- Table -->
                <div
                    :class="[
                        'flex flex-col overflow-auto transition-all',
                        showPanel ? 'w-1/2' : 'w-full',
                    ]"
                >
                    <div
                        v-if="loading"
                        class="flex items-center justify-center gap-2 py-20 text-gray-400"
                    >
                        <svg
                            class="h-5 w-5 animate-spin"
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
                        <span class="text-sm">Loading requests...</span>
                    </div>

                    <div
                        v-else-if="filteredRequests.length === 0"
                        class="flex flex-col items-center justify-center py-20 text-center"
                    >
                        <span class="text-5xl">🗑️</span>
                        <p class="mt-3 font-bold text-gray-900">
                            No requests found
                        </p>
                        <p class="text-sm text-gray-500">
                            No deletion requests match your current filter
                        </p>
                    </div>

                    <table v-else class="w-full table-auto text-left">
                        <thead>
                            <tr class="bg-gray-50">
                                <th
                                    class="border-y border-gray-100 p-4 text-xs font-semibold text-gray-500 uppercase"
                                >
                                    User
                                </th>
                                <th
                                    class="border-y border-gray-100 p-4 text-xs font-semibold text-gray-500 uppercase"
                                >
                                    Contact
                                </th>
                                <th
                                    class="border-y border-gray-100 p-4 text-xs font-semibold text-gray-500 uppercase"
                                >
                                    Reason
                                </th>
                                <th
                                    class="border-y border-gray-100 p-4 text-xs font-semibold text-gray-500 uppercase"
                                >
                                    Status
                                </th>
                                <th
                                    class="border-y border-gray-100 p-4 text-xs font-semibold text-gray-500 uppercase"
                                >
                                    Scheduled
                                </th>
                                <th
                                    class="border-y border-gray-100 p-4 text-xs font-semibold text-gray-500 uppercase"
                                >
                                    Processed by
                                </th>
                                <th
                                    class="border-y border-gray-100 p-2 text-xs font-semibold text-gray-500 uppercase"
                                >
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="req in filteredRequests"
                                :key="req.id"
                                @click="
                                    selectedRequest = req;
                                    showPanel = true;
                                "
                                :class="[
                                    'cursor-pointer border-b border-gray-50 transition-colors hover:bg-gray-50/50',
                                    selectedRequest?.id === req.id
                                        ? 'bg-blue-50/30'
                                        : '',
                                ]"
                            >
                                <td class="p-4">
                                    <div
                                        class="text-sm font-semibold text-gray-900"
                                    >
                                        {{ req.name }}
                                    </div>
                                    <div class="text-xs text-gray-400">
                                        {{ formatDate(req.requested_at) }}
                                    </div>
                                </td>
                                <td class="p-4">
                                    <div class="text-sm text-gray-600">
                                        {{ req.email }}
                                    </div>
                                    <div class="text-xs text-gray-400">
                                        {{ req.phone ?? '—' }}
                                    </div>
                                </td>
                                <td
                                    class="max-w-[160px] truncate p-4 text-sm text-gray-500"
                                >
                                    {{ req.reason?.replace(/_/g, ' ') ?? '—' }}
                                </td>
                                <td class="p-4">
                                    <span
                                        :class="[
                                            'rounded-full px-2.5 py-1 text-xs font-bold uppercase',
                                            statusConfig[req.status]?.classes,
                                        ]"
                                    >
                                        {{ statusConfig[req.status]?.label }}
                                    </span>
                                </td>
                                <td class="p-4">
                                    <div
                                        class="text-sm whitespace-nowrap text-gray-600"
                                    >
                                        {{
                                            formatDate(
                                                req.scheduled_deletion_at,
                                            )
                                        }}
                                    </div>
                                    <div
                                        v-if="
                                            req.status === 'pending' &&
                                            daysUntil(
                                                req.scheduled_deletion_at,
                                            ) !== null
                                        "
                                        :class="[
                                            'text-xs font-semibold',
                                            daysUntil(
                                                req.scheduled_deletion_at,
                                            )! <= 5
                                                ? 'text-red-500'
                                                : 'text-gray-400',
                                        ]"
                                    >
                                        {{
                                            daysUntil(req.scheduled_deletion_at)
                                        }}
                                        days left
                                    </div>
                                </td>
                                <td class="p-4 text-sm text-gray-500">
                                    <span
                                        v-if="
                                            req.processed_by_type === 'system'
                                        "
                                        class="flex items-center gap-1 text-xs text-gray-400"
                                    >
                                        🤖 System
                                    </span>
                                    <span
                                        v-else-if="
                                            req.processed_by_type === 'admin'
                                        "
                                        class="flex items-center gap-1 text-xs text-gray-400"
                                    >
                                        👤 {{ req.processor?.name ?? 'Admin' }}
                                    </span>
                                    <span v-else class="text-xs text-gray-300"
                                        >—</span
                                    >
                                </td>
                                <td class="p-2" @click.stop>
                                    <div class="flex items-center gap-1">
                                        <!-- Cancel (only for pending) -->
                                        <button
                                            v-if="req.status === 'pending'"
                                            @click="
                                                confirmAction = {
                                                    type: 'cancel',
                                                    request: req,
                                                }
                                            "
                                            class="rounded-lg p-2 text-green-600 transition-colors hover:bg-green-50"
                                            title="Cancel request & reactivate account"
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
                                                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                                                />
                                            </svg>
                                        </button>
                                        <!-- Manual delete (only for pending) -->
                                        <button
                                            v-if="req.status === 'pending'"
                                            @click="
                                                confirmAction = {
                                                    type: 'delete',
                                                    request: req,
                                                }
                                            "
                                            class="rounded-lg p-2 text-red-600 transition-colors hover:bg-red-50"
                                            title="Delete account now"
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

                    <!-- Pagination -->
                    <div
                        class="flex items-center justify-between border-t border-gray-100 p-4"
                    >
                        <span class="text-sm text-gray-500"
                            >Showing {{ pagination.from }} to
                            {{ pagination.to }} of {{ pagination.total }}</span
                        >
                        <div class="flex gap-1">
                            <template
                                v-for="(link, i) in pagination.links"
                                :key="i"
                            >
                                <button
                                    v-if="link.url"
                                    @click="load(link.url)"
                                    v-html="link.label"
                                    :class="[
                                        'min-w-[36px] rounded border px-2 py-1 text-xs transition-colors',
                                        link.active
                                            ? 'border-gray-900 bg-gray-900 text-white'
                                            : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50',
                                    ]"
                                />
                                <span
                                    v-else
                                    v-html="link.label"
                                    class="min-w-[36px] cursor-not-allowed rounded border border-gray-200 bg-gray-100 px-2 py-1 text-center text-xs text-gray-400"
                                />
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Detail Panel -->
                <transition name="panel">
                    <div
                        v-if="showPanel && selectedRequest"
                        class="w-1/2 overflow-auto border-l border-gray-100 bg-white"
                    >
                        <div
                            class="sticky top-0 z-10 flex items-center justify-between border-b border-gray-100 bg-white px-6 py-4"
                        >
                            <h2 class="font-bold text-gray-900">
                                Request #{{ selectedRequest.id }}
                            </h2>
                            <button
                                @click="showPanel = false"
                                class="rounded-lg p-2 text-gray-400 hover:bg-gray-100"
                            >
                                ✕
                            </button>
                        </div>

                        <div class="space-y-5 p-6">
                            <!-- Status -->
                            <div class="flex items-center gap-3">
                                <span
                                    :class="[
                                        'rounded-full px-3 py-1.5 text-sm font-bold',
                                        statusConfig[selectedRequest.status]
                                            ?.classes,
                                    ]"
                                >
                                    {{
                                        statusConfig[selectedRequest.status]
                                            ?.label
                                    }}
                                </span>
                                <span class="text-sm text-gray-400"
                                    >Submitted
                                    {{
                                        formatDate(selectedRequest.requested_at)
                                    }}</span
                                >
                            </div>

                            <!-- User info -->
                            <div class="space-y-1 rounded-xl bg-gray-50 p-4">
                                <p
                                    class="mb-2 text-xs font-bold text-gray-400 uppercase"
                                >
                                    Requestor
                                </p>
                                <p class="font-semibold text-gray-900">
                                    {{ selectedRequest.name }}
                                </p>
                                <p class="text-sm text-gray-500">
                                    {{ selectedRequest.email }}
                                </p>
                                <p class="text-sm text-gray-500">
                                    {{ selectedRequest.phone ?? '—' }}
                                </p>
                                <p
                                    v-if="selectedRequest.user_id"
                                    class="mt-1 text-xs text-gray-400"
                                >
                                    Linked account ID: #{{
                                        selectedRequest.user_id
                                    }}
                                </p>
                                <p v-else class="mt-1 text-xs text-amber-500">
                                    ⚠ No matching user account found
                                </p>
                            </div>

                            <!-- Reason & notes -->
                            <div class="rounded-xl bg-gray-50 p-4">
                                <p
                                    class="mb-2 text-xs font-bold text-gray-400 uppercase"
                                >
                                    Reason
                                </p>
                                <p class="text-sm text-gray-700 capitalize">
                                    {{
                                        selectedRequest.reason?.replace(
                                            /_/g,
                                            ' ',
                                        ) ?? 'Not specified'
                                    }}
                                </p>
                                <p
                                    v-if="selectedRequest.notes"
                                    class="mt-2 border-t border-gray-200 pt-2 text-sm text-gray-500 italic"
                                >
                                    "{{ selectedRequest.notes }}"
                                </p>
                            </div>

                            <!-- Deletion schedule -->
                            <div class="rounded-xl bg-gray-50 p-4">
                                <p
                                    class="mb-2 text-xs font-bold text-gray-400 uppercase"
                                >
                                    Deletion Schedule
                                </p>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-gray-500"
                                            >Requested</span
                                        >
                                        <span
                                            class="font-medium text-gray-700"
                                            >{{
                                                formatDate(
                                                    selectedRequest.requested_at,
                                                )
                                            }}</span
                                        >
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500"
                                            >Scheduled deletion</span
                                        >
                                        <span
                                            :class="[
                                                'font-medium',
                                                daysUntil(
                                                    selectedRequest.scheduled_deletion_at,
                                                )! <= 5 &&
                                                selectedRequest.status ===
                                                    'pending'
                                                    ? 'text-red-600'
                                                    : 'text-gray-700',
                                            ]"
                                        >
                                            {{
                                                formatDate(
                                                    selectedRequest.scheduled_deletion_at,
                                                )
                                            }}
                                        </span>
                                    </div>
                                    <div
                                        v-if="
                                            selectedRequest.status === 'pending'
                                        "
                                        class="flex justify-between"
                                    >
                                        <span class="text-gray-500"
                                            >Days remaining</span
                                        >
                                        <span
                                            :class="[
                                                'font-bold',
                                                daysUntil(
                                                    selectedRequest.scheduled_deletion_at,
                                                )! <= 5
                                                    ? 'text-red-600'
                                                    : 'text-gray-700',
                                            ]"
                                        >
                                            {{
                                                daysUntil(
                                                    selectedRequest.scheduled_deletion_at,
                                                )
                                            }}
                                            days
                                        </span>
                                    </div>
                                    <div
                                        v-if="selectedRequest.processed_at"
                                        class="flex justify-between"
                                    >
                                        <span class="text-gray-500"
                                            >Processed at</span
                                        >
                                        <span
                                            class="font-medium text-gray-700"
                                            >{{
                                                formatDate(
                                                    selectedRequest.processed_at,
                                                )
                                            }}</span
                                        >
                                    </div>
                                </div>
                            </div>

                            <!-- Processed by -->
                            <div class="rounded-xl bg-gray-50 p-4">
                                <p
                                    class="mb-2 text-xs font-bold text-gray-400 uppercase"
                                >
                                    Processed By
                                </p>
                                <p
                                    v-if="
                                        selectedRequest.processed_by_type ===
                                        'system'
                                    "
                                    class="text-sm text-gray-600"
                                >
                                    🤖 Automated system (cronjob)
                                </p>
                                <p
                                    v-else-if="
                                        selectedRequest.processed_by_type ===
                                        'admin'
                                    "
                                    class="text-sm text-gray-600"
                                >
                                    👤
                                    {{
                                        selectedRequest.processor?.name ??
                                        'Admin'
                                    }}
                                </p>
                                <p v-else class="text-sm text-gray-400">
                                    Not yet processed
                                </p>
                            </div>

                            <!-- Admin notes -->
                            <div
                                v-if="selectedRequest.admin_notes"
                                class="rounded-xl border border-amber-100 bg-amber-50 p-4"
                            >
                                <p
                                    class="mb-2 text-xs font-bold text-amber-500 uppercase"
                                >
                                    Admin Notes
                                </p>
                                <p class="text-sm text-amber-700">
                                    {{ selectedRequest.admin_notes }}
                                </p>
                            </div>

                            <!-- Actions -->
                            <div
                                v-if="selectedRequest.status === 'pending'"
                                class="flex gap-3 pt-2"
                            >
                                <button
                                    @click="
                                        confirmAction = {
                                            type: 'cancel',
                                            request: selectedRequest,
                                        }
                                    "
                                    class="flex-1 rounded-xl bg-green-600 py-3 text-sm font-bold text-white transition-colors hover:bg-green-700"
                                >
                                    ✓ Cancel & Reactivate
                                </button>
                                <button
                                    @click="
                                        confirmAction = {
                                            type: 'delete',
                                            request: selectedRequest,
                                        }
                                    "
                                    class="rounded-xl border border-red-200 px-5 py-3 text-sm font-bold text-red-600 transition-colors hover:bg-red-50"
                                >
                                    Delete Now
                                </button>
                            </div>
                        </div>
                    </div>
                </transition>
            </div>
        </div>

        <!-- Cancel confirmation modal -->
        <div
            v-if="confirmAction?.type === 'cancel'"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
        >
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <div class="mb-4 flex items-center gap-3">
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full bg-green-100"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-5 w-5 text-green-600"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                            />
                        </svg>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-900">
                            Cancel Deletion Request
                        </h3>
                        <p class="text-sm text-gray-500">
                            {{ confirmAction.request.name }} ·
                            {{ confirmAction.request.email }}
                        </p>
                    </div>
                </div>
                <div
                    class="mb-5 rounded-lg border border-green-100 bg-green-50 p-4 text-sm text-green-800"
                >
                    The deletion request will be cancelled and the account will
                    be reactivated. The user will be able to log in again.
                </div>
                <div class="flex justify-end gap-3">
                    <button
                        @click="confirmAction = null"
                        class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        Keep Request
                    </button>
                    <button
                        @click="proceedCancel"
                        :disabled="isProcessing"
                        class="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
                    >
                        {{
                            isProcessing
                                ? 'Processing...'
                                : 'Yes, Cancel Request'
                        }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Delete confirmation modal -->
        <div
            v-if="confirmAction?.type === 'delete'"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
        >
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <div class="mb-4 flex items-center gap-3">
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full bg-red-100"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-5 w-5 text-red-600"
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
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-900">
                            Delete Account Now
                        </h3>
                        <p class="text-sm text-gray-500">
                            {{ confirmAction.request.name }} ·
                            {{ confirmAction.request.email }}
                        </p>
                    </div>
                </div>
                <div
                    class="mb-5 rounded-lg border border-red-100 bg-red-50 p-4 text-sm text-red-800"
                >
                    <p class="mb-1 font-semibold">This will immediately:</p>
                    <ul class="list-inside list-disc space-y-1">
                        <li>Permanently delete all personal data</li>
                        <li>Revoke all active sessions and tokens</li>
                        <li>Remove the employee record</li>
                        <li>This cannot be undone</li>
                    </ul>
                </div>
                <div class="flex justify-end gap-3">
                    <button
                        @click="confirmAction = null"
                        class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        Cancel
                    </button>
                    <button
                        @click="proceedDelete"
                        :disabled="isProcessing"
                        class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
                    >
                        {{ isProcessing ? 'Deleting...' : 'Yes, Delete Now' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Flash toast -->
        <transition name="flash">
            <div
                v-if="flashMsg"
                :class="[
                    'fixed right-8 bottom-8 z-50 rounded-xl border-l-4 px-5 py-3 text-sm font-semibold text-white shadow-xl',
                    flashType === 'success'
                        ? 'border-green-400 bg-gray-900'
                        : 'border-red-400 bg-gray-900',
                ]"
            >
                {{ flashMsg }}
            </div>
        </transition>
    </AppLayout>
</template>

<style scoped>
.panel-enter-active,
.panel-leave-active {
    transition: all 0.25s ease;
}
.panel-enter-from,
.panel-leave-to {
    opacity: 0;
    transform: translateX(20px);
}
.flash-enter-active,
.flash-leave-active {
    transition: all 0.3s ease;
}
.flash-enter-from,
.flash-leave-to {
    opacity: 0;
    transform: translateY(8px);
}
</style>

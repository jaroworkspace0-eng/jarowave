<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';

const props = defineProps<{ id: string | number }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Clients', href: '/clients' },
    { title: 'Pairings', href: '#' },
];

// ── State ─────────────────────────────────────────────────────
const household = ref<any | null>(null);
const pairings = ref<any[]>([]);
const loading = ref(false);
const dissolving = ref<number | null>(null);
const showConfirmModal = ref(false);
const selectedPairing = ref<any | null>(null);
const filterStatus = ref<'all' | 'active' | 'pending' | 'dissolved'>('all');
const search = ref('');
const successMessage = ref('');
const errorMessage = ref('');

const token = computed(() => localStorage.getItem('token') ?? '');

// ── Stats ─────────────────────────────────────────────────────
const stats = computed(() => ({
    active: pairings.value.filter((p) => p.status === 'active').length,
    pending: pairings.value.filter((p) => p.status === 'pending').length,
    dissolved: pairings.value.filter((p) => p.status === 'dissolved').length,
    total: pairings.value.length,
}));

// ── Filtered list ─────────────────────────────────────────────
const filtered = computed(() => {
    let list = [...pairings.value];
    if (filterStatus.value !== 'all')
        list = list.filter((p) => p.status === filterStatus.value);
    if (search.value.trim()) {
        const q = search.value.toLowerCase();
        list = list.filter(
            (p) =>
                p.household?.name?.toLowerCase().includes(q) ||
                p.household?.address_line_1?.toLowerCase().includes(q) ||
                p.household?.suburb?.toLowerCase().includes(q) ||
                p.household?.phone?.toLowerCase().includes(q),
        );
    }
    return list;
});

// ── API ───────────────────────────────────────────────────────
async function loadData() {
    loading.value = true;
    errorMessage.value = '';
    try {
        const [householdRes, pairingsRes] = await Promise.all([
            axios.get(`${import.meta.env.VITE_APP_URL}/api/users/${props.id}`, {
                headers: { Authorization: `Bearer ${token.value}` },
            }),
            axios.get(
                `${import.meta.env.VITE_APP_URL}/api/households/${props.id}/pairings`,
                { headers: { Authorization: `Bearer ${token.value}` } },
            ),
        ]);
        household.value = householdRes.data;
        pairings.value = pairingsRes.data ?? [];
    } catch {
        errorMessage.value = 'Failed to load pairing data.';
    } finally {
        loading.value = false;
    }
}

function confirmDissolve(pairing: any) {
    selectedPairing.value = pairing;
    showConfirmModal.value = true;
}

async function dissolve() {
    if (!selectedPairing.value) return;
    dissolving.value = selectedPairing.value.id;
    errorMessage.value = '';
    try {
        await axios.delete(
            `${import.meta.env.VITE_APP_URL}/api/household-pairings/${selectedPairing.value.id}`,
            { headers: { Authorization: `Bearer ${token.value}` } },
        );
        showConfirmModal.value = false;
        selectedPairing.value = null;
        successMessage.value = 'Pairing dissolved successfully.';
        setTimeout(() => (successMessage.value = ''), 3000);
        await loadData();
    } catch {
        errorMessage.value = 'Failed to dissolve pairing.';
    } finally {
        dissolving.value = null;
    }
}

// ── Helpers ───────────────────────────────────────────────────
function formatDate(ts: string) {
    if (!ts) return '—';
    return new Date(ts).toLocaleString('en-ZA', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function timeAgo(ts: string) {
    if (!ts) return '—';
    const diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
    if (diff < 60) return 'Just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
}

function initials(name: string) {
    if (!name) return '?';
    return name
        .split(' ')
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('');
}

const statusClass = (s: string) =>
    ({
        active: 'bg-green-50 text-green-700 border border-green-200',
        pending: 'bg-orange-50 text-orange-700 border border-orange-200',
        dissolved: 'bg-gray-100 text-gray-500 border border-gray-200',
    })[s] ?? 'bg-gray-100 text-gray-500';

const directionClass = (d: string) =>
    d === 'sent'
        ? 'bg-blue-50 text-blue-700 border border-blue-200'
        : 'bg-purple-50 text-purple-700 border border-purple-200';

const avatarColor = (name: string) => {
    const colors = [
        'bg-indigo-100 text-indigo-600',
        'bg-pink-100 text-pink-600',
        'bg-teal-100 text-teal-600',
        'bg-amber-100 text-amber-600',
        'bg-cyan-100 text-cyan-600',
    ];
    const i = (name?.charCodeAt(0) ?? 0) % colors.length;
    return colors[i];
};

onMounted(loadData);
</script>

<template>
    <Head title="Household Pairings" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-6">
            <!-- ── Toast ──────────────────────────────────────── -->
            <Transition name="toast">
                <div
                    v-if="successMessage"
                    class="fixed top-6 right-6 z-50 flex items-center gap-2 rounded-xl border border-green-200 bg-green-50 px-4 py-3 shadow-lg"
                >
                    <svg
                        class="h-4 w-4 text-green-600"
                        fill="currentColor"
                        viewBox="0 0 20 20"
                    >
                        <path
                            fill-rule="evenodd"
                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                            clip-rule="evenodd"
                        />
                    </svg>
                    <span class="text-sm font-medium text-green-700">{{
                        successMessage
                    }}</span>
                </div>
            </Transition>

            <!-- ── Loading ────────────────────────────────────── -->
            <div v-if="loading" class="flex items-center justify-center py-32">
                <div
                    class="h-8 w-8 animate-spin rounded-full border-2 border-blue-600 border-t-transparent"
                ></div>
                <span class="ml-3 text-sm text-gray-500"
                    >Loading pairings…</span
                >
            </div>

            <template v-else>
                <!-- ── Household header ───────────────────────── -->
                <div
                    class="rounded-xl border border-gray-100 bg-white p-6 shadow"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-4"
                    >
                        <div class="flex items-center gap-4">
                            <div
                                :class="[
                                    'flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-xl text-xl font-bold',
                                    avatarColor(household?.name ?? ''),
                                ]"
                            >
                                {{ initials(household?.name ?? '') }}
                            </div>
                            <div>
                                <h1 class="text-lg font-bold text-gray-900">
                                    {{ household?.name ?? '—' }}
                                </h1>
                                <div
                                    class="mt-1 flex flex-wrap items-center gap-3 text-sm text-gray-500"
                                >
                                    <span
                                        v-if="household?.address_line_1"
                                        class="flex items-center gap-1"
                                    >
                                        <svg
                                            class="h-3.5 w-3.5 text-gray-400"
                                            fill="currentColor"
                                            viewBox="0 0 20 20"
                                        >
                                            <path
                                                fill-rule="evenodd"
                                                d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z"
                                                clip-rule="evenodd"
                                            />
                                        </svg>
                                        {{ household.address_line_1 }}
                                        <span v-if="household.suburb"
                                            >, {{ household.suburb }}</span
                                        >
                                    </span>
                                    <span
                                        v-if="household?.phone"
                                        class="flex items-center gap-1"
                                    >
                                        <svg
                                            class="h-3.5 w-3.5 text-gray-400"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"
                                            />
                                        </svg>
                                        {{ household.phone }}
                                    </span>
                                    <span
                                        class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 capitalize"
                                    >
                                        {{ household?.role ?? 'household' }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <button
                            @click="loadData"
                            class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-600 transition-colors hover:border-blue-300 hover:text-blue-600"
                        >
                            Refresh
                        </button>
                    </div>
                </div>

                <!-- ── Stats ──────────────────────────────────── -->
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div
                        class="rounded-xl border border-gray-100 bg-white p-5 shadow"
                    >
                        <p class="text-sm font-medium text-gray-500">
                            Total Pairings
                        </p>
                        <p class="mt-1 text-2xl font-bold text-gray-900">
                            {{ stats.total }}
                        </p>
                    </div>
                    <div
                        class="rounded-xl border border-green-100 bg-green-50 p-5 shadow"
                    >
                        <p class="text-sm font-medium text-green-600">
                            Active Guardians
                        </p>
                        <p class="mt-1 text-2xl font-bold text-green-700">
                            {{ stats.active }}
                        </p>
                    </div>
                    <div
                        class="rounded-xl border border-orange-100 bg-orange-50 p-5 shadow"
                    >
                        <p class="text-sm font-medium text-orange-600">
                            Pending Requests
                        </p>
                        <p class="mt-1 text-2xl font-bold text-orange-700">
                            {{ stats.pending }}
                        </p>
                    </div>
                    <div
                        class="rounded-xl border border-gray-100 bg-white p-5 shadow"
                    >
                        <p class="text-sm font-medium text-gray-500">
                            Dissolved
                        </p>
                        <p class="mt-1 text-2xl font-bold text-gray-900">
                            {{ stats.dissolved }}
                        </p>
                    </div>
                </div>

                <!-- ── Filters ────────────────────────────────── -->
                <div
                    class="rounded-xl border border-gray-100 bg-white p-5 shadow"
                >
                    <div class="flex flex-wrap items-center gap-3">
                        <!-- Search -->
                        <div class="relative min-w-[200px] flex-1">
                            <svg
                                class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-gray-400"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M21 21l-4.35-4.35M17 11A6 6 0 111 11a6 6 0 0116 0z"
                                />
                            </svg>
                            <input
                                v-model="search"
                                class="w-full rounded-lg border border-gray-200 py-2 pr-4 pl-9 text-sm focus:border-blue-400 focus:outline-none"
                                placeholder="Search by name, address, suburb…"
                            />
                        </div>

                        <!-- Status pills -->
                        <div class="flex items-center gap-2">
                            <button
                                v-for="opt in [
                                    { v: 'all', l: 'All' },
                                    { v: 'active', l: 'Active' },
                                    { v: 'pending', l: 'Pending' },
                                    { v: 'dissolved', l: 'Dissolved' },
                                ]"
                                :key="opt.v"
                                @click="filterStatus = opt.v as any"
                                :class="[
                                    'rounded-full px-3 py-1.5 text-xs font-semibold transition-colors',
                                    filterStatus === opt.v
                                        ? 'bg-blue-600 text-white'
                                        : 'border border-gray-200 text-gray-600 hover:border-blue-300 hover:text-blue-600',
                                ]"
                            >
                                {{ opt.l }}
                                <span
                                    v-if="opt.v !== 'all'"
                                    :class="[
                                        'ml-1 rounded-full px-1.5 py-0.5 text-[10px]',
                                        filterStatus === opt.v
                                            ? 'bg-white/20 text-white'
                                            : 'bg-gray-100 text-gray-500',
                                    ]"
                                >
                                    {{
                                        opt.v === 'active'
                                            ? stats.active
                                            : opt.v === 'pending'
                                              ? stats.pending
                                              : stats.dissolved
                                    }}
                                </span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── Error ──────────────────────────────────── -->
                <div
                    v-if="errorMessage"
                    class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700"
                >
                    {{ errorMessage }}
                </div>

                <!-- ── Empty ──────────────────────────────────── -->
                <div
                    v-if="filtered.length === 0"
                    class="flex flex-col items-center justify-center rounded-xl border border-gray-100 bg-white py-20 text-center shadow"
                >
                    <div
                        class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-gray-100"
                    >
                        <svg
                            class="h-8 w-8 text-gray-400"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.5"
                                d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"
                            />
                        </svg>
                    </div>
                    <p class="text-sm font-semibold text-gray-600">
                        No pairings found
                    </p>
                    <p class="mt-1 text-xs text-gray-400">
                        {{
                            filterStatus !== 'all'
                                ? 'Try changing the filter'
                                : 'This household has no guardian pairings yet'
                        }}
                    </p>
                </div>

                <!-- ── Pairing cards ──────────────────────────── -->
                <div
                    v-else
                    class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3"
                >
                    <div
                        v-for="pairing in filtered"
                        :key="pairing.id"
                        :class="[
                            'group rounded-xl border bg-white p-5 shadow transition-all hover:shadow-md',
                            pairing.status === 'active'
                                ? 'border-green-100'
                                : pairing.status === 'pending'
                                  ? 'border-orange-100'
                                  : 'border-gray-100 opacity-70',
                        ]"
                    >
                        <!-- Card header -->
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <div
                                    :class="[
                                        'flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl text-sm font-bold',
                                        avatarColor(
                                            pairing.household?.name ?? '',
                                        ),
                                    ]"
                                >
                                    {{
                                        initials(pairing.household?.name ?? '')
                                    }}
                                </div>
                                <div class="min-w-0">
                                    <p
                                        class="truncate font-semibold text-gray-900"
                                    >
                                        {{ pairing.household?.name ?? '—' }}
                                    </p>
                                    <p
                                        v-if="pairing.household?.suburb"
                                        class="truncate text-xs text-gray-400"
                                    >
                                        {{ pairing.household.suburb }}
                                    </p>
                                </div>
                            </div>
                            <!-- Status badge -->
                            <span
                                :class="[
                                    'flex-shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold capitalize',
                                    statusClass(pairing.status),
                                ]"
                            >
                                {{ pairing.status }}
                            </span>
                        </div>

                        <!-- Divider -->
                        <div class="my-4 border-t border-gray-100"></div>

                        <!-- Meta -->
                        <dl class="space-y-2">
                            <!-- Address -->
                            <div
                                v-if="pairing.household?.address_line_1"
                                class="flex items-start gap-2"
                            >
                                <svg
                                    class="mt-0.5 h-3.5 w-3.5 flex-shrink-0 text-gray-400"
                                    fill="currentColor"
                                    viewBox="0 0 20 20"
                                >
                                    <path
                                        fill-rule="evenodd"
                                        d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z"
                                        clip-rule="evenodd"
                                    />
                                </svg>
                                <span class="text-xs text-gray-600">
                                    {{ pairing.household.address_line_1 }}
                                    <span v-if="pairing.household.suburb"
                                        >, {{ pairing.household.suburb }}</span
                                    >
                                </span>
                            </div>

                            <!-- Phone -->
                            <div
                                v-if="pairing.household?.phone"
                                class="flex items-center gap-2"
                            >
                                <svg
                                    class="h-3.5 w-3.5 flex-shrink-0 text-gray-400"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"
                                    />
                                </svg>
                                <span class="text-xs text-gray-600">{{
                                    pairing.household.phone
                                }}</span>
                            </div>

                            <!-- Direction -->
                            <div class="flex items-center gap-2">
                                <svg
                                    class="h-3.5 w-3.5 flex-shrink-0 text-gray-400"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"
                                    />
                                </svg>
                                <span
                                    :class="[
                                        'rounded-full px-2 py-0.5 text-[10px] font-semibold capitalize',
                                        directionClass(pairing.direction),
                                    ]"
                                >
                                    {{
                                        pairing.direction === 'sent'
                                            ? 'Request sent'
                                            : 'Request received'
                                    }}
                                </span>
                            </div>

                            <!-- Dates -->
                            <div
                                class="flex items-center gap-2 text-xs text-gray-400"
                            >
                                <svg
                                    class="h-3.5 w-3.5"
                                    fill="currentColor"
                                    viewBox="0 0 20 20"
                                >
                                    <path
                                        fill-rule="evenodd"
                                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z"
                                        clip-rule="evenodd"
                                    />
                                </svg>
                                <span
                                    >Requested
                                    {{ timeAgo(pairing.requested_at) }}</span
                                >
                                <span
                                    v-if="pairing.responded_at"
                                    class="text-gray-300"
                                    >·</span
                                >
                                <span v-if="pairing.responded_at">
                                    Responded
                                    {{ timeAgo(pairing.responded_at) }}
                                </span>
                            </div>
                        </dl>

                        <!-- Actions -->
                        <div
                            v-if="pairing.status !== 'dissolved'"
                            class="mt-4 flex items-center justify-end gap-2 border-t border-gray-100 pt-4"
                        >
                            <button
                                @click="confirmDissolve(pairing)"
                                class="flex items-center gap-1.5 rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 transition-colors hover:bg-red-50"
                            >
                                <svg
                                    class="h-3.5 w-3.5"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM21 12h-6"
                                    />
                                </svg>
                                Dissolve Pairing
                            </button>
                        </div>

                        <!-- Dissolved info -->
                        <div v-else class="mt-4 border-t border-gray-100 pt-4">
                            <p class="text-xs text-gray-400">
                                Dissolved {{ timeAgo(pairing.dissolved_at) }}
                            </p>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- ── Dissolve Confirm Modal ──────────────────────────── -->
        <Teleport to="body">
            <Transition name="modal">
                <div
                    v-if="showConfirmModal && selectedPairing"
                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm"
                    @click.self="showConfirmModal = false"
                >
                    <div
                        class="w-full max-w-sm rounded-2xl bg-white shadow-2xl"
                    >
                        <!-- Header -->
                        <div class="px-6 pt-6 pb-2">
                            <div
                                class="flex h-12 w-12 items-center justify-center rounded-full bg-red-100"
                            >
                                <svg
                                    class="h-6 w-6 text-red-600"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM21 12h-6"
                                    />
                                </svg>
                            </div>
                            <h2 class="mt-4 text-base font-bold text-gray-900">
                                Dissolve Pairing
                            </h2>
                            <p class="mt-1 text-sm text-gray-500">
                                Are you sure you want to dissolve the pairing
                                between
                                <span class="font-semibold text-gray-700">{{
                                    household?.name
                                }}</span>
                                and
                                <span class="font-semibold text-gray-700">{{
                                    selectedPairing.household?.name
                                }}</span
                                >? This cannot be undone.
                            </p>
                        </div>

                        <!-- Warning -->
                        <div
                            class="mx-6 mt-4 rounded-lg border border-orange-100 bg-orange-50 p-3 text-xs text-orange-700"
                        >
                            Once dissolved, neither household will receive
                            guardian alerts for each other. They would need to
                            send a new pair request to reconnect.
                        </div>

                        <!-- Footer -->
                        <div
                            class="flex items-center justify-end gap-3 px-6 py-5"
                        >
                            <button
                                @click="showConfirmModal = false"
                                class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-50"
                            >
                                Cancel
                            </button>
                            <button
                                @click="dissolve"
                                :disabled="dissolving !== null"
                                class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-red-700 disabled:opacity-50"
                            >
                                <span v-if="dissolving !== null"
                                    >Dissolving…</span
                                >
                                <span v-else>Yes, Dissolve</span>
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AppLayout>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.2s;
}
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
.modal-enter-active .rounded-2xl,
.modal-leave-active .rounded-2xl {
    transition:
        transform 0.2s ease,
        opacity 0.2s;
}
.modal-enter-from .rounded-2xl,
.modal-leave-to .rounded-2xl {
    transform: scale(0.96) translateY(10px);
    opacity: 0;
}
.toast-enter-active,
.toast-leave-active {
    transition:
        opacity 0.3s,
        transform 0.3s;
}
.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateY(-8px);
}
</style>

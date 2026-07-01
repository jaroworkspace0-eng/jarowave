<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { onMounted, ref } from 'vue';

const auth = useAuthStore();

onMounted(() => {
    if (auth.user?.role !== 'admin') {
        router.visit('/dashboard'); // redirect non-admins away
    }
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Payment Simulator', href: '/admin/simulate-payment' },
];

interface HouseholdUser {
    id: number;
    name: string;
    email: string;
    subscription_status?: string;
}

interface LogEntry {
    time: string;
    type: string;
    success: boolean;
    user: string;
    subscription: string;
    results: Record<string, any>;
    error?: string;
}

const users = ref<HouseholdUser[]>([]);
const selectedUser = ref<HouseholdUser | null>(null);
const loading = ref(false);
const loadingType = ref('');
const logs = ref<LogEntry[]>([]);

const token = localStorage.getItem('token');

onMounted(async () => {
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/admin/simulate-payment/users`,
            { headers: { Authorization: `Bearer ${token}` } },
        );
        users.value = data;
    } catch (e) {
        console.error('Failed to load users:', e);
    }
});

function selectUser(e: Event) {
    const id = Number((e.target as HTMLSelectElement).value);
    selectedUser.value = users.value.find((u) => u.id === id) ?? null;
}

const simulations = [
    {
        type: 'complete',
        label: 'Payment Successful',
        badge: 'COMPLETE',
        badgeClass: 'bg-green-50 text-green-700 border-green-200',
        btnClass: 'bg-green-600 hover:bg-green-700',
        iconBg: 'bg-green-50 border-green-200',
        iconColor: '#16a34a',
        desc: 'Activates subscription · creates payment record · creates earning & invoice · queues success email · re-enables SOS',
        icon: `<polyline points="20 6 9 17 4 12"/>`,
    },
    {
        type: 'failed',
        label: 'Payment Failed',
        badge: 'FAILED',
        badgeClass: 'bg-red-50 text-red-700 border-red-200',
        btnClass: 'bg-red-600 hover:bg-red-700',
        iconBg: 'bg-red-50 border-red-200',
        iconColor: '#dc2626',
        desc: 'Sets past_due · creates failed payment record · starts 24h grace period · sends push notification + email',
        icon: `<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>`,
    },
    {
        type: 'suspended',
        label: 'Grace Period Expired',
        badge: 'SUSPENDED',
        badgeClass: 'bg-red-50 text-red-800 border-red-300',
        btnClass: 'bg-red-900 hover:bg-red-800',
        iconBg: 'bg-red-50 border-red-200',
        iconColor: '#991b1b',
        desc: 'Skips grace period entirely · suspends SOS immediately · sends push notification to device',
        icon: `<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>`,
    },
    {
        type: 'cancelled',
        label: 'Subscription Cancelled',
        badge: 'CANCELLED',
        badgeClass: 'bg-gray-100 text-gray-600 border-gray-200',
        btnClass: 'bg-gray-600 hover:bg-gray-700',
        iconBg: 'bg-gray-50 border-gray-200',
        iconColor: '#6b7280',
        desc: 'Sets cancelled · creates cancellation record · queues cancellation email · SOS suspends at period end',
        icon: `<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>`,
    },
    {
        type: 'resolved',
        label: 'Payment Resolved',
        badge: 'RESOLVED',
        badgeClass: 'bg-blue-50 text-blue-700 border-blue-200',
        btnClass: 'bg-blue-600 hover:bg-blue-700',
        iconBg: 'bg-blue-50 border-blue-200',
        iconColor: '#2563eb',
        desc: 'Clears all failures · creates recovery payment record · creates earning & invoice · re-enables SOS instantly',
        icon: `<polyline points="20 6 9 17 4 12"/>`,
    },
];

async function simulate(type: string) {
    if (!selectedUser.value) return;
    loading.value = true;
    loadingType.value = type;

    try {
        const { data } = await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/admin/simulate-payment`,
            {
                type,
                user_id: selectedUser.value.id,
                user_name: selectedUser.value.name,
                user_email: selectedUser.value.email,
            },
            { headers: { Authorization: `Bearer ${token}` } },
        );

        logs.value.unshift({
            time: new Date().toLocaleTimeString(),
            type,
            success: data.success,
            user: data.user,
            subscription: data.subscription,
            results: data.results,
        });

        // Update user subscription status in dropdown
        const u = users.value.find((u) => u.id === selectedUser.value?.id);
        if (u) u.subscription_status = data.subscription;
    } catch (e: any) {
        logs.value.unshift({
            time: new Date().toLocaleTimeString(),
            type,
            success: false,
            user: selectedUser.value?.name ?? '—',
            subscription: '—',
            results: {},
            error: e.response?.data?.message ?? e.message,
        });
    } finally {
        loading.value = false;
        loadingType.value = '';
    }
}

const badgeColors: Record<string, string> = {
    complete: 'bg-green-50 text-green-700',
    failed: 'bg-red-50 text-red-700',
    suspended: 'bg-red-100 text-red-800',
    cancelled: 'bg-gray-100 text-gray-600',
    resolved: 'bg-blue-50 text-blue-700',
};
</script>

<template>
    <Head title="Payment Simulator" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-3xl space-y-6 p-6">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <div class="mb-1 flex items-center gap-2">
                        <span
                            class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2.5 py-0.5 text-xs font-bold tracking-widest text-amber-600 uppercase"
                        >
                            Dev Tool
                        </span>
                    </div>
                    <h1 class="text-xl font-bold tracking-tight text-gray-900">
                        Payment Simulator
                    </h1>
                    <p class="mt-0.5 text-sm text-gray-400">
                        Simulate PayFast / Ozow webhook events without real
                        transactions.
                    </p>
                </div>
                <span
                    class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-bold tracking-widest text-gray-500 uppercase"
                >
                    {{ $page.props.ziggy?.environment ?? 'local' }}
                </span>
            </div>

            <!-- User selector -->
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <label
                    class="mb-3 block text-xs font-bold tracking-widest text-gray-400 uppercase"
                >
                    Household member to simulate
                </label>
                <select
                    class="w-full rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-800 focus:border-transparent focus:ring-2 focus:ring-orange-400 focus:outline-none"
                    @change="selectUser"
                >
                    <option value="">Select a household member...</option>
                    <option
                        v-for="user in users"
                        :key="user.id"
                        :value="user.id"
                    >
                        {{ user.name }} — {{ user.email }}
                        <template v-if="user.subscription_status">
                            ({{ user.subscription_status }})</template
                        >
                    </option>
                </select>

                <!-- Selected user info -->
                <div v-if="selectedUser" class="mt-4 grid grid-cols-3 gap-3">
                    <div class="rounded-xl bg-gray-50 p-3">
                        <div
                            class="mb-1 text-xs tracking-wider text-gray-400 uppercase"
                        >
                            Name
                        </div>
                        <div
                            class="truncate text-sm font-semibold text-gray-800"
                        >
                            {{ selectedUser.name }}
                        </div>
                    </div>
                    <div class="rounded-xl bg-gray-50 p-3">
                        <div
                            class="mb-1 text-xs tracking-wider text-gray-400 uppercase"
                        >
                            Email
                        </div>
                        <div
                            class="truncate text-sm font-semibold text-gray-800"
                        >
                            {{ selectedUser.email }}
                        </div>
                    </div>
                    <div class="rounded-xl bg-gray-50 p-3">
                        <div
                            class="mb-1 text-xs tracking-wider text-gray-400 uppercase"
                        >
                            Sub status
                        </div>
                        <div class="text-sm font-semibold text-gray-800">
                            {{ selectedUser.subscription_status ?? '—' }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Simulation cards -->
            <div class="space-y-3">
                <div
                    v-for="sim in simulations"
                    :key="sim.type"
                    class="flex items-center justify-between gap-4 rounded-xl border border-gray-200 bg-white p-5"
                >
                    <div class="flex items-center gap-4">
                        <div
                            :class="[
                                'flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl border',
                                sim.iconBg,
                            ]"
                        >
                            <svg
                                width="16"
                                height="16"
                                viewBox="0 0 24 24"
                                fill="none"
                                :stroke="sim.iconColor"
                                stroke-width="2.5"
                                v-html="sim.icon"
                            />
                        </div>
                        <div>
                            <div class="mb-0.5 flex items-center gap-2">
                                <span
                                    :class="[
                                        'inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-bold',
                                        sim.badgeClass,
                                    ]"
                                >
                                    {{ sim.badge }}
                                </span>
                                <span
                                    class="text-sm font-semibold text-gray-900"
                                    >{{ sim.label }}</span
                                >
                            </div>
                            <p class="text-xs text-gray-400">{{ sim.desc }}</p>
                        </div>
                    </div>
                    <button
                        :class="[
                            'flex-shrink-0 rounded-xl px-5 py-2.5 text-xs font-bold text-white transition-all active:scale-95 disabled:cursor-not-allowed disabled:opacity-50',
                            sim.btnClass,
                        ]"
                        :disabled="!selectedUser || loading"
                        @click="simulate(sim.type)"
                    >
                        <span
                            v-if="loading && loadingType === sim.type"
                            class="flex items-center gap-2"
                        >
                            <svg
                                class="h-3 w-3 animate-spin"
                                viewBox="0 0 24 24"
                                fill="none"
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
                                    d="M4 12a8 8 0 018-8v8z"
                                />
                            </svg>
                            Running
                        </span>
                        <span v-else>Run</span>
                    </button>
                </div>
            </div>

            <!-- Response log -->
            <div class="overflow-hidden rounded-xl bg-gray-900">
                <div
                    class="flex items-center justify-between border-b border-gray-800 px-5 py-3"
                >
                    <span
                        class="text-xs font-bold tracking-widest text-gray-400 uppercase"
                        >Response log</span
                    >
                    <button
                        class="text-xs text-gray-600 transition hover:text-gray-400"
                        @click="logs = []"
                    >
                        Clear
                    </button>
                </div>

                <div class="min-h-28 space-y-3 px-5 py-4 font-mono text-xs">
                    <div v-if="logs.length === 0" class="text-gray-600">
                        No simulations run yet...
                    </div>

                    <div
                        v-for="(log, i) in logs"
                        :key="i"
                        class="border-b border-gray-800 pb-3 last:border-0 last:pb-0"
                    >
                        <div class="mb-1 flex items-center gap-2">
                            <span
                                :class="[
                                    'h-2 w-2 flex-shrink-0 rounded-full',
                                    log.success ? 'bg-green-500' : 'bg-red-500',
                                ]"
                            />
                            <span
                                :class="[
                                    'font-bold',
                                    badgeColors[log.type]
                                        ? ''
                                        : 'text-gray-300',
                                ]"
                            >
                                {{ log.type.toUpperCase() }}
                            </span>
                            <span class="text-gray-600">{{ log.time }}</span>
                            <span class="text-gray-500"
                                >→ {{ log.success ? 'OK' : 'FAILED' }}</span
                            >
                        </div>
                        <div class="ml-4 text-gray-500">
                            User: {{ log.user }} · Sub: {{ log.subscription }}
                        </div>
                        <div
                            v-if="
                                log.results && Object.keys(log.results).length
                            "
                            class="mt-1 ml-4 text-gray-600"
                        >
                            {{ JSON.stringify(log.results) }}
                        </div>
                        <div v-if="log.error" class="mt-1 ml-4 text-red-400">
                            {{ log.error }}
                        </div>
                    </div>
                </div>
            </div>

            <p class="text-center text-xs text-gray-300">
                Echo Link Payment Simulator · Not available in production
            </p>
        </div>
    </AppLayout>
</template>

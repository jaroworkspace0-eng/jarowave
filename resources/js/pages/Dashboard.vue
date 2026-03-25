<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { useAuthStore } from '@/stores/auth';
import { type BreadcrumbItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';

import {
    ArcElement,
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Filler,
    Legend,
    LinearScale,
    LineElement,
    PointElement,
    Title,
    Tooltip,
} from 'chart.js';
import { computed, onMounted, ref } from 'vue';
import { Bar, Doughnut, Line } from 'vue-chartjs';
const auth = useAuthStore();

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    ArcElement,
    Title,
    Tooltip,
    Legend,
    Filler,
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard().url },
];

const stats = ref({
    channelsCount: 0,
    employeesCount: 0,
    clientsCount: 0,
    onlineCount: 0,
    offlineCount: 0,
    activeEmergencies: 0,
    employeesPerClient: [] as { name: string; count: number }[],
    onlineHistory: [] as { time: string; online: number; offline: number }[],
    announcementsHistory: [] as { date: string; count: number }[],
    channelActivity: [] as { name: string; members: number }[],
    peakHours: [] as { hour: string; count: number }[],
    recentActivity: [] as { name: string; status: string; logged_at: string }[],
});

onMounted(async () => {
    const { data } = await axios.get(
        `${import.meta.env.VITE_APP_URL}/api/dashboard`,
        {
            headers: {
                Authorization: `Bearer ${localStorage.getItem('token')}`,
            },
        },
    );
    console.log('dashboard:', data);
    stats.value = data.stats;
});

// const metrics = computed(() => [
//     {
//         label: 'Channels',
//         value: stats.value.channelsCount.toLocaleString(),
//         icon: '🎯',
//         href: '/channels',
//     },
//     {
//         label: 'Personnel',
//         value: stats.value.employeesCount.toLocaleString(),
//         icon: '🎫',
//         href: '/employees',
//     },
//     {
//         label: 'Clients',
//         value: stats.value.clientsCount.toLocaleString(),
//         icon: '🧑‍💼',
//         href: '/clients',
//     },
//     {
//         label: 'Online Now',
//         value: stats.value.onlineCount.toLocaleString(),
//         icon: '🟢',
//         href: '/employees?status=online',
//     },
//     {
//         label: 'Offline',
//         value: stats.value.offlineCount.toLocaleString(),
//         icon: '⚪',
//         href: '/employees?status=offline',
//     },
//     {
//         label: 'Emergencies',
//         value: stats.value.activeEmergencies.toLocaleString(),
//         icon: '🚨',
//         href: '/emergencies',
//         emergency: true,
//     },
// ]);

const metrics = computed(() => {
    const all = [
        {
            label: 'Channels',
            value: stats.value.channelsCount.toLocaleString(),
            icon: '🎯',
            href: '/channels',
        },
        {
            label: 'Personnel',
            value: stats.value.employeesCount.toLocaleString(),
            icon: '🎫',
            href: '/employees',
        },
        {
            label: 'Clients',
            value: stats.value.clientsCount.toLocaleString(),
            icon: '🧑‍💼',
            href: '/clients',
        },
        {
            label: 'Online Now',
            value: stats.value.onlineCount.toLocaleString(),
            icon: '🟢',
            href: '/employees?status=online',
        },
        {
            label: 'Offline',
            value: stats.value.offlineCount.toLocaleString(),
            icon: '⚪',
            href: '/employees?status=offline',
        },
        {
            label: 'Emergencies',
            value: stats.value.activeEmergencies.toLocaleString(),
            icon: '🚨',
            href: '/emergencies',
            emergency: true,
        },
    ];

    return all.filter(
        (m) => !(m.label === 'Clients' && auth.user?.role === 'client'),
    );
});

const onlineOfflineChart = computed(() => ({
    data: {
        labels: ['Online', 'Offline'],
        datasets: [
            {
                data: [stats.value.onlineCount, stats.value.offlineCount],
                backgroundColor: ['#22c55e', '#e5e7eb'],
                borderColor: ['#16a34a', '#d1d5db'],
                borderWidth: 2,
            },
        ],
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom' as const },
            title: {
                display: true,
                text: 'Online vs Offline',
                font: { size: 14, weight: 'bold' as const },
            },
        },
        cutout: '65%',
    },
}));

const onlineHistoryChart = computed(() => ({
    data: {
        labels: stats.value.onlineHistory.map((h) => h.time),
        datasets: [
            {
                label: 'Online',
                data: stats.value.onlineHistory.map((h) => h.online),
                borderColor: '#22c55e',
                backgroundColor: 'rgba(34,197,94,0.1)',
                fill: true,
                tension: 0.4,
            },
            {
                label: 'Offline',
                data: stats.value.onlineHistory.map((h) => h.offline),
                borderColor: '#94a3b8',
                backgroundColor: 'rgba(148,163,184,0.1)',
                fill: true,
                tension: 0.4,
            },
        ],
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom' as const },
            title: {
                display: true,
                text: 'Personnel Activity (Last 7 Days)',
                font: { size: 14, weight: 'bold' as const },
            },
        },
        scales: { y: { beginAtZero: true } },
    },
}));

const employeesPerClientChart = computed(() => ({
    data: {
        labels: stats.value.employeesPerClient.map((c) => c.name),
        datasets: [
            {
                label: 'Personnel',
                data: stats.value.employeesPerClient.map((c) => c.count),
                backgroundColor: 'rgba(99,102,241,0.7)',
                borderColor: '#6366f1',
                borderWidth: 2,
                borderRadius: 6,
            },
        ],
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            title: {
                display: true,
                text: 'Personnel per Client',
                font: { size: 14, weight: 'bold' as const },
            },
        },
        scales: { y: { beginAtZero: true } },
    },
}));

const announcementsChart = computed(() => ({
    data: {
        labels: stats.value.announcementsHistory.map((a) => a.date),
        datasets: [
            {
                label: 'Announcements',
                data: stats.value.announcementsHistory.map((a) => a.count),
                backgroundColor: 'rgba(245,158,11,0.7)',
                borderColor: '#f59e0b',
                borderWidth: 2,
                borderRadius: 6,
            },
        ],
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            title: {
                display: true,
                text: 'Announcements Sent (Last 30 Days)',
                font: { size: 14, weight: 'bold' as const },
            },
        },
        scales: { y: { beginAtZero: true } },
    },
}));

const channelActivityChart = computed(() => ({
    data: {
        labels: stats.value.channelActivity.map((c) => c.name),
        datasets: [
            {
                label: 'Members',
                data: stats.value.channelActivity.map((c) => c.members),
                backgroundColor: 'rgba(14,165,233,0.7)',
                borderColor: '#0ea5e9',
                borderWidth: 2,
                borderRadius: 6,
            },
        ],
    },
    options: {
        indexAxis: 'y' as const,
        responsive: true,
        plugins: {
            legend: { display: false },
            title: {
                display: true,
                text: 'Members per Channel',
                font: { size: 14, weight: 'bold' as const },
            },
        },
        scales: { x: { beginAtZero: true } },
    },
}));

// NEW: Peak hours chart
const peakHoursChart = computed(() => ({
    data: {
        labels: stats.value.peakHours.map((h) => h.hour),
        datasets: [
            {
                label: 'Connections',
                data: stats.value.peakHours.map((h) => h.count),
                backgroundColor: 'rgba(168,85,247,0.7)',
                borderColor: '#a855f7',
                borderWidth: 2,
                borderRadius: 6,
            },
        ],
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            title: {
                display: true,
                text: 'Peak Activity Hours (Last 30 Days)',
                font: { size: 14, weight: 'bold' as const },
            },
        },
        scales: { y: { beginAtZero: true } },
    },
}));

function timeAgo(ts: string) {
    const diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
    if (diff < 60) return 'Just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
}

const gridCols = computed(() => {
    const count = metrics.value.length;
    const map: Record<number, string> = {
        4: 'lg:grid-cols-4',
        5: 'lg:grid-cols-5',
        6: 'lg:grid-cols-6',
    };
    return map[count] ?? 'lg:grid-cols-5';
});
</script>

<template>
    <Head title="Dashboard" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <!-- Stat cards -->
        <div :class="['grid grid-cols-1 gap-6 p-6 sm:grid-cols-2', gridCols]">
            <Link
                v-for="metric in metrics"
                :key="metric.label"
                :href="metric.href"
                :class="[
                    'group flex items-center justify-between rounded-lg border p-6 shadow transition-all active:scale-95',
                    metric.emergency && stats.activeEmergencies > 0
                        ? 'border-red-300 bg-red-50 hover:border-red-400 hover:shadow-md'
                        : 'border-gray-100 bg-white hover:border-blue-300 hover:shadow-md',
                ]"
            >
                <div>
                    <h3
                        :class="[
                            'text-sm font-medium transition-colors',
                            metric.emergency && stats.activeEmergencies > 0
                                ? 'text-red-600'
                                : 'text-gray-500 group-hover:text-blue-600',
                        ]"
                    >
                        {{ metric.label }}
                    </h3>
                    <p
                        :class="[
                            'text-2xl font-bold',
                            metric.emergency && stats.activeEmergencies > 0
                                ? 'text-red-700'
                                : 'text-gray-900',
                        ]"
                    >
                        {{ metric.value }}
                    </p>
                </div>
                <div
                    :class="[
                        'rounded-full p-3 transition-colors',
                        metric.label === 'Online Now'
                            ? 'bg-green-50 group-hover:bg-green-100'
                            : metric.emergency && stats.activeEmergencies > 0
                              ? 'bg-red-100'
                              : 'bg-gray-50 group-hover:bg-blue-50',
                    ]"
                >
                    <span
                        :class="[
                            'text-2xl',
                            metric.label === 'Online Now' ||
                            (metric.emergency && stats.activeEmergencies > 0)
                                ? 'animate-pulse'
                                : '',
                        ]"
                    >
                        {{ metric.icon }}
                    </span>
                </div>
            </Link>
        </div>

        <!-- Charts row 1 -->
        <div class="grid grid-cols-1 gap-6 px-6 pb-6 lg:grid-cols-3">
            <div
                class="flex items-center justify-center rounded-xl border border-gray-100 bg-white p-6 shadow"
            >
                <Doughnut
                    :data="onlineOfflineChart.data"
                    :options="onlineOfflineChart.options"
                />
            </div>
            <div
                class="rounded-xl border border-gray-100 bg-white p-6 shadow lg:col-span-2"
            >
                <Line
                    :data="onlineHistoryChart.data"
                    :options="onlineHistoryChart.options"
                />
            </div>
        </div>

        <!-- Charts row 2 -->
        <div class="grid grid-cols-1 gap-6 px-6 pb-6 lg:grid-cols-2">
            <div class="rounded-xl border border-gray-100 bg-white p-6 shadow">
                <Bar
                    :data="employeesPerClientChart.data"
                    :options="employeesPerClientChart.options"
                />
            </div>
            <div class="rounded-xl border border-gray-100 bg-white p-6 shadow">
                <Bar
                    :data="announcementsChart.data"
                    :options="announcementsChart.options"
                />
            </div>
        </div>

        <!-- Charts row 3 -->
        <div class="grid grid-cols-1 gap-6 px-6 pb-6 lg:grid-cols-2">
            <div class="rounded-xl border border-gray-100 bg-white p-6 shadow">
                <Bar
                    :data="channelActivityChart.data"
                    :options="channelActivityChart.options"
                />
            </div>
            <!-- NEW: Peak hours -->
            <div class="rounded-xl border border-gray-100 bg-white p-6 shadow">
                <Bar
                    :data="peakHoursChart.data"
                    :options="peakHoursChart.options"
                />
            </div>
        </div>

        <!-- NEW: Recent activity feed -->
        <div class="px-6 pb-6">
            <div class="rounded-xl border border-gray-100 bg-white p-6 shadow">
                <h3 class="mb-4 text-sm font-bold text-gray-700">
                    Recent Activity
                </h3>
                <div
                    v-if="stats.recentActivity.length === 0"
                    class="py-8 text-center text-sm text-gray-400"
                >
                    No activity yet
                </div>
                <ul v-else class="divide-y divide-gray-50">
                    <li
                        v-for="(activity, i) in stats.recentActivity"
                        :key="i"
                        class="flex items-center justify-between py-3"
                    >
                        <div class="flex items-center gap-3">
                            <span
                                :class="[
                                    'h-2.5 w-2.5 flex-shrink-0 rounded-full',
                                    activity.status === 'online'
                                        ? 'bg-green-500'
                                        : 'bg-gray-300',
                                ]"
                            ></span>
                            <span class="text-sm font-medium text-gray-800">{{
                                activity.name
                            }}</span>
                            <span
                                :class="[
                                    'rounded-full px-2 py-0.5 text-xs font-semibold',
                                    activity.status === 'online'
                                        ? 'bg-green-50 text-green-700'
                                        : 'bg-gray-100 text-gray-500',
                                ]"
                            >
                                {{
                                    activity.status === 'online'
                                        ? 'Connected'
                                        : 'Disconnected'
                                }}
                            </span>
                        </div>
                        <span class="text-xs text-gray-400">{{
                            timeAgo(activity.logged_at)
                        }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>

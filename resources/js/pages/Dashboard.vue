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

const loading = ref(true);

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
    recentActivity: [] as {
        name: string;
        status: string;
        logged_at: string;
        channel: string | null;
    }[],
});

onMounted(async () => {
    loading.value = true;
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/dashboard`,
            {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                },
            },
        );
        stats.value = data.stats;
    } catch (e) {
        console.error('[Dashboard]', e);
    } finally {
        loading.value = false;
    }
});

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

const gridCols = computed(() => {
    const count = metrics.value.length;
    const map: Record<number, string> = {
        4: 'grid-cols-4',
        5: 'grid-cols-5',
        6: 'grid-cols-6',
    };
    return map[count] ?? 'grid-cols-5';
});

const fontFamily = "'DM Sans', system-ui, sans-serif";

const baseFont = {
    family: fontFamily,
    weight: 600,
};

const onlineOfflineChart = computed(() => ({
    data: {
        labels: ['Online', 'Offline'],
        datasets: [
            {
                data: [stats.value.onlineCount, stats.value.offlineCount],
                backgroundColor: ['#ea580c', '#e4e8ef'],
                borderColor: ['#c2410c', '#cbd5e1'],
                borderWidth: 2,
            },
        ],
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                position: 'bottom' as const,
                labels: { font: baseFont, color: '#64748b' },
            },
            title: {
                display: true,
                text: 'Online vs Offline',
                font: { size: 13, weight: 'bold' as const, family: fontFamily },
                color: '#1a2332',
                padding: { bottom: 14 },
            },
        },
        cutout: '68%',
    },
}));

const onlineHistoryChart = computed(() => ({
    data: {
        labels: stats.value.onlineHistory.map((h) => h.time),
        datasets: [
            {
                label: 'Online',
                data: stats.value.onlineHistory.map((h) => h.online),
                borderColor: '#ea580c',
                backgroundColor: 'rgba(234,88,12,0.08)',
                fill: true,
                tension: 0.4,
                borderWidth: 2,
                pointRadius: 0,
            },
            {
                label: 'Offline',
                data: stats.value.onlineHistory.map((h) => h.offline),
                borderColor: '#94a3b8',
                backgroundColor: 'rgba(148,163,184,0.06)',
                fill: true,
                tension: 0.4,
                borderWidth: 2,
                pointRadius: 0,
            },
        ],
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                position: 'bottom' as const,
                labels: { font: baseFont, color: '#64748b' },
            },
            title: {
                display: true,
                text: 'Personnel Activity (Last 7 Days)',
                font: { size: 13, weight: 'bold' as const, family: fontFamily },
                color: '#1a2332',
                padding: { bottom: 14 },
            },
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: '#f1f5f9' },
                ticks: { color: '#94a3b8', font: { family: fontFamily } },
            },
            x: {
                grid: { display: false },
                ticks: { color: '#94a3b8', font: { family: fontFamily } },
            },
        },
    },
}));

const employeesPerClientChart = computed(() => ({
    data: {
        labels: stats.value.employeesPerClient.map((c) => c.name),
        datasets: [
            {
                label: 'Personnel',
                data: stats.value.employeesPerClient.map((c) => c.count),
                backgroundColor: 'rgba(234,88,12,0.75)',
                borderColor: '#ea580c',
                borderWidth: 2,
                borderRadius: 6,
                maxBarThickness: 34,
            },
        ],
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { display: false },
            title: {
                display: true,
                text: 'Personnel per Client',
                font: { size: 13, weight: 'bold' as const, family: fontFamily },
                color: '#1a2332',
                padding: { bottom: 14 },
            },
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: '#f1f5f9' },
                ticks: { color: '#94a3b8', font: { family: fontFamily } },
            },
            x: {
                grid: { display: false },
                ticks: { color: '#94a3b8', font: { family: fontFamily } },
            },
        },
    },
}));

const announcementsChart = computed(() => ({
    data: {
        labels: stats.value.announcementsHistory.map((a) => a.date),
        datasets: [
            {
                label: 'Announcements',
                data: stats.value.announcementsHistory.map((a) => a.count),
                backgroundColor: 'rgba(251,146,60,0.75)',
                borderColor: '#fb923c',
                borderWidth: 2,
                borderRadius: 6,
                maxBarThickness: 20,
            },
        ],
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { display: false },
            title: {
                display: true,
                text: 'Announcements Sent (Last 30 Days)',
                font: { size: 13, weight: 'bold' as const, family: fontFamily },
                color: '#1a2332',
                padding: { bottom: 14 },
            },
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: '#f1f5f9' },
                ticks: { color: '#94a3b8', font: { family: fontFamily } },
            },
            x: {
                grid: { display: false },
                ticks: { color: '#94a3b8', font: { family: fontFamily } },
            },
        },
    },
}));

const channelActivityChart = computed(() => ({
    data: {
        labels: stats.value.channelActivity.map((c) => c.name),
        datasets: [
            {
                label: 'Members',
                data: stats.value.channelActivity.map((c) => c.members),
                backgroundColor: 'rgba(194,65,12,0.75)',
                borderColor: '#c2410c',
                borderWidth: 2,
                borderRadius: 6,
                maxBarThickness: 22,
            },
        ],
    },
    options: {
        indexAxis: 'y' as const,
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { display: false },
            title: {
                display: true,
                text: 'Members per Channel',
                font: { size: 13, weight: 'bold' as const, family: fontFamily },
                color: '#1a2332',
                padding: { bottom: 14 },
            },
        },
        scales: {
            x: {
                beginAtZero: true,
                grid: { color: '#f1f5f9' },
                ticks: { color: '#94a3b8', font: { family: fontFamily } },
            },
            y: {
                grid: { display: false },
                ticks: { color: '#94a3b8', font: { family: fontFamily } },
            },
        },
    },
}));

const peakHoursChart = computed(() => ({
    data: {
        labels: stats.value.peakHours.map((h) => h.hour),
        datasets: [
            {
                label: 'Connections',
                data: stats.value.peakHours.map((h) => h.count),
                backgroundColor: 'rgba(154,52,18,0.75)',
                borderColor: '#9a3412',
                borderWidth: 2,
                borderRadius: 6,
                maxBarThickness: 20,
            },
        ],
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { display: false },
            title: {
                display: true,
                text: 'Peak Activity Hours (Last 30 Days)',
                font: { size: 13, weight: 'bold' as const, family: fontFamily },
                color: '#1a2332',
                padding: { bottom: 14 },
            },
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: '#f1f5f9' },
                ticks: { color: '#94a3b8', font: { family: fontFamily } },
            },
            x: {
                grid: { display: false },
                ticks: { color: '#94a3b8', font: { family: fontFamily } },
            },
        },
    },
}));

function timeAgo(ts: string) {
    const diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
    if (diff < 60) return 'Just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
}
</script>

<template>
    <Head title="Dashboard" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="page-root">
            <!-- PAGE HEADER -->
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Overview</div>
                    <h1 class="page-header__title">Dashboard</h1>
                </div>
            </div>

            <!-- STAT CARDS -->
            <div class="stat-row" :class="gridCols">
                <Link
                    v-for="metric in metrics"
                    :key="metric.label"
                    :href="metric.href"
                    class="stat-card stat-card--link"
                    :class="{
                        'stat-card--emergency':
                            metric.emergency && stats.activeEmergencies > 0,
                    }"
                >
                    <div class="stat-card__top">
                        <div class="stat-card__label">{{ metric.label }}</div>
                        <div
                            class="stat-card__icon"
                            :class="{
                                'stat-card__icon--pulse':
                                    metric.label === 'Online Now' ||
                                    (metric.emergency &&
                                        stats.activeEmergencies > 0),
                            }"
                        >
                            {{ metric.icon }}
                        </div>
                    </div>
                    <div
                        class="stat-card__value"
                        :class="{
                            'stat-card__value--red':
                                metric.emergency && stats.activeEmergencies > 0,
                        }"
                    >
                        {{ metric.value }}
                    </div>
                </Link>
            </div>

            <!-- LOADING -->
            <div v-if="loading" class="table-card">
                <div class="empty-state">
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
                        >Loading dashboard…</span
                    >
                </div>
            </div>

            <template v-else>
                <!-- Charts row 1 -->
                <div class="chart-grid chart-grid--1-2">
                    <div class="chart-card">
                        <Doughnut
                            :data="onlineOfflineChart.data"
                            :options="onlineOfflineChart.options"
                        />
                    </div>
                    <div class="chart-card">
                        <Line
                            :data="onlineHistoryChart.data"
                            :options="onlineHistoryChart.options"
                        />
                    </div>
                </div>

                <!-- Charts row 2 -->
                <div class="chart-grid chart-grid--2">
                    <div class="chart-card">
                        <Bar
                            :data="employeesPerClientChart.data"
                            :options="employeesPerClientChart.options"
                        />
                    </div>
                    <div class="chart-card">
                        <Bar
                            :data="announcementsChart.data"
                            :options="announcementsChart.options"
                        />
                    </div>
                </div>

                <!-- Charts row 3 -->
                <div class="chart-grid chart-grid--2">
                    <div class="chart-card">
                        <Bar
                            :data="channelActivityChart.data"
                            :options="channelActivityChart.options"
                        />
                    </div>
                    <div class="chart-card">
                        <Bar
                            :data="peakHoursChart.data"
                            :options="peakHoursChart.options"
                        />
                    </div>
                </div>

                <!-- Recent activity -->
                <div class="table-card">
                    <div class="activity-header">
                        <span class="activity-header__title"
                            >Recent Activity</span
                        >
                    </div>

                    <div
                        v-if="stats.recentActivity.length === 0"
                        class="empty-state"
                    >
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
                                    d="M13 10V3L4 14h7v7l9-11h-7z"
                                />
                            </svg>
                        </div>
                        <p class="empty-state__title">No activity yet</p>
                        <p class="empty-state__sub">
                            Personnel connection events will appear here
                        </p>
                    </div>

                    <ul v-else class="activity-list">
                        <li
                            v-for="(activity, i) in stats.recentActivity"
                            :key="i"
                            class="activity-row"
                        >
                            <span
                                class="activity-row__dot"
                                :class="{
                                    'activity-row__dot--online':
                                        activity.status === 'online',
                                }"
                            ></span>
                            <span class="activity-row__name">{{
                                activity.name
                            }}</span>
                            <span
                                v-if="activity.channel"
                                class="activity-row__channel"
                                >· {{ activity.channel }}</span
                            >
                            <span
                                class="activity-row__status"
                                :class="{
                                    'activity-row__status--online':
                                        activity.status === 'online',
                                }"
                            >
                                {{
                                    activity.status === 'online'
                                        ? 'Connected'
                                        : 'Disconnected'
                                }}
                            </span>
                            <span class="activity-row__time">{{
                                timeAgo(activity.logged_at)
                            }}</span>
                        </li>
                    </ul>
                </div>
            </template>
        </div>
    </AppLayout>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap');

.page-root {
    --c-bg: #f4f6f9;
    --c-surface: #ffffff;
    --c-border: #e4e8ef;
    --c-text: #1a2332;
    --c-muted: #64748b;
    --c-faint: #94a3b8;
    --c-primary: #ea580c;
    --c-primary-h: #c2410c;
    --c-danger: #dc2626;
    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 16px;
    --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
    --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.08);
    font-family: 'DM Sans', system-ui, sans-serif;

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

/* STAT ROW */
.stat-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}
.stat-row.grid-cols-4 {
    grid-template-columns: repeat(4, 1fr);
}
.stat-row.grid-cols-5 {
    grid-template-columns: repeat(5, 1fr);
}
.stat-row.grid-cols-6 {
    grid-template-columns: repeat(6, 1fr);
}

.stat-card {
    background: #ffffff;
    border: 1px solid #e4e8ef;
    border-radius: 16px;
    padding: 20px 22px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    box-shadow: var(--shadow-sm);
    transition:
        box-shadow 0.2s,
        transform 0.2s,
        border-color 0.2s;
    text-decoration: none;
}
.stat-card--link {
    cursor: pointer;
}
.stat-card--link:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-1px);
    border-color: #fdba8c;
}
.stat-card--link:active {
    transform: scale(0.98);
}
.stat-card--emergency {
    border-color: #fca5a5;
    background: #fef2f2;
}
.stat-card--emergency:hover {
    border-color: #f87171;
}

.stat-card__top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 8px;
}
.stat-card__label {
    font-size: 11px;
    font-weight: 600;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.stat-card--emergency .stat-card__label {
    color: #dc2626;
}
.stat-card__icon {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    background: #fff7ed;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}
.stat-card--emergency .stat-card__icon {
    background: #fee2e2;
}
.stat-card__icon--pulse {
    animation: pulse-icon 1.8s ease-in-out infinite;
}
@keyframes pulse-icon {
    0%,
    100% {
        opacity: 1;
    }
    50% {
        opacity: 0.55;
    }
}
.stat-card__value {
    font-size: 28px;
    font-weight: 800;
    color: #1a2332;
    line-height: 1;
    letter-spacing: -1px;
}
.stat-card__value--red {
    color: #dc2626;
}

/* CHART GRIDS */
.chart-grid {
    display: grid;
    gap: 16px;
}
.chart-grid--2 {
    grid-template-columns: 1fr 1fr;
}
.chart-grid--1-2 {
    grid-template-columns: 1fr 2fr;
}
.chart-card {
    background: #ffffff;
    border: 1px solid #e4e8ef;
    border-radius: 16px;
    padding: 20px 22px;
    box-shadow: var(--shadow-sm);
    transition:
        box-shadow 0.2s,
        transform 0.2s;
}
.chart-card:hover {
    box-shadow: var(--shadow-md);
}

/* TABLE CARD (reused for activity feed / loading / empty) */
.table-card {
    background: #ffffff;
    border: 1px solid #e4e8ef;
    border-radius: 16px;
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}
.activity-header {
    padding: 16px 22px;
    border-bottom: 1px solid #e4e8ef;
    background: #f8fafc;
}
.activity-header__title {
    font-size: 13px;
    font-weight: 700;
    color: #1a2332;
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

/* ACTIVITY LIST */
.activity-list {
    list-style: none;
    margin: 0;
    padding: 0;
}
.activity-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 13px 22px;
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.12s;
}
.activity-row:last-child {
    border-bottom: none;
}
.activity-row:hover {
    background: #fafbfc;
}
.activity-row__dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: #cbd5e1;
    flex-shrink: 0;
}
.activity-row__dot--online {
    background: #16a34a;
}
.activity-row__name {
    font-size: 13px;
    font-weight: 600;
    color: #1a2332;
}
.activity-row__channel {
    font-size: 12px;
    color: #94a3b8;
}
.activity-row__status {
    background: #f1f5f9;
    color: #64748b;
    border-radius: 20px;
    padding: 2px 10px;
    font-size: 11px;
    font-weight: 700;
}
.activity-row__status--online {
    background: #f0fdf4;
    color: #16a34a;
}
.activity-row__time {
    margin-left: auto;
    font-size: 12px;
    color: #94a3b8;
    white-space: nowrap;
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
@media (max-width: 1024px) {
    .stat-row.grid-cols-4,
    .stat-row.grid-cols-5,
    .stat-row.grid-cols-6 {
        grid-template-columns: repeat(3, 1fr);
    }
    .chart-grid--1-2 {
        grid-template-columns: 1fr;
    }
    .chart-grid--2 {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 768px) {
    .stat-row,
    .stat-row.grid-cols-4,
    .stat-row.grid-cols-5,
    .stat-row.grid-cols-6 {
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
    .chart-card {
        padding: 14px;
    }
    .activity-row {
        padding: 11px 16px;
        flex-wrap: wrap;
    }
    .activity-row__time {
        margin-left: 0;
        width: 100%;
        padding-left: 19px;
    }
}
</style>

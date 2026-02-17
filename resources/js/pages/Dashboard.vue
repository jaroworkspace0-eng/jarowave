<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

const stats = ref({
    channelsCount: 0,
    employeesCount: 0,
    clientsCount: 0,
    onlineCount: 0,
    offlineCount: 0,
});

onMounted(async () => {
    const { data } = await axios.get(
        `${import.meta.env.VITE_APP_URL}/api/dashboard`,
        {
            headers: {
                Authorization: `Bearer ${localStorage.getItem('token')}`, // if using Sanctum or JWT
            },
        },
    );
    stats.value = data.stats;
});

const metrics = computed(() => [
    {
        label: 'Channels',
        value: stats.value.channelsCount.toLocaleString(),
        icon: '🎯',
        href: '/channels',
    },
    {
        label: 'Employees',
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
        color: 'green',
    },
    {
        label: 'Offline',
        value: stats.value.offlineCount.toLocaleString(),
        icon: '⚪',
        href: '/employees?status=offline',
        color: 'gray',
    },
]);
</script>
<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="grid grid-cols-1 gap-6 p-6 sm:grid-cols-2 lg:grid-cols-5">
            <Link
                v-for="metric in metrics"
                :key="metric.label"
                :href="metric.href"
                class="group flex items-center justify-between rounded-lg border border-gray-100 bg-white p-6 shadow transition-all hover:border-blue-300 hover:shadow-md active:scale-95"
            >
                <div>
                    <h3
                        class="text-sm font-medium text-gray-500 transition-colors group-hover:text-blue-600"
                    >
                        {{ metric.label }}
                    </h3>
                    <p class="text-2xl font-bold text-gray-900">
                        {{ metric.value }}
                    </p>
                </div>

                <div
                    :class="[
                        'rounded-full p-3 transition-colors',
                        metric.label === 'Online Now'
                            ? 'bg-green-50 group-hover:bg-green-100'
                            : 'bg-gray-50 group-hover:bg-blue-50',
                    ]"
                >
                    <span
                        :class="[
                            'text-2xl',
                            metric.label === 'Online Now'
                                ? 'animate-pulse'
                                : '',
                        ]"
                    >
                        {{ metric.icon }}
                    </span>
                </div>
            </Link>
        </div>
    </AppLayout>
</template>

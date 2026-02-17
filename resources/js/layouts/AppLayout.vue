<script setup lang="ts">
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { onMounted, ref } from 'vue';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

// ---------------------------
// Stable SPA route protection
// ---------------------------
const user = ref<any | null>(null);
const token = ref<string | null>(null);

onMounted(() => {
    try {
        const storedToken = localStorage.getItem('token');
        const storedUser = localStorage.getItem('user');

        token.value = storedToken;
        user.value = storedUser ? JSON.parse(storedUser) : null;

        if (!token.value || !user.value) {
            // Only redirect if truly not logged in
            router.visit('/');
        } else {
            // Attach token to axios globally
            axios.defaults.headers.common['Authorization'] =
                `Bearer ${token.value}`;
        }
    } catch (err) {
        console.error('Error reading auth from localStorage:', err);
        router.visit('/');
    }
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
    </AppLayout>
</template>

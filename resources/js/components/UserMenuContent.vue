<script setup lang="ts">
import UserInfo from '@/components/UserInfo.vue';
import {
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import type { User } from '@/types';
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { LogOut, Settings } from 'lucide-vue-next';

// Props
const props = defineProps<{ user?: User | null }>();

// Logout function (self-contained)
const handleLogout = (event: Event) => {
    event.preventDefault(); // prevent default link navigation

    // Clear token & user
    localStorage.removeItem('token');
    localStorage.removeItem('user');
    delete axios.defaults.headers.common['Authorization'];

    // Optional: clear Inertia page cache
    router.flushAll();

    // Redirect to login
    router.visit('/');
};
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="props.user" :show-email="true" />
        </div>
    </DropdownMenuLabel>

    <DropdownMenuSeparator />

    <!-- Settings: plain Link, no dropdown item wrapping -->
    <div class="px-1">
        <Link
            class="flex w-full items-center rounded px-2 py-1 text-sm hover:bg-gray-100"
            href="/settings"
            prefetch
        >
            <Settings class="mr-2 h-4 w-4" />
            Settings
        </Link>
    </div>

    <DropdownMenuSeparator />

    <!-- Logout: keep as DropdownMenuItem so dropdown closes properly -->
    <DropdownMenuItem :as-child="true">
        <button
            class="block w-full text-left"
            @click="handleLogout"
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            Log out
        </button>
    </DropdownMenuItem>
</template>

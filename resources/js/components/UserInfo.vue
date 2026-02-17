<script setup lang="ts">
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import type { User } from '@/types';
import { computed, onMounted, ref } from 'vue';

const { getInitials } = useInitials();

// store user locally
const user = ref<User | null>(null);

// load from localStorage when component mounts
onMounted(() => {
    const storedUser = localStorage.getItem('user');

    if (storedUser) {
        user.value = JSON.parse(storedUser);
    }
});

// check avatar
const showAvatar = computed(
    () => user.value?.avatar && user.value?.avatar !== '',
);
</script>

<template>
    <Avatar class="h-8 w-8 overflow-hidden rounded-lg">
        <AvatarImage v-if="showAvatar" :src="user?.avatar" :alt="user?.name" />
        <AvatarFallback class="rounded-lg text-black dark:text-white">
            {{ getInitials(user?.name) }}
        </AvatarFallback>
    </Avatar>

    <div class="grid flex-1 text-left text-sm leading-tight">
        <span v-if="user" class="truncate font-medium">
            {{ user.name }}
        </span>

        <span v-if="user?.email" class="truncate text-xs text-muted-foreground">
            {{ user.email }}
        </span>
    </div>
</template>

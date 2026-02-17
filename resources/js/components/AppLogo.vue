<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { useSidebar } from './ui/sidebar/utils';

const { toggleSidebar } = useSidebar();

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <!-- <div
        class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
    >
        <AppLogoIcon class="size-5 fill-current text-white dark:text-black" />
    </div> -->
    <div class="ml-1 flex flex-1 items-center p-0 text-left text-sm">
        <span class="flex-1 truncate leading-tight font-semibold"> Dycom </span>

        <!-- <Button
            data-sidebar="trigger"
            data-slot="sidebar-trigger"
            variant="ghost"
            size="icon"
            :class="cn('h-7 w-7', props.class)"
            @click="toggleSidebar"
        >
            <PanelLeft />
            <span class="sr-only">Toggle Sidebar</span>
        </Button> -->
    </div>
</template>

<script lang="ts" setup>
import { defineEmits, defineProps } from 'vue';

const props = defineProps<{
    itemName: string;
    type: string;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'confirm'): void;
}>();
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 backdrop-blur-sm"
    >
        <div
            class="w-full max-w-md rounded-lg bg-white p-6 text-center shadow-lg"
        >
            <h2 class="mb-4 text-lg font-semibold">
                Delete {{ props.type.toUpperCase() }}?
            </h2>
            <p class="mb-6 text-sm text-neutral-700">
                Are you sure you want to delete
                <strong>{{ props.itemName }}</strong
                >? This action cannot be undone.
            </p>

            <div class="flex justify-center space-x-3">
                <button
                    class="rounded bg-neutral-200 px-4 py-2 text-sm hover:bg-neutral-300"
                    @click="$emit('close')"
                >
                    Cancel
                </button>
                <button
                    class="rounded bg-red-600 px-4 py-2 text-sm text-white hover:bg-red-700"
                    @click="$emit('confirm')"
                >
                    Delete
                </button>
            </div>
        </div>
    </div>
</template>

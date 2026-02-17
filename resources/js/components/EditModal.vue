<script lang="ts" setup>
import { defineEmits, defineProps, ref } from 'vue';
interface BaseItem {
    id: number;
    name: string;
    email?: string;
    phone?: string;
    occupation?: string;
    category?: string;
}

const props = defineProps<{
    item: BaseItem;
    type: string;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'save', updatedItem: BaseItem): void;
}>();

const form = ref({ ...props.item });

const save = () => emit('save', form.value);
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 backdrop-blur-sm"
    >
        <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-lg">
            <h2 class="mb-4 text-lg font-semibold">
                Edit {{ props.type.toUpperCase() }}
            </h2>

            <div class="space-y-3">
                <label class="flex flex-col text-sm">
                    Name
                    <input
                        v-model="form.name"
                        class="mt-1 rounded border px-2 py-1 text-sm"
                    />
                </label>
                <label
                    class="flex flex-col text-sm"
                    v-if="form.email !== undefined"
                >
                    Email
                    <input
                        v-model="form.email"
                        class="mt-1 rounded border px-2 py-1 text-sm"
                    />
                </label>
                <label
                    class="flex flex-col text-sm"
                    v-if="form.phone !== undefined"
                >
                    Phone
                    <input
                        v-model="form.phone"
                        class="mt-1 rounded border px-2 py-1 text-sm"
                    />
                </label>
                <label
                    class="flex flex-col text-sm"
                    v-if="form.occupation !== undefined"
                >
                    Occupation
                    <input
                        v-model="form.occupation"
                        class="mt-1 rounded border px-2 py-1 text-sm"
                    />
                </label>
                <label
                    class="flex flex-col text-sm"
                    v-if="form.category !== undefined"
                >
                    Category
                    <input
                        v-model="form.category"
                        class="mt-1 rounded border px-2 py-1 text-sm"
                    />
                </label>
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <button
                    class="rounded bg-neutral-200 px-4 py-2 text-sm hover:bg-neutral-300"
                    @click="$emit('close')"
                >
                    Cancel
                </button>
                <button
                    class="rounded bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700"
                    @click="save"
                >
                    Save
                </button>
            </div>
        </div>
    </div>
</template>

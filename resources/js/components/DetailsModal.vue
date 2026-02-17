<script lang="ts" setup>
import { defineProps, ref } from 'vue';
import DeleteModal from './DeleteModal.vue';
import EditModal from './EditModal.vue';

interface BaseItem {
    id: number;
    name: string;
    email?: string;
    phone?: string;
    occupation?: string;
    category?: string;
    is_active?: boolean;
    status?: 'online' | 'offline';
    created_at?: string;
    updated_at?: string;
}

const props = defineProps<{
    item: BaseItem;
    type: string;
    onClose: () => void;
}>();

const showEdit = ref(false);
const showDelete = ref(false);

const formatDate = (date?: string): string => {
    if (!date) return '-';
    const d = new Date(date);
    return d.toLocaleString();
};
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 backdrop-blur-sm"
    >
        <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-lg">
            <h2 class="mb-4 text-lg font-semibold">
                {{ props.type.toUpperCase() }} DETAILS
            </h2>

            <div class="space-y-2">
                <!-- Account -->
                <div
                    v-if="props.item.is_active !== undefined"
                    class="flex justify-between text-sm text-neutral-700"
                >
                    <span class="font-medium">Account</span>
                    <span
                        :class="
                            props.item.is_active
                                ? 'bg-green-100 text-green-800'
                                : 'bg-red-100 text-red-800'
                        "
                        class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold"
                    >
                        {{ props.item.is_active ? 'Active' : 'Inactive' }}
                    </span>
                </div>

                <!-- Online / Offline -->
                <div
                    v-if="props.item.status"
                    class="flex justify-between text-sm text-neutral-700"
                >
                    <span class="font-medium">Online / Offline</span>
                    <span
                        :class="
                            props.item.status == 'online'
                                ? 'bg-green-100 text-green-800'
                                : 'bg-red-100 text-red-800'
                        "
                        class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold"
                    >
                        {{
                            props.item.status == 'online' ? 'Online' : 'Offline'
                        }}
                    </span>
                </div>

                <div
                    v-if="props.item.name"
                    class="flex justify-between text-sm text-neutral-700"
                >
                    <span class="font-medium">Name</span>
                    <span>{{ props.item.name }}</span>
                </div>
                <div
                    v-if="props.item.email"
                    class="flex justify-between text-sm text-neutral-700"
                >
                    <span class="font-medium">Email</span>
                    <span>{{ props.item.email }}</span>
                </div>
                <div
                    v-if="props.item.phone"
                    class="flex justify-between text-sm text-neutral-700"
                >
                    <span class="font-medium">Phone</span>
                    <span>{{ props.item.phone }}</span>
                </div>
                <div
                    v-if="props.type === 'channels' && props.item.category"
                    class="flex justify-between text-sm text-neutral-700"
                >
                    <span class="font-medium">Category</span>
                    <span>{{ props.item.category }}</span>
                </div>
                <div
                    v-if="props.item.created_at"
                    class="flex justify-between text-sm text-neutral-700"
                >
                    <span class="font-medium">Created</span>
                    <span>{{ formatDate(props.item.created_at) }}</span>
                </div>
                <div
                    v-if="props.item.updated_at"
                    class="flex justify-between text-sm text-neutral-700"
                >
                    <span class="font-medium">Updated</span>
                    <span>{{ formatDate(props.item.updated_at) }}</span>
                </div>
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <button
                    class="rounded bg-neutral-200 px-4 py-2 text-sm hover:bg-neutral-300"
                    @click="props.onClose()"
                >
                    Close
                </button>
                <button
                    class="rounded bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700"
                    @click="showEdit = true"
                >
                    Edit
                </button>
                <button
                    class="rounded bg-red-600 px-4 py-2 text-sm text-white hover:bg-red-700"
                    @click="showDelete = true"
                >
                    Delete
                </button>
            </div>
        </div>

        <!-- Nested Modals -->
        <EditModal
            v-if="showEdit"
            :item="props.item"
            :type="props.type"
            @close="showEdit = false"
            @save="showEdit = false"
        />
        <DeleteModal
            v-if="showDelete"
            :itemName="props.item.name"
            :type="props.type"
            @close="showDelete = false"
            @confirm="showDelete = false"
        />
    </div>
</template>

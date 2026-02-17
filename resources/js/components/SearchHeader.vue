<script lang="ts" setup>
import axios from 'axios';
import { Search, X } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import DetailsModal from './DetailsModal.vue';

// --------------------
// Types
// --------------------
interface BaseItem {
    id: number;
    name: string;
    email?: string | null;
    phone?: string | null;
    occupation?: string | null;
    category?: string | null;
    is_active?: boolean | null;
    status?: 'online' | 'offline' | null;
    created_at?: string | null;
    updated_at?: string | null;
}

type Client = BaseItem;
type Employee = BaseItem;
type Channel = BaseItem;

interface Results {
    clients: Client[];
    employees: Employee[];
    channels: Channel[];
}

// --------------------
// Props
// --------------------
const props = defineProps<{ initialResults: Results }>();

// --------------------
// Reactive state
// --------------------
const query = ref('');
const results = ref<Results>({ ...props.initialResults });

const showModal = ref(false);
const selectedItem = ref<BaseItem | null>(null);
const selectedType = ref<'client' | 'employee' | 'channel' | null>(null);

// --------------------
// Utils
// --------------------
const formatDate = (date?: string | null): string => {
    if (!date) return '-';
    const d = new Date(date);
    return d.toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

// --------------------
// Helpers
// --------------------
const openModal = (item: BaseItem, type: 'client' | 'employee' | 'channel') => {
    selectedItem.value = item;
    selectedType.value = type;
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    selectedItem.value = null;
    selectedType.value = null;
};

const clearSearch = () => {
    query.value = '';
    results.value = { clients: [], employees: [], channels: [] };
};

// --------------------
// Debounced search
// --------------------
let debounceTimeout: number | undefined;
watch(query, (value) => {
    clearTimeout(debounceTimeout);

    if (!value) {
        results.value = { clients: [], employees: [], channels: [] };
        return;
    }

    debounceTimeout = window.setTimeout(async () => {
        try {
            const { data } = await axios.get<Results>('/search', {
                params: { search: value },
            });
            results.value = data;
        } catch (error) {
            console.error('Search failed:', error);
            results.value = { clients: [], employees: [], channels: [] };
        }
    }, 300);
});

// --------------------
// Actions
// --------------------
const editItem = (item: BaseItem, type: string) => {
    console.log('Edit', type, item);
    // Open edit modal here
};

const deleteItem = (item: BaseItem, type: string) => {
    console.log('Delete', type, item);
    // Open delete confirmation modal here
};
</script>

<template>
    <div class="relative flex h-16 w-full items-center px-0">
        <!-- Search Input -->
        <!-- <div class="flex w-full items-center">
            <Search class="mr-2 ml-3 size-4 text-muted-foreground" />

            <input
                v-model="query"
                type="search"
                placeholder="Search clients, employees, and channels..."
                class="h-10 w-full bg-transparent text-sm text-neutral-900 placeholder:text-neutral-500 focus:ring-0 focus:outline-none"
                style="border: none"
            />

            <button
                v-if="query"
                type="button"
                class="mr-3 p-1 text-muted-foreground hover:text-foreground"
                @click="clearSearch"
            >
                <X class="size-4" />
            </button>
        </div> -->

        <!-- Results Dropdown -->
        <div
            v-if="
                query &&
                (results.clients.length ||
                    results.employees.length ||
                    results.channels.length)
            "
            class="absolute top-full left-0 z-50 mt-0 max-h-96 w-full overflow-auto rounded-b-lg bg-white shadow-xl ring-1 ring-black/5 transition-all"
        >
            <template v-for="(items, type) in results">
                <div
                    v-if="items.length"
                    :key="type"
                    class="border-b border-neutral-100 p-3 last:border-b-0"
                >
                    <h2
                        class="mb-1 text-xs font-semibold tracking-wide text-neutral-500 uppercase"
                    >
                        {{ type }}
                    </h2>
                    <ul>
                        <li
                            v-for="item in items"
                            :key="item.id"
                            @click="
                                openModal(
                                    item,
                                    type as 'client' | 'employee' | 'channel',
                                )
                            "
                            class="cursor-pointer rounded px-2 py-2 text-sm transition hover:bg-neutral-50"
                        >
                            <span class="font-medium text-neutral-900">{{
                                item.name
                            }}</span>
                            <span class="block text-xs text-neutral-400">
                                {{
                                    type === 'clients'
                                        ? item.email
                                        : type === 'employees'
                                          ? item.occupation
                                          : item.category
                                }}
                            </span>
                        </li>
                    </ul>
                </div>
            </template>
        </div>

        <!-- Modal -->
        <DetailsModal
            v-if="showModal"
            :item="selectedItem"
            :type="selectedType"
            :onClose="closeModal"
        />
    </div>
</template>

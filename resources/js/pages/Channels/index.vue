<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import axios from 'axios';
import { onMounted, ref } from 'vue';
import '../../../css/style.css';

// --- State ---
const showModal = ref(false);
const isEditing = ref(false);
const clients = ref<any[]>([]);
const channelsList = ref<any[]>([]);

const isConfirmingDeletion = ref(false);
const channelToDelete = ref<any | null>(null);
const confirmationName = ref('');

// --- Feedback & Errors ---
const flashMessage = ref<string | null>(null);
const isProcessing = ref(false);
const errors = ref<{ [key: string]: string[] }>({});

// --- Form ---
const form = ref({
    id: null,
    client_id: '',
    name: '',
    category: '',
    type: '',
});

// --- Helpers ---
function showMessage(message: string) {
    flashMessage.value = message;
    setTimeout(() => (flashMessage.value = null), 3000);
}

function resetForm() {
    form.value = {
        id: null,
        client_id: '',
        name: '',
        category: '',
        type: '',
    };
}

// --- API Fetch ---
const reloadClients = async () => {
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/clients/show`,
            {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                },
            },
        );
        clients.value = data;
    } catch (e) {
        console.error('Error fetching clients', e);
    }
};

const channels = ref<any>({
    data: [],
    from: 0,
    to: 0,
    total: 0,
    links: [],
    current_page: 1,
    last_page: 1,
});

const reloadChannels = async (url?: string) => {
    try {
        const endpoint = url || `${import.meta.env.VITE_APP_URL}/api/channels`;

        const { data } = await axios.get(endpoint, {
            headers: {
                Authorization: `Bearer ${localStorage.getItem('token')}`,
            },
        });

        channels.value = data.channels; // ✅ full paginator object
        console.log('Channels:', channels.value.data);
    } catch (e) {
        console.error('Error fetching channels', e);
    }
};

onMounted(() => {
    reloadClients();
    reloadChannels();
});

// --- Modal Logic ---
const openModal = () => {
    isEditing.value = false;
    resetForm();
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    isConfirmingDeletion.value = false;
    channelToDelete.value = null;
    confirmationName.value = '';
};

// --- Channel Actions ---
async function createChannel() {
    try {
        isProcessing.value = true;
        await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/channels`,
            form.value,
            {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                },
            },
        );
        errors.value = {};
        showMessage('Channel created successfully');
        resetForm();
        showModal.value = false;
        await reloadChannels();
    } catch (err: any) {
        errors.value = err.response?.data?.errors || {};
    } finally {
        isProcessing.value = false;
    }
}

async function updateChannel() {
    if (!form.value.id) {
        alert('Error: No ID found. Cannot update.');
        return;
    }
    try {
        isProcessing.value = true;
        await axios.put(
            `${import.meta.env.VITE_APP_URL}/api/channels/${form.value.id}`,
            form.value,
            {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                },
            },
        );

        errors.value = {};
        showMessage('Channel updated successfully');
        showModal.value = false;
        isEditing.value = false;
        resetForm();
        await reloadChannels();
    } catch (err: any) {
        errors.value = err.response?.data?.errors || {};
    } finally {
        isProcessing.value = false;
    }
}

async function deleteChannel() {
    try {
        isProcessing.value = true;
        await axios.delete(
            `${import.meta.env.VITE_APP_URL}/api/channels/${channelToDelete.value.id}`,
            {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                },
            },
        );
        showMessage('Channel deleted successfully');
        closeModal();
        await reloadChannels();
    } catch (err: any) {
        errors.value = err.response?.data?.errors || {};
    } finally {
        isProcessing.value = false;
    }
}

async function toggleChannelStatus(channel: any) {
    try {
        isProcessing.value = true;
        await axios.patch(
            `${import.meta.env.VITE_APP_URL}/api/channels/${channel.id}/toggle-status`,
            {},
            {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                },
            },
        );
        showMessage('Channel status updated');
        await reloadChannels();
    } catch (err: any) {
        errors.value = err.response?.data?.errors || {};
    } finally {
        isProcessing.value = false;
    }
}

// --- Editing ---
const editChannel = (channel: any) => {
    isEditing.value = true;
    form.value = {
        id: channel.id,
        name: channel.name,
        category: channel.category,
        type: channel.type,
        client_id: channel.client_id || channel.client?.id,
    };
    showModal.value = true;
};

const handleSubmit = () => {
    if (isEditing.value) {
        updateChannel();
    } else {
        createChannel();
    }
};

const confirmDelete = (channel: any) => {
    channelToDelete.value = channel;
    confirmationName.value = '';
    isConfirmingDeletion.value = true; // <-- this is what shows the modal
};
</script>

<template>
    <Head title="Channels" />

    <AppLayout>
        <div
            class="relative flex h-full w-full flex-col rounded-xl bg-white bg-clip-border text-gray-700 shadow-md"
        >
            <div
                class="relative mx-4 mt-4 overflow-hidden rounded-none bg-white bg-clip-border text-gray-700"
            >
                <div class="mb-8 flex items-center justify-between gap-8">
                    <div>
                        <p
                            class="mt-1 block font-sans text-base leading-relaxed font-normal text-gray-700 antialiased"
                        >
                            Channels
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col gap-2 sm:flex-row">
                        <button
                            class="rounded-lg border border-gray-900 px-4 py-2 text-center align-middle font-sans text-xs font-bold text-gray-900 uppercase transition-all select-none hover:opacity-75 focus:ring focus:ring-gray-300 active:opacity-[0.85] disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none"
                            type="button"
                            @click="openModal"
                        >
                            Add Channel
                        </button>
                        <form @submit.prevent="handleSubmit">
                            <div v-if="showModal">
                                <!-- Overlaying -->
                                <div
                                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-[2px]"
                                >
                                    <!-- Modal Content -->
                                    <div
                                        class="w-full max-w-lg rounded-lg bg-white p-6 shadow-lg"
                                    >
                                        <h2 class="text-heading">
                                            {{
                                                isEditing
                                                    ? 'Edit Channel'
                                                    : 'Add Channel'
                                            }}
                                        </h2>

                                        <div class="grid gap-4">
                                            <div class="grid gap-3">
                                                <Label for="name-1"
                                                    >Name
                                                </Label>
                                                <input
                                                    id="name-1"
                                                    default-value="Pedro Duarte"
                                                    v-model="form.name"
                                                />
                                                <p
                                                    v-if="errors.name"
                                                    class="text-sm text-red-600"
                                                >
                                                    {{ errors.name[0] }}
                                                </p>
                                            </div>
                                            <div class="grid gap-3">
                                                <Label for="name-1"
                                                    >Category</Label
                                                >
                                                <input
                                                    id="name-1"
                                                    default-value="Pedro Duarte"
                                                    v-model="form.category"
                                                />
                                                <p
                                                    v-if="errors.category"
                                                    class="text-sm text-red-600"
                                                >
                                                    {{ errors.category[0] }}
                                                </p>
                                            </div>
                                            <div class="grid gap-3">
                                                <Label for="name-1"
                                                    >Client</Label
                                                >
                                                <select
                                                    id="post-client"
                                                    v-model="form.client_id"
                                                    class="focus:ring-opacity-50 mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200"
                                                >
                                                    <option value="" disabled>
                                                        -- Choose client --
                                                    </option>
                                                    <option
                                                        v-for="client in clients"
                                                        :key="client.id"
                                                        :value="client.id"
                                                    >
                                                        {{ client.name }}
                                                    </option>
                                                </select>
                                                <p
                                                    v-if="errors.client_id"
                                                    class="text-sm text-red-600"
                                                >
                                                    {{ errors.client_id[0] }}
                                                </p>
                                            </div>
                                            <div class="grid gap-3">
                                                <Label for="name-1">Type</Label>
                                                <select
                                                    v-model="form.type"
                                                    id="post-type"
                                                    class="focus:ring-opacity-50 mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200"
                                                >
                                                    <option value="" disabled>
                                                        -- Choose type --
                                                    </option>
                                                    <option
                                                        value="listen"
                                                        selected
                                                    >
                                                        Listen Only
                                                    </option>
                                                    <option
                                                        value="listen_and_speak"
                                                        selected
                                                    >
                                                        Listen & speak
                                                    </option>
                                                </select>
                                                <p
                                                    v-if="errors.type"
                                                    class="text-sm text-red-600"
                                                >
                                                    {{ errors.type[0] }}
                                                </p>
                                            </div>
                                            <div class="flex w-max items-end">
                                                <button
                                                    type="button"
                                                    @click="closeModal"
                                                    class="cancel-btn mr-3"
                                                >
                                                    Cancel
                                                </button>

                                                <button
                                                    class="save-btn flex items-center justify-center"
                                                >
                                                    <span
                                                        v-if="isProcessing"
                                                        class="loader mr-2"
                                                    ></span>
                                                    <span>
                                                        {{
                                                            isProcessing
                                                                ? isEditing
                                                                    ? 'Updating...'
                                                                    : 'Adding...'
                                                                : isEditing
                                                                  ? 'Update Channel'
                                                                  : 'Add Channel'
                                                        }}
                                                    </span>
                                                </button>

                                                <!-- <button
                                                    type="submit"
                                                    class="save-btn"
                                                >
                                                    Save
                                                </button> -->
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="overflow-scroll p-0 px-0">
                <div class="pt-0 pr-4 pb-0 pl-4">
                    <div
                        v-if="flashMessage"
                        class="mb-4 rounded bg-green-100 p-2 text-green-700"
                    >
                        {{ flashMessage }}
                    </div>
                </div>
                <table class="mt-0 w-full min-w-max table-auto text-left">
                    <thead>
                        <tr class="bg-gray-50">
                            <th
                                class="border-blue-gray-100 bg-blue-gray-50/50 hover:bg-blue-gray-50 cursor-pointer border-y p-4 transition-colors"
                            >
                                <p
                                    class="text-blue-gray-900 flex items-center justify-between gap-2 font-sans text-sm leading-none font-normal antialiased opacity-70"
                                >
                                    Name
                                </p>
                            </th>
                            <th
                                class="border-blue-gray-100 bg-blue-gray-50/50 hover:bg-blue-gray-50 cursor-pointer border-y p-4 transition-colors"
                            >
                                <p
                                    class="text-blue-gray-900 flex items-center justify-between gap-2 font-sans text-sm leading-none font-normal antialiased opacity-70"
                                >
                                    Associated Client
                                </p>
                            </th>
                            <th
                                class="border-blue-gray-100 bg-blue-gray-50/50 hover:bg-blue-gray-50 cursor-pointer border-y p-4 transition-colors"
                            >
                                <p
                                    class="text-blue-gray-900 flex items-center justify-between gap-2 font-sans text-sm leading-none font-normal antialiased opacity-70"
                                >
                                    Catagory
                                </p>
                            </th>
                            <th
                                class="border-blue-gray-100 bg-blue-gray-50/50 hover:bg-blue-gray-50 cursor-pointer border-y p-4 transition-colors"
                            >
                                <p
                                    class="text-blue-gray-900 flex items-center justify-between gap-2 font-sans text-sm leading-none font-normal antialiased opacity-70"
                                >
                                    Status
                                </p>
                            </th>
                            <th
                                class="border-blue-gray-100 bg-blue-gray-50/50 hover:bg-blue-gray-50 cursor-pointer border-y p-4 transition-colors"
                            >
                                <p
                                    class="text-blue-gray-900 flex items-center justify-between gap-2 font-sans text-sm leading-none font-normal antialiased opacity-70"
                                >
                                    Type
                                </p>
                            </th>
                            <th
                                class="border-blue-gray-100 bg-blue-gray-50/50 hover:bg-blue-gray-50 cursor-pointer border-y p-2 transition-colors"
                            >
                                <p
                                    class="text-blue-gray-900 flex items-center justify-between gap-2 font-sans text-sm leading-none font-normal antialiased opacity-70"
                                >
                                    Action
                                </p>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="channels.data.length === 0">
                            <td
                                colspan="9"
                                class="p-4 text-center text-gray-500"
                            >
                                No channles found.
                            </td>
                        </tr>
                        <tr
                            v-for="channel in channels.data"
                            :key="channel.id"
                            class="hover:bg-gray-50/50"
                        >
                            <td class="border-blue-gray-50 border-b p-4">
                                <div class="flex items-center gap-3">
                                    <div class="flex flex-col">
                                        <p
                                            class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                                        >
                                            {{ channel.name }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <div class="flex flex-col">
                                    <p
                                        class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                                    >
                                        {{ channel.client.name }}
                                    </p>
                                </div>
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <div class="flex flex-col">
                                    <p
                                        class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                                    >
                                        {{ channel.category }}
                                    </p>
                                </div>
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <button
                                    @click="toggleChannelStatus(channel)"
                                    :title="
                                        channel.is_active
                                            ? 'Deactivate Channel'
                                            : 'Activate Channel'
                                    "
                                    class="transition-transform active:scale-95"
                                >
                                    <span
                                        :class="[
                                            'cursor-pointer rounded-full px-2 py-1 text-xs font-bold uppercase',
                                            channel.is_active
                                                ? 'border border-green-500/30 bg-green-500/20 text-green-900'
                                                : 'border border-red-500/30 bg-red-500/20 text-red-900',
                                        ]"
                                    >
                                        {{
                                            channel.is_active
                                                ? 'Active'
                                                : 'Inactive'
                                        }}
                                    </span>
                                </button>
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <div class="flex flex-col">
                                    <p
                                        class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                                    >
                                        {{ channel.type }}
                                    </p>
                                </div>
                            </td>
                            <td class="border-blue-gray-50 border-b p-0">
                                <div class="flex items-center gap-2">
                                    <button
                                        @click="editChannel(channel)"
                                        class="rounded-lg p-2 text-blue-600 transition-colors hover:bg-blue-50"
                                        title="Edit"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            class="h-4 w-4"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
                                            />
                                        </svg>
                                    </button>

                                    <button
                                        @click="confirmDelete(channel)"
                                        class="rounded-lg p-2 text-red-600 transition-colors hover:bg-red-50"
                                        title="Delete"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            class="h-4 w-4"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                            />
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                class="border-blue-gray-50 flex items-center justify-between border-t p-4"
            >
                <!-- Pagination info -->
                <div class="text-sm text-gray-600">
                    Showing {{ channels.from || 0 }} to
                    {{ channels.to || 0 }} of {{ channels.total || 0 }} entries
                </div>

                <!-- Pagination links -->
                <div class="flex flex-nowrap space-x-2">
                    <template
                        v-for="(link, index) in channels.links"
                        :key="index"
                    >
                        <button
                            v-if="link.url"
                            @click="reloadChannels(link.url)"
                            v-html="link.label"
                            class="inline-block min-w-[40px] rounded border px-3 py-1 text-center transition-all duration-200"
                            :class="{
                                'border-blue-500 bg-blue-500 text-white':
                                    link.active,
                                'border-gray-300 bg-white text-blue-500 hover:bg-gray-50':
                                    !link.active,
                            }"
                        />
                        <span
                            v-else
                            v-html="link.label"
                            class="inline-block min-w-[40px] cursor-not-allowed rounded border border-gray-300 bg-gray-200 px-3 py-1 text-center text-gray-500"
                        />
                    </template>
                </div>
            </div>
        </div>
    </AppLayout>

    <div
        v-if="isConfirmingDeletion"
        class="modal fixed inset-0 z-[60] flex items-center justify-center bg-black/50"
    >
        <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
            <h2 class="text-lg font-bold text-gray-900">Confirm Deletion</h2>
            <p class="mt-2 text-sm text-gray-600">
                This action <strong>cannot</strong> be undone. Please type
                <span class="font-mono font-bold text-red-600 underline">{{
                    channelToDelete?.name
                }}</span>
                to confirm.
            </p>

            <div class="mt-4">
                <input
                    v-model="confirmationName"
                    type="text"
                    :placeholder="channelToDelete?.name"
                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 sm:text-sm"
                    autocomplete="off"
                />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <button
                    @click="closeModal"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100"
                >
                    No, Keep it
                </button>
                <button
                    @click="deleteChannel"
                    :disabled="
                        isProcessing ||
                        confirmationName !== channelToDelete?.name
                    "
                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white transition-colors hover:bg-red-700 disabled:cursor-not-allowed disabled:bg-red-300"
                >
                    {{ isProcessing ? 'Deleting...' : 'Yes, Delete Channel' }}
                </button>
            </div>
        </div>
    </div>
</template>
<style scoped>
.loader {
    border: 2px solid #f3f3f3; /* Light grey background */
    border-top: 2px solid #3498db; /* Blue top border */
    border-radius: 50%; /* Make it round */
    width: 16px;
    height: 16px;
    animation: spin 1s linear infinite; /* Spin animation */
}

@keyframes spin {
    0% {
        transform: rotate(0deg);
    }
    100% {
        transform: rotate(360deg);
    }
}
</style>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import '../../../css/style.css';

import { type BreadcrumbItem } from '@/types';
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { onMounted, ref } from 'vue';

import '@inertiajs/core';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Clients',
        href: '/clients',
    },
];

declare module '@inertiajs/core' {
    interface PageProps {
        flash: {
            success?: string;
            error?: string;
        };
    }
}

const flashMessage = ref<string | null>(null);
const errors = ref<{ [key: string]: string[] }>({});
const flash = ref<{ success?: string; error?: string }>({});
const isProcessing = ref(false);

const clients = ref<any>({
    data: [],
    from: 0,
    to: 0,
    total: 0,
    links: [],
    current_page: 1,
    last_page: 1,
});

async function reloadClients(url?: string) {
    try {
        const endpoint = url || `${import.meta.env.VITE_APP_URL}/api/clients`;

        const { data } = await axios.get(endpoint, {
            headers: {
                Authorization: `Bearer ${localStorage.getItem('token')}`,
            },
        });

        clients.value = data.clients;
    } catch (err) {
        console.error('Failed to load clients', err);
    }
}

onMounted(() => {
    reloadClients(); // initial load
});

const pagination = ref<any>(null);

const showModal = ref(false);
const isEditing = ref(false);

const isConfirmingDeletion = ref(false);
const clientToDelete: any = ref(null);
const confirmationName = ref('');

const deleteForm = useForm({});

const confirmDelete = (client: any) => {
    console.log('Confirming delete for:', client.name);
    clientToDelete.value = client;
    confirmationName.value = '';
    isConfirmingDeletion.value = true;
};

const closeDeleteModal = () => {
    isConfirmingDeletion.value = false;
    clientToDelete.value = null;
};

const openModal = () => {
    isEditing.value = false;
    form.reset();
    showModal.value = true;
};

const closeModal = () => {
    console.log('Closing modal...');
    showModal.value = false;
    isEditing.value = false;
    resetForm();
};

defineProps({
    clients: {
        type: Object,
        required: true,
    },
});

const form: any = useForm({
    id: null,
    name: '',
    phone: '',
    email: '',
    address: '',
});

function resetForm() {
    form.id = null;
    form.name = '';
    form.phone = '';
    form.email = '';
    form.address = '';
    form.role = '';
}

// const editClient = (client: any) => {
//     isEditing.value = true;

//     // Fill the form with existing client data
//     form.id = client.id;
//     form.name = client.name;
//     form.phone = client.phone;
//     form.email = client.email;
//     form.address = client.address;
//     form.role = client.role;

//     showModal.value = true;
// };

const createClient = async () => {
    try {
        isProcessing.value = true;
        await axios.post(`${import.meta.env.VITE_APP_URL}/api/clients`, form);
        resetForm();
        closeModal();
        await reloadClients(); // refresh list
    } catch (err) {
        console.error('Failed to create client', err);
    } finally {
        isProcessing.value = false; // stop loading
    }
};

function showMessage(message: string) {
    flashMessage.value = message;
    setTimeout(() => (flashMessage.value = null), 3000); // auto-hide after 3s
}

const editClient = (client: any) => {
    isEditing.value = true;
    Object.assign(form, client);
    showModal.value = true;
};

const handleSubmit = async () => {
    try {
        isProcessing.value = true;
        let response;
        if (isEditing.value) {
            response = await axios.patch(
                `${import.meta.env.VITE_APP_URL}/api/clients/${form.id}`,
                form,

                {
                    headers: {
                        Authorization: `Bearer ${localStorage.getItem('token')}`, // if using Sanctum or JWT
                    },
                },
            );
        } else {
            response = await axios.post(
                `${import.meta.env.VITE_APP_URL}/api/clients`,
                form,

                {
                    headers: {
                        Authorization: `Bearer ${localStorage.getItem('token')}`, // if using Sanctum or JWT
                    },
                },
            );

            resetForm();
        }

        errors.value = {};
        showMessage(response.data.message);
        closeModal();
        await reloadClients();
    } catch (err: any) {
        errors.value = err.response.data.errors;
        console.error('Failed to submit employee', err);
    } finally {
        isProcessing.value = false;
    }
};

const deleteClient = async () => {
    try {
        isProcessing.value = true;
        let response;
        response = await axios.delete(
            `${import.meta.env.VITE_APP_URL}/api/clients/${clientToDelete.value.id}`,
            {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`, // if using Sanctum or JWT
                },
            },
        );
        showMessage(response.data.message);
        closeDeleteModal();
        await reloadClients();
    } catch (err) {
        console.error('Failed to delete client', err);
    } finally {
        isProcessing.value = false;
    }
};

const toggleClientStatus = async (client: any) => {
    try {
        isProcessing.value = true;
        let response;
        response = await axios.patch(
            `${import.meta.env.VITE_APP_URL}/api/clients/${client.id}/toggle-status`,
            {},
            {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`, // if using Sanctum or JWT
                },
            },
        );
        showMessage(response.data.message);
        await reloadClients();
    } catch (err) {
        console.error('Failed to toggle status', err);
    } finally {
        isProcessing.value = false;
    }
};

async function loadPage(url: string) {
    try {
        const { data } = await axios.get(
            url.replace(import.meta.env.VITE_APP_URL, ''),
        );
        clients.value = data.clients.data;
        pagination.value = {
            from: data.clients.from,
            to: data.clients.to,
            total: data.clients.total,
            links: data.clients.links,
            current_page: data.clients.current_page,
            last_page: data.clients.last_page,
        };
    } catch (err) {
        console.error('Failed to load page', err);
    }
}
</script>

<template>
    <!-- <AppLayout :breadcrumbs="breadcrumbs"> -->
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
                            Clients
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col gap-2 sm:flex-row">
                        <button
                            class="rounded-lg border border-gray-900 px-4 py-2 text-center align-middle font-sans text-xs font-bold text-gray-900 uppercase transition-all select-none hover:opacity-75 focus:ring focus:ring-gray-300 active:opacity-[0.85] disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none"
                            type="button"
                            @click="openModal"
                        >
                            Add Client
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
                                        <h2 class="text-lg font-bold">
                                            {{
                                                isEditing
                                                    ? 'Edit Client'
                                                    : 'Add Client'
                                            }}
                                        </h2>

                                        <div class="grid gap-4">
                                            <div class="grid gap-3">
                                                <Label for="name-1">Name</Label>
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
                                                    >Contact</Label
                                                >
                                                <input
                                                    id="name-1"
                                                    default-value="Pedro Duarte"
                                                    v-model="form.phone"
                                                />
                                                <p
                                                    v-if="errors.phone"
                                                    class="text-sm text-red-600"
                                                >
                                                    {{ errors.phone[0] }}
                                                </p>
                                            </div>
                                            <div class="grid gap-3">
                                                <Label for="name-1"
                                                    >Email</Label
                                                >
                                                <input
                                                    id="name-1"
                                                    default-value="Pedro Duarte"
                                                    v-model="form.email"
                                                />
                                                <p
                                                    v-if="errors.email"
                                                    class="text-sm text-red-600"
                                                >
                                                    {{ errors.email[0] }}
                                                </p>
                                            </div>
                                            <div class="grid gap-3">
                                                <Label for="name-1"
                                                    >Address</Label
                                                >
                                                <textarea
                                                    rows="7"
                                                    v-model="form.address"
                                                ></textarea>
                                                <p
                                                    v-if="errors.address"
                                                    class="text-sm text-red-600"
                                                >
                                                    {{ errors.address[0] }}
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
                                                    type="submit"
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
                                                                  ? 'Update Client'
                                                                  : 'Add Client'
                                                        }}
                                                    </span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

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
                            class="border-blue-gray-100 border-y p-4 font-sans text-sm font-normal opacity-70"
                        >
                            Name
                        </th>
                        <th
                            class="border-blue-gray-100 border-y p-4 font-sans text-sm font-normal opacity-70"
                        >
                            Contact
                        </th>
                        <th
                            class="border-blue-gray-100 border-y p-4 font-sans text-sm font-normal opacity-70"
                        >
                            Email
                        </th>
                        <th
                            class="border-blue-gray-100 border-y p-4 font-sans text-sm font-normal opacity-70"
                        >
                            Address
                        </th>
                        <th
                            class="border-blue-gray-100 border-y p-4 font-sans text-sm font-normal opacity-70"
                        >
                            Status
                        </th>
                        <th
                            class="border-blue-gray-100 border-y p-2 font-sans text-sm font-normal opacity-70"
                        >
                            Action
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="client in clients.data"
                        :key="client.id"
                        class="hover:bg-gray-50/50"
                    >
                        <td class="border-blue-gray-50 border-b p-4">
                            <div class="flex items-center gap-3">
                                <div class="flex flex-col">
                                    <p
                                        class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                                    >
                                        {{ client.name }}
                                    </p>
                                </div>
                            </div>
                        </td>
                        <td class="border-blue-gray-50 border-b p-4">
                            <div class="flex flex-col">
                                <p
                                    class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                                >
                                    {{ client.phone }}
                                </p>
                            </div>
                        </td>
                        <td class="border-blue-gray-50 border-b p-0">
                            <div class="w-max">
                                <div
                                    class="relative grid items-center px-2 py-1 font-sans font-normal"
                                >
                                    {{ client.email }}
                                </div>
                            </div>
                        </td>
                        <td class="border-blue-gray-50 border-b p-4">
                            <p
                                class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                            >
                                {{ client.address ?? 'N/A' }}
                            </p>
                        </td>

                        <td class="border-blue-gray-50 border-b p-4">
                            <div class="flex gap-2">
                                <button
                                    @click="toggleClientStatus(client)"
                                    type="button"
                                    :title="
                                        client.is_active
                                            ? 'Deactivate Client'
                                            : 'Activate Client'
                                    "
                                    class="transition-transform active:scale-95"
                                >
                                    <span
                                        :class="[
                                            'cursor-pointer rounded-full px-2 py-1 text-xs font-bold uppercase',
                                            client.is_active
                                                ? 'border border-green-500/30 bg-green-500/20 text-green-900'
                                                : 'border border-red-500/30 bg-red-500/20 text-red-900',
                                        ]"
                                    >
                                        {{
                                            client.is_active
                                                ? 'Active'
                                                : 'Inactive'
                                        }}
                                    </span>
                                </button>
                            </div>
                        </td>
                        <td class="border-blue-gray-50 border-b p-0">
                            <div class="flex items-center gap-2">
                                <button
                                    @click="editClient(client)"
                                    class="relative h-10 w-10 rounded-lg text-blue-600 transition-all hover:bg-blue-50"
                                    type="button"
                                    title="Edit Client"
                                >
                                    <span
                                        class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2"
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
                                    </span>
                                </button>

                                <button
                                    @click="confirmDelete(client)"
                                    class="relative h-10 w-10 rounded-lg text-red-600 transition-all hover:bg-red-50"
                                    type="button"
                                    title="Delete Client"
                                >
                                    <span
                                        class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2"
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
                                    </span>
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div
                class="border-blue-gray-50 flex items-center justify-between border-t p-4"
            >
                <!-- Pagination info -->
                <div class="text-sm text-gray-600">
                    Showing {{ clients.from || 0 }} to {{ clients.to || 0 }} of
                    {{ clients.total || 0 }} entries
                </div>

                <!-- Pagination links -->
                <div class="flex flex-nowrap space-x-2">
                    <template
                        v-for="(link, index) in clients.links"
                        :key="index"
                    >
                        <button
                            v-if="link.url"
                            @click="reloadClients(link.url)"
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
        class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50"
    >
        <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
            <h2 class="text-lg font-bold text-gray-900">Confirm Deletion</h2>
            <p class="mt-2 text-sm text-gray-600">
                This action <strong>cannot</strong> be undone. Please type
                <span class="font-mono font-bold text-red-600 underline">{{
                    clientToDelete?.name
                }}</span>
                to confirm.
            </p>

            <div class="mt-4">
                <input
                    v-model="confirmationName"
                    type="text"
                    class="w-full rounded-md border-gray-300 shadow-sm"
                    :placeholder="clientToDelete?.name"
                />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <button
                    @click="closeDeleteModal"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100"
                >
                    No, Keep it
                </button>
                <button
                    @click="deleteClient"
                    :disabled="confirmationName !== clientToDelete?.name"
                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white transition-colors hover:bg-red-700 disabled:cursor-not-allowed disabled:bg-red-300"
                >
                    {{
                        deleteForm.processing
                            ? 'Deleting...'
                            : 'Yes, Delete Client'
                    }}
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

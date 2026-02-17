<script setup lang="ts">
import Label from '@/components/ui/label/Label.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import '../../../css/style.css';

// 1. Data States
const showModal = ref(false);
const isEditing = ref(false);
const channels = ref<any[]>([]);
const clients = ref<any[]>([]);
const employeesList = ref<any[]>([]);
const showDeleteModal = ref(false);
const employeeToDelete = ref<number | null>(null);
const isProcessing = ref(false);
const loading = ref(false);

const flashMessage = ref<string | null>(null);
const errors = ref<Record<string, string[]>>({});

function showMessage(message: string) {
    flashMessage.value = message;
    setTimeout(() => (flashMessage.value = null), 3000); // auto-hide after 3s
}

const employees = ref<any>({
    data: [],
    from: 0,
    to: 0,
    total: 0,
    links: [],
});

// 2. Fetch Data
const reloadEmployees = async (url?: string) => {
    try {
        const params = new URLSearchParams(window.location.search);
        const status = params.get('status'); // "online" or "offline"

        // If a pagination link is clicked, use that URL directly
        const endpoint = url || `${import.meta.env.VITE_APP_URL}/api/employees`;

        const { data } = await axios.get(endpoint, {
            params: { status }, // keep filter applied
            headers: {
                Authorization: `Bearer ${localStorage.getItem('token')}`,
            },
        });

        console.log('Employees API response:', data);
        employees.value = data.employees; // full paginator object
        employeesList.value = data.employees.data; // just the records
    } catch (e) {
        console.error('Error fetching employees', e);
    }
};

const handleChannels = async () => {
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/channels/show`,
            {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                },
            },
        );
        channels.value = data;
    } catch (e) {
        console.error('Error fetching channels', e);
    }
};

const handleClients = async () => {
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

onMounted(() => {
    reloadEmployees();
    handleClients();
    handleChannels();
});

// 3. Form Initialization
const form = ref({
    id: null,
    name: '',
    email: '',
    phone: '',
    occupation: '',
    channel_id: '',
    client_id: '',
    password: '',
    role: 'employee',
});

// 4. Computed Channels
const filteredChannels = computed(() => {
    if (!form.value.client_id) return [];
    return channels.value.filter(
        (channel) => channel.client_id == form.value.client_id,
    );
});

// 5. Modal Logic
const openModal = () => {
    isEditing.value = false;
    Object.assign(form.value, {
        id: null,
        name: '',
        email: '',
        phone: '',
        occupation: '',
        channel_id: '',
        client_id: '',
        password: '',
        role: 'employee',
    });
    showModal.value = true;
};

const editEmployee = (employee: any) => {
    isEditing.value = true;
    form.value.id = employee.id;
    form.value.name = employee.user.name;
    form.value.email = employee.user.email;
    form.value.phone = employee.user.phone;
    form.value.occupation = employee.user.occupation;
    form.value.client_id = employee.client_id;
    form.value.channel_id = employee.channel?.[0]?.id || '';
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
};

// 7. Delete Logic
const confirmDelete = (id: number) => {
    employeeToDelete.value = id;
    showDeleteModal.value = true;
};

const submitEmployee = async () => {
    try {
        loading.value = true;
        if (isEditing.value) {
            const { data } = await axios.put(
                `${import.meta.env.VITE_APP_URL}/api/employees/${form.value.id}`,
                form.value,
                {
                    headers: {
                        Authorization: `Bearer ${localStorage.getItem('token')}`,
                    },
                },
            );
            showMessage(data.message);
        } else {
            const { data } = await axios.post(
                `${import.meta.env.VITE_APP_URL}/api/employees`,
                form.value,
                {
                    headers: {
                        Authorization: `Bearer ${localStorage.getItem('token')}`,
                    },
                },
            );
            showMessage(data.message);
            errors.value = {};

            // ✅ manually clear fields only for new employee
            Object.assign(form.value, {
                id: null,
                name: '',
                email: '',
                phone: '',
                occupation: '',
                channel_id: '',
                client_id: '',
                password: '',
                role: 'employee',
            });
        }

        closeModal(); // just hides the modal
        await reloadEmployees();
    } catch (err: any) {
        errors.value = err.response.data.errors;
        console.error('Failed to submit employee', err);
    } finally {
        loading.value = false;
    }
};

const executeDelete = async () => {
    try {
        const { data } = await axios.delete(
            `${import.meta.env.VITE_APP_URL}/api/employees/${employeeToDelete.value}`,
            {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                },
            },
        );
        showMessage(data.message);
        showDeleteModal.value = false;
        employeeToDelete.value = null;
        await reloadEmployees();
    } catch (err) {
        console.error('Failed to delete employee', err);
    }
};

const toggleStatus = async (employee: any) => {
    try {
        const { data } = await axios.patch(
            `${import.meta.env.VITE_APP_URL}/api/users/${employee.user_id}/toggle-status`,
            {},
            {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                },
            },
        );
        showMessage(data.message);
        await reloadEmployees();
    } catch (err) {
        console.error('Failed to toggle status', err);
    }
};
</script>

<template>
    <Head title="Users" />

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
                            Users
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col gap-2 sm:flex-row">
                        <button
                            class="rounded-lg border border-gray-900 px-4 py-2 text-center align-middle font-sans text-xs font-bold text-gray-900 uppercase transition-all select-none hover:opacity-75 focus:ring focus:ring-gray-300 active:opacity-[0.85] disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none"
                            type="button"
                            @click="openModal"
                        >
                            Add User
                        </button>
                        <form @submit.prevent="submitEmployee()">
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
                                                    ? 'Edit User'
                                                    : 'Add User'
                                            }}
                                        </h2>

                                        <div class="grid gap-4">
                                            <div
                                                class="grid grid-cols-1 gap-4 md:grid-cols-2"
                                            >
                                                <div class="grid gap-2">
                                                    <Label for="name"
                                                        >Name</Label
                                                    >
                                                    <input
                                                        id="name"
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
                                                <div class="grid gap-2">
                                                    <Label for="email"
                                                        >Email</Label
                                                    >
                                                    <input
                                                        id="email"
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
                                            </div>

                                            <div
                                                class="grid grid-cols-1 gap-4 md:grid-cols-2"
                                            >
                                                <div class="grid gap-2">
                                                    <Label for="contact"
                                                        >Contact
                                                    </Label>
                                                    <input
                                                        id="contact"
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
                                                <div class="grid gap-2">
                                                    <Label for="role"
                                                        >Role / Occupation
                                                    </Label>
                                                    <input
                                                        id="role"
                                                        default-value="Pedro Duarte"
                                                        v-model="
                                                            form.occupation
                                                        "
                                                    />
                                                    <p
                                                        v-if="errors.occupation"
                                                        class="text-sm text-red-600"
                                                    >
                                                        {{
                                                            errors.occupation[0]
                                                        }}
                                                    </p>
                                                </div>
                                            </div>

                                            <div class="grid gap-2">
                                                <Label for="clients"
                                                    >Client</Label
                                                >
                                                <select
                                                    id="clients"
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
                                                <Label for="channels"
                                                    >Channel
                                                </Label>
                                                <select
                                                    v-model="form.channel_id"
                                                    id="channels"
                                                    class="focus:ring-opacity-50 mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200"
                                                >
                                                    <option value="" disabled>
                                                        -- Choose channel --
                                                    </option>
                                                    <option
                                                        v-for="channel in filteredChannels"
                                                        :key="channel.id"
                                                        :value="channel.id"
                                                        selected
                                                    >
                                                        {{ channel.name }}
                                                    </option>
                                                </select>
                                                <p
                                                    v-if="errors.channel_id"
                                                    class="text-sm text-red-600"
                                                >
                                                    {{ errors.channel_id[0] }}
                                                </p>
                                            </div>

                                            <div class="grid gap-3">
                                                <Label for="password"
                                                    >Set New Password</Label
                                                >
                                                <input
                                                    id="password"
                                                    default-value="Pedro Duarte"
                                                    v-model="form.password"
                                                />
                                                <p
                                                    v-if="errors.password"
                                                    class="text-sm text-red-600"
                                                >
                                                    {{ errors.password[0] }}
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
                                                    :disabled="loading"
                                                >
                                                    <!-- Spinner only when loading -->
                                                    <span
                                                        v-if="loading"
                                                        class="loader mr-2"
                                                    ></span>

                                                    <!-- Label changes depending on loading -->
                                                    <span>
                                                        {{
                                                            loading
                                                                ? isEditing
                                                                    ? 'Updating...'
                                                                    : 'Adding...'
                                                                : isEditing
                                                                  ? 'Update User'
                                                                  : 'Add User'
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
                                Assigned Client
                            </th>
                            <th
                                class="border-blue-gray-100 border-y p-4 font-sans text-sm font-normal opacity-70"
                            >
                                Role
                            </th>
                            <th
                                class="border-blue-gray-100 border-y p-4 font-sans text-sm font-normal opacity-70"
                            >
                                Channels
                            </th>
                            <th
                                class="border-blue-gray-100 border-y p-4 font-sans text-sm font-normal opacity-70"
                            >
                                Online / Offline
                            </th>
                            <th
                                class="border-blue-gray-100 border-y p-4 font-sans text-sm font-normal opacity-70"
                            >
                                Account
                            </th>
                            <th
                                class="border-blue-gray-100 border-y p-2 font-sans text-sm font-normal opacity-70"
                            >
                                Action
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!employeesList || employeesList.length === 0">
                            <td
                                colspan="9"
                                class="p-4 text-center text-gray-500"
                            >
                                No employees found.
                            </td>
                        </tr>
                        <tr
                            v-for="employee in employeesList"
                            :key="employee.id"
                            class="hover:bg-gray-50/50"
                        >
                            <td class="border-blue-gray-50 border-b p-4">
                                <div class="flex flex-col">
                                    <p
                                        class="text-blue-gray-900 text-sm font-bold"
                                    >
                                        {{ employee.user.name }}
                                    </p>
                                    <p
                                        class="text-blue-gray-900 text-sm opacity-70"
                                    >
                                        {{ employee.user.email }}
                                    </p>
                                </div>
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <div class="flex flex-col">
                                    <p class="text-blue-gray-900 text-sm">
                                        {{ employee.user.phone }}
                                    </p>
                                </div>
                            </td>
                            <td
                                class="border-blue-gray-50 border-b p-4 text-sm"
                            >
                                {{
                                    employee.client
                                        ? employee.client.name
                                        : 'No Client Assigned'
                                }}
                            </td>
                            <td
                                class="border-blue-gray-50 border-b p-4 text-sm"
                            >
                                {{ employee.user.occupation }}
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-for="c in employee.channel"
                                        :key="c.id"
                                        :class="[
                                            'flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-semibold shadow-sm',
                                            c.pivot.is_online
                                                ? 'border-green-200 bg-green-50 text-green-700'
                                                : 'border-gray-200 bg-gray-50 text-gray-500',
                                        ]"
                                        :title="
                                            'Last seen: ' + c.pivot.last_seen
                                        "
                                    >
                                        <span
                                            :class="[
                                                'h-2 w-2 rounded-full',
                                                c.pivot.is_online
                                                    ? 'animate-pulse bg-green-500'
                                                    : 'bg-gray-400',
                                            ]"
                                        ></span>

                                        {{ c.name }}
                                    </span>
                                </div>
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <span
                                    :class="[
                                        'rounded-full px-2 py-1 text-xs font-bold uppercase',
                                        employee.user.status === 'online'
                                            ? 'border border-green-500/30 bg-green-500/20 text-green-900'
                                            : 'border border-red-500/30 bg-red-500/20 text-red-900',
                                    ]"
                                >
                                    {{
                                        employee.user.status === 'online'
                                            ? 'Online'
                                            : 'Offline'
                                    }}
                                </span>
                            </td>

                            <td class="border-blue-gray-50 border-b p-4">
                                <button
                                    @click="toggleStatus(employee)"
                                    :title="
                                        employee.user.is_active
                                            ? 'Deactivate Account'
                                            : 'Activate Account'
                                    "
                                    class="transition-opacity transition-transform hover:opacity-80 active:scale-95"
                                >
                                    <span
                                        :class="[
                                            'cursor-pointer rounded-full px-2 py-1 text-xs font-bold uppercase',
                                            employee.user.is_active
                                                ? 'border border-green-500/30 bg-green-500/20 text-green-900'
                                                : 'border border-red-500/30 bg-red-500/20 text-red-900',
                                        ]"
                                    >
                                        {{
                                            employee.user.is_active
                                                ? 'Active'
                                                : 'Inactive'
                                        }}
                                    </span>
                                </button>
                            </td>
                            <td class="border-blue-gray-50 border-b p-0">
                                <div class="flex items-center gap-2">
                                    <button
                                        @click="editEmployee(employee)"
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
                                        @click="confirmDelete(employee.id)"
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
                <div class="text-sm text-gray-600">
                    Showing {{ employees.from || 0 }} to
                    {{ employees.to || 0 }} of {{ employees.total }} entries
                </div>

                <div class="flex flex-nowrap space-x-2">
                    <template
                        v-for="(link, index) in employees.links"
                        :key="index"
                    >
                        <button
                            v-if="link.url"
                            @click="reloadEmployees(link.url)"
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
        v-if="showDeleteModal"
        class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 backdrop-blur-sm"
    >
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-2xl">
            <h2 class="text-lg font-bold text-gray-900">Confirm Deletion</h2>
            <p class="mt-2 text-sm text-gray-500">
                Are you sure you want to delete this employee? This action is
                permanent and all associated data will be removed.
            </p>

            <div class="mt-6 flex justify-center gap-3">
                <button
                    @click="showDeleteModal = false"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100"
                >
                    No, Keep it
                </button>
                <button
                    @click="executeDelete"
                    class="rounded-lg bg-red-600 px-6 py-2 text-sm font-bold text-white shadow-md transition-all hover:bg-red-700 active:scale-95"
                >
                    Yes, Delete Employee
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

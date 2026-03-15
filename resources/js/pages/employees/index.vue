<script setup lang="ts">
import Label from '@/components/ui/label/Label.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import 'intl-tel-input/build/css/intlTelInput.css';
import { computed, onMounted, ref, watch } from 'vue';
import Multiselect from 'vue-multiselect';
import { VueTelInput } from 'vue-tel-input';
import 'vue-tel-input/vue-tel-input.css';

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
const selectedRole = ref('');

const flashMessage = ref<string | null>(null);
const errors = ref<Record<string, string[]>>({});

const map = ref(null);
const marker = ref(null);

const addressSuggestions = ref([]);
const showSuggestions = ref(false);
let debounceTimeout: any = null;

const handleAddressSearch = (event: any) => {
    const query = event.target.value;

    // Clear previous timeout
    clearTimeout(debounceTimeout);

    if (query.length < 3) {
        addressSuggestions.value = [];
        return;
    }

    // Wait 500ms after typing stops to search
    debounceTimeout = setTimeout(async () => {
        try {
            const response = await fetch(
                `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&addressdetails=1&limit=5`,
            );
            addressSuggestions.value = await response.json();
            showSuggestions.value = true;
        } catch (error) {
            console.error('Search error:', error);
        }
    }, 500);
};

const selectAddress = (item) => {
    // 1. Set Coordinates
    form.value.latitude = item.lat;
    form.value.longitude = item.lon;

    // 2. Map Address Details
    form.value.address_line_1 = item.display_name;

    // Extract suburb or city
    const addr = item.address;
    form.value.suburb =
        addr.suburb ||
        addr.neighbourhood ||
        addr.city_district ||
        addr.town ||
        '';

    // Close dropdown
    showSuggestions.value = false;
    addressSuggestions.value = [];
};

function showMessage(message: string) {
    flashMessage.value = message;
    setTimeout(() => (flashMessage.value = null), 3000); // auto-hide after 3s
}

const roleGroups = [
    {
        label: 'Residents',
        options: [
            { text: 'Household', value: 'household' },
            { text: 'Resident', value: 'resident' },
        ],
    },
    {
        label: 'System & Management',
        options: [
            { text: 'Field Unit (Default)', value: 'field_unit' },
            { text: 'Supervisor', value: 'supervisor' },
            { text: 'Dispatch / Base Station', value: 'dispatch' },
            { text: 'Site Manager', value: 'site_manager' },
            { text: 'System Administrator', value: 'admin' },
            { text: 'Operations Controller', value: 'ops_controller' }, // new
        ],
    },
    {
        label: 'Security & Safety',
        options: [
            { text: 'Security Guard', value: 'security_guard' },
            { text: 'Patrol Officer', value: 'patrol_officer' },
            { text: 'Loss Prevention', value: 'loss_prevention' },
            { text: 'First Responder', value: 'first_responder' },
            { text: 'Safety Officer', value: 'safety_officer' }, // moved here
            { text: 'Emergency Coordinator', value: 'emergency_coordinator' }, // new
        ],
    },
    {
        label: 'Operations & Logistics',
        options: [
            { text: 'Maintenance Technician', value: 'maintenance' },
            { text: 'Warehouse Operative', value: 'warehouse' },
            { text: 'Forklift Operator', value: 'forklift' },
            { text: 'Fleet Driver', value: 'fleet_driver' },
            { text: 'Logistics Coordinator', value: 'logistics_coordinator' }, // new
        ],
    },
    {
        label: 'Hospitality & Services',
        options: [
            { text: 'Housekeeping', value: 'housekeeping' },
            { text: 'Front Desk / Concierge', value: 'front_desk' },
            { text: 'Event Staff', value: 'event_staff' },
            { text: 'Janitorial', value: 'janitorial' },
            { text: 'Customer Service Liaison', value: 'customer_service' }, // new
        ],
    },
    {
        label: 'Medical & Emergency',
        options: [
            { text: 'Paramedic', value: 'paramedic' },
            { text: 'Medic', value: 'medic' },
            { text: 'Firefighter', value: 'firefighter' },
        ],
    },
];

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

        console.log('employeesList:', employeesList.value[0].channels);
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
    phone: '+27',
    occupation: '',
    // channel_id: '',
    channel_ids: [] as number[],
    client_id: '',
    password: '',
    role: 'employee',
    // New Fields
    address_line_1: '',
    complex_name: '',
    suburb: '',
    access_code: '',
    latitude: null,
    longitude: null,
});

// Watch the occupation and update the role automatically
watch(
    () => form.value.occupation,
    (newVal) => {
        if (newVal === 'household') {
            form.value.role = 'household';
        } else if (newVal === 'resident') {
            form.value.role = 'resident';
        } else {
            form.value.role = 'employee';
        }
    },
);

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

    form.value.client_id = employee.client_id;
    form.value.channel_ids = employee.channels || []; // ✅ keep objects

    form.value.id = employee.id;
    form.value.name = employee.user.name;
    form.value.email = employee.user.email;
    form.value.phone = employee.user.phone;
    form.value.occupation = employee.user.occupation;

    // Set the role (this triggers the v-if in your template)
    form.value.role = employee.user.role || 'employee';

    // Preload Household fields
    form.value.address_line_1 = employee.user.address_line_1 || '';
    form.value.complex_name = employee.user.complex_name || '';
    form.value.suburb = employee.user.suburb || '';
    form.value.access_code = employee.user.access_code || '';
    form.value.latitude = employee.user.latitude || null;
    form.value.longitude = employee.user.longitude || null;

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

        // Ensure only IDs are sent
        const payload = {
            ...form.value,
            channel_ids: form.value.channel_ids.map((c: any) => c.id),
        };

        if (isEditing.value) {
            const { data } = await axios.put(
                `${import.meta.env.VITE_APP_URL}/api/employees/${form.value.id}`,
                payload, // ✅ use payload here
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
                payload, // ✅ use payload here
                {
                    headers: {
                        Authorization: `Bearer ${localStorage.getItem('token')}`,
                    },
                },
            );
            showMessage(data.message);
            errors.value = {};

            Object.assign(form.value, {
                id: null,
                name: '',
                email: '',
                phone: '',
                occupation: '',
                channel_ids: [], // ✅ reset array
                client_id: '',
                password: '',
                role: 'employee',
            });
        }

        closeModal();
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

// Place this in your <script setup>
watch(
    () => form.occupation,
    (newVal) => {
        if (newVal && !selectedRole.value) {
            // Only hydrate if not already set by the user
            const allOptions = roleGroups.flatMap((g) => g.options);
            selectedRole.value =
                allOptions.find((o) => o.value === newVal) || null;
        }
    },
    { immediate: true },
);

const handlePhoneInput = (val: string) => {
    // 1. If the field is empty or they deleted the prefix, force it back
    if (!val || !val.startsWith('+27')) {
        form.phone = '+27';
        return;
    }

    // 2. Remove any spaces or illegal characters immediately
    const cleanValue = val.replace(/\s+/g, '').replace(/[^0-9+]/g, '');

    // 3. Update the form with the clean version
    form.phone = cleanValue;
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
                                    class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 py-8 backdrop-blur-[2px]"
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

                                        <div class="mb-4 grid gap-2">
                                            <div class="form-group">
                                                <!-- <input
                                                        id="role"
                                                        default-value="Pedro Duarte"
                                                        v-model="
                                                            form.occupation
                                                        "
                                                    /> -->
                                                <label for="role-select"
                                                    >Assign User Role:</label
                                                >

                                                <Multiselect
                                                    v-model="selectedRole"
                                                    :options="roleGroups"
                                                    :multiple="false"
                                                    :searchable="true"
                                                    :close-on-select="true"
                                                    :show-labels="false"
                                                    group-values="options"
                                                    group-label="label"
                                                    placeholder="Select a role..."
                                                    track-by="value"
                                                    label="text"
                                                    @select="
                                                        (option) => {
                                                            form.occupation =
                                                                option.value;
                                                        }
                                                    "
                                                    @remove="
                                                        () => {
                                                            form.occupation =
                                                                '';
                                                        }
                                                    "
                                                />
                                            </div>

                                            <p
                                                v-if="errors.occupation"
                                                class="text-sm text-red-600"
                                            >
                                                {{ errors.occupation[0] }}
                                            </p>
                                        </div>
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
                                                    <div class="grid gap-2">
                                                        <Label for="contact"
                                                            >Phone</Label
                                                        >

                                                        <VueTelInput
                                                            v-model="form.phone"
                                                            mode="international"
                                                            :onlyCountries="[
                                                                'ZA',
                                                            ]"
                                                            defaultCountry="ZA"
                                                            :autoFormat="true"
                                                            :inputOptions="{
                                                                showDialCode: true,
                                                                placeholder:
                                                                    '+27821234567',
                                                            }"
                                                            @input="
                                                                handlePhoneInput
                                                            "
                                                            class="h-10 rounded-md border-gray-300 shadow-sm"
                                                        />
                                                    </div>

                                                    <!-- <input
                                                        id="contact"
                                                        default-value="Pedro Duarte"
                                                        v-model="form.phone"
                                                    /> -->
                                                    <p
                                                        v-if="errors.phone"
                                                        class="text-sm text-red-600"
                                                    >
                                                        {{ errors.phone[0] }}
                                                    </p>
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
                                                        <option
                                                            value=""
                                                            disabled
                                                        >
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
                                                        {{
                                                            errors.client_id[0]
                                                        }}
                                                    </p>
                                                </div>
                                            </div>

                                            <div class="grid gap-3">
                                                <Label for="channels"
                                                    >Channels</Label
                                                >
                                                <Multiselect
                                                    v-model="form.channel_ids"
                                                    :options="filteredChannels"
                                                    :multiple="true"
                                                    :close-on-select="false"
                                                    :clear-on-select="false"
                                                    :preserve-search="true"
                                                    placeholder="Select channels..."
                                                    label="name"
                                                    track-by="id"
                                                />

                                                <p
                                                    v-if="errors.channel_ids"
                                                    class="text-sm text-red-600"
                                                >
                                                    {{ errors.channel_ids[0] }}
                                                </p>
                                            </div>

                                            <div
                                                v-if="
                                                    form.role === 'household' ||
                                                    form.role === 'resident'
                                                "
                                                class="mt-4 border-t pt-4"
                                            >
                                                <h3
                                                    class="mb-3 text-sm font-semibold tracking-wider text-gray-900 uppercase"
                                                >
                                                    Household Details
                                                </h3>

                                                <div
                                                    class="relative mb-4 grid gap-2"
                                                >
                                                    <Label for="address_search"
                                                        >Search Address
                                                        (Free)</Label
                                                    >
                                                    <div class="relative">
                                                        <input
                                                            id="address_search"
                                                            type="text"
                                                            placeholder="Type your street address..."
                                                            class="w-full rounded-md border-gray-300 pl-10 shadow-sm"
                                                            @input="
                                                                handleAddressSearch
                                                            "
                                                            @blur="
                                                                setTimeout(
                                                                    () =>
                                                                        (showSuggestions = false),
                                                                    200,
                                                                )
                                                            "
                                                        />
                                                        <span
                                                            class="absolute top-2.5 left-3 text-gray-400"
                                                        >
                                                            <i
                                                                class="fas fa-search-location"
                                                            ></i>
                                                        </span>
                                                    </div>

                                                    <ul
                                                        v-if="
                                                            showSuggestions &&
                                                            addressSuggestions.length
                                                        "
                                                        class="absolute top-full z-50 mt-1 max-h-60 w-full overflow-auto rounded-md border border-gray-200 bg-white shadow-xl"
                                                    >
                                                        <li
                                                            v-for="item in addressSuggestions"
                                                            :key="item.place_id"
                                                            @click="
                                                                selectAddress(
                                                                    item,
                                                                )
                                                            "
                                                            class="cursor-pointer border-b px-4 py-3 text-sm last:border-0 hover:bg-gray-50"
                                                        >
                                                            <div
                                                                class="font-medium text-gray-800"
                                                            >
                                                                {{
                                                                    item.display_name
                                                                }}
                                                            </div>
                                                        </li>
                                                    </ul>
                                                </div>

                                                <div
                                                    class="grid grid-cols-1 gap-4 md:grid-cols-2"
                                                >
                                                    <div class="grid gap-2">
                                                        <Label
                                                            for="address_line_1"
                                                            >Street
                                                            Address</Label
                                                        >
                                                        <input
                                                            id="address_line_1"
                                                            v-model="
                                                                form.address_line_1
                                                            "
                                                            placeholder="e.g. 123 Maple Ave"
                                                        />
                                                        <p
                                                            v-if="
                                                                errors.address_line_1
                                                            "
                                                            class="text-sm text-red-600"
                                                        >
                                                            {{
                                                                errors
                                                                    .address_line_1[0]
                                                            }}
                                                        </p>
                                                    </div>
                                                    <div class="grid gap-2">
                                                        <Label for="complex"
                                                            >Complex/Estate Name
                                                            (Optional)</Label
                                                        >
                                                        <input
                                                            id="complex"
                                                            v-model="
                                                                form.complex_name
                                                            "
                                                            placeholder="e.g. Green Valley Estate"
                                                        />
                                                    </div>
                                                </div>

                                                <div
                                                    class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2"
                                                >
                                                    <div class="grid gap-2">
                                                        <Label for="suburb"
                                                            >Suburb/Area</Label
                                                        >
                                                        <input
                                                            id="suburb"
                                                            v-model="
                                                                form.suburb
                                                            "
                                                            placeholder="e.g. Morningside"
                                                        />
                                                        <p
                                                            v-if="errors.suburb"
                                                            class="text-sm text-red-600"
                                                        >
                                                            {{
                                                                errors.suburb[0]
                                                            }}
                                                        </p>
                                                    </div>
                                                </div>

                                                <!-- <div
                                                    v-if="form.latitude"
                                                    class="mt-2 flex items-center text-[10px] text-green-600"
                                                >
                                                    <i
                                                        class="fas fa-check-circle mr-1"
                                                    ></i>
                                                    Location pinned:
                                                    {{ form.latitude }},
                                                    {{ form.longitude }}
                                                </div> -->
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
                            <td
                                class="border-blue-gray-50 border-b p-4 align-top"
                            >
                                <div class="flex max-w-[200px] flex-wrap gap-1">
                                    <span
                                        v-for="c in employee.channels"
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
.custom-select {
    width: 100%;
    padding: 8px;
    border-radius: 4px;
    border: 1px solid #ccc;
    background-color: white;
}
optgroup {
    font-weight: bold;
    color: #666;
    font-style: italic;
}
option {
    font-style: normal;
    color: black;
}
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

/* Force the library to respect the height and show the borders */
.vue-tel-input {
    display: flex !important;
    background-color: white;
    min-height: 40px; /* This is h-10 */
    border: 1px solid #d1d5db !important; /* border-gray-300 */
}

/* Fix the internal input field */
.vti__input {
    background: transparent !important;
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
}
/* 1. Force the container to be visible and have the right height */
:deep(.custom-tel-input) {
    display: flex !important;
    height: 40px !important; /* This matches h-10 */
    border-radius: 6px;
    border: 1px solid #d1d5db !important; /* gray-300 */
    background-color: white;
}

/* 2. Fix the input field inside to not have its own weird borders */
:deep(.vti__input) {
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
    font-size: 0.875rem; /* text-sm */
}

/* 3. Fix the dropdown part so it doesn't look squashed */
:deep(.vti__dropdown) {
    border-radius: 6px 0 0 6px;
}
</style>
<style src="vue-multiselect/dist/vue-multiselect.min.css"></style>

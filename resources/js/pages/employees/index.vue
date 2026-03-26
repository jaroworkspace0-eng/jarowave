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

// ─── helpers ──────────────────────────────────────────────────────────────────
const generatePin = () => String(Math.floor(100000 + Math.random() * 900000));

const isHouseholdRole = (role: string) =>
    role === 'household' || role === 'resident';

// ─── data states ──────────────────────────────────────────────────────────────
const showModal = ref(false);
const isEditing = ref(false);
const channels = ref<any[]>([]);
const clients = ref<any[]>([]);
const employeesList = ref<any[]>([]);
const showDeleteModal = ref(false);
const employeeToDelete = ref<number | null>(null);
const loading = ref(false);
const selectedRole = ref('');
const flashMessage = ref<string | null>(null);
const errors = ref<Record<string, string[]>>({});
const addressSuggestions = ref([]);
const showSuggestions = ref(false);
const inComplex = ref(false);
let debounceTimeout: any = null;
const confirmToggleEmployee = ref<any>(null);

const employees = ref<any>({
    data: [],
    from: 0,
    to: 0,
    total: 0,
    links: [],
});

// ─── address search ───────────────────────────────────────────────────────────
// const handleAddressSearch = (event: any) => {
//     const query = event.target.value;
//     clearTimeout(debounceTimeout);
//     if (query.length < 3) {
//         addressSuggestions.value = [];
//         return;
//     }
//     debounceTimeout = setTimeout(async () => {
//         try {
//             const res = await fetch(
//                 `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&addressdetails=1&limit=5`,
//             );
//             addressSuggestions.value = await res.json();
//             showSuggestions.value = true;
//         } catch {}
//     }, 500);
// };

// const selectAddress = (item: any) => {
//     form.value.latitude = item.lat;
//     form.value.longitude = item.lon;
//     form.value.address_line_1 = item.display_name;
//     const addr = item.address;
//     form.value.suburb =
//         addr.suburb ||
//         addr.neighbourhood ||
//         addr.city_district ||
//         addr.town ||
//         '';
//     showSuggestions.value = false;
//     addressSuggestions.value = [];
// };

// ─── address search ───────────────────────────────────────────────────────────
let sessionToken: any = null;

const initPlacesSession = async () => {
    const { AutocompleteSessionToken } = await (
        window as any
    ).google.maps.importLibrary('places');
    sessionToken = new AutocompleteSessionToken();
};

const handleAddressSearch = async (event: any) => {
    const query = event.target.value;
    console.log('input:', query); // step 1 - are we even firing?
    clearTimeout(debounceTimeout);
    if (query.length < 3) {
        addressSuggestions.value = [];
        return;
    }
    debounceTimeout = setTimeout(async () => {
        console.log('debounce fired'); // step 2 - did debounce run?
        try {
            await new Promise<void>((resolve) => {
                const check = () => {
                    if ((window as any).google?.maps) resolve();
                    else setTimeout(check, 100);
                };
                check();
            });
            console.log('google maps ready'); // step 3 - is maps loaded?

            const { AutocompleteSuggestion, AutocompleteSessionToken } = await (
                window as any
            ).google.maps.importLibrary('places');
            console.log('places library loaded'); // step 4 - did library import?

            if (!sessionToken) sessionToken = new AutocompleteSessionToken();

            const { suggestions } =
                await AutocompleteSuggestion.fetchAutocompleteSuggestions({
                    input: query,
                    sessionToken,
                });

            addressSuggestions.value = suggestions.map((s: any) => ({
                place_id: s.placePrediction.placeId,
                display_name: s.placePrediction.text.toString(),
                _prediction: s.placePrediction,
            }));
            showSuggestions.value = true;
        } catch (e) {
            console.error('Places error:', e);
        }
    }, 400);
};

const selectAddress = async (item: any) => {
    showSuggestions.value = false;
    addressSuggestions.value = [];
    try {
        const { Place } = await (window as any).google.maps.importLibrary(
            'places',
        );
        const place = new Place({ id: item.place_id });
        await place.fetchFields({
            fields: ['addressComponents', 'formattedAddress', 'location'],
        });

        form.value.address_line_1 = place.formattedAddress || item.display_name;
        form.value.latitude = place.location?.lat() ?? null;
        form.value.longitude = place.location?.lng() ?? null;

        const get = (type: string) =>
            place.addressComponents?.find((c: any) => c.types.includes(type))
                ?.longText || '';

        form.value.suburb =
            get('sublocality_level_1') ||
            get('locality') ||
            get('sublocality') ||
            '';

        // Reset session token after a place is selected (billing best practice)
        sessionToken = null;
    } catch (e) {
        console.error(e);
    }
};

function showMessage(message: string) {
    flashMessage.value = message;
    setTimeout(() => (flashMessage.value = null), 3000);
}

// ─── role groups ──────────────────────────────────────────────────────────────
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
            { text: 'Operations Controller', value: 'ops_controller' },
        ],
    },
    {
        label: 'Security & Safety',
        options: [
            { text: 'Security Guard', value: 'security_guard' },
            { text: 'Patrol Officer', value: 'patrol_officer' },
            { text: 'Loss Prevention', value: 'loss_prevention' },
            { text: 'First Responder', value: 'first_responder' },
            { text: 'Safety Officer', value: 'safety_officer' },
            { text: 'Emergency Coordinator', value: 'emergency_coordinator' },
        ],
    },
    {
        label: 'Operations & Logistics',
        options: [
            { text: 'Maintenance Technician', value: 'maintenance' },
            { text: 'Warehouse Operative', value: 'warehouse' },
            { text: 'Forklift Operator', value: 'forklift' },
            { text: 'Fleet Driver', value: 'fleet_driver' },
            { text: 'Logistics Coordinator', value: 'logistics_coordinator' },
        ],
    },
    {
        label: 'Hospitality & Services',
        options: [
            { text: 'Housekeeping', value: 'housekeeping' },
            { text: 'Front Desk / Concierge', value: 'front_desk' },
            { text: 'Event Staff', value: 'event_staff' },
            { text: 'Janitorial', value: 'janitorial' },
            { text: 'Customer Service Liaison', value: 'customer_service' },
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

// ─── form ─────────────────────────────────────────────────────────────────────
const form = ref({
    id: null,
    name: '',
    email: '',
    phone: '+27',
    occupation: '',
    channel_ids: [] as any[],
    client_id: '',
    password: '',
    role: 'employee',
    address_line_1: '',
    complex_name: '',
    suburb: '',
    access_code: '',
    unit_number: '',
    latitude: null as any,
    longitude: null as any,
    safe_cancel_pin: '',
    duress_pin: '',
});

// ─── watchers ─────────────────────────────────────────────────────────────────
watch(
    () => form.value.occupation,
    (newVal) => {
        if (newVal === 'household' || newVal === 'resident') {
            form.value.role = newVal;
            // Auto-generate pins when first switching to a household role
            if (!form.value.safe_cancel_pin)
                form.value.safe_cancel_pin = generatePin();
            if (!form.value.duress_pin) form.value.duress_pin = generatePin();
            // Household can only have one channel — keep only the first if multiple were set
            if (form.value.channel_ids.length > 1)
                form.value.channel_ids = [form.value.channel_ids[0]];
        } else {
            form.value.role = 'employee';
            // Clear pins when switching away from household role
            if (!isEditing.value) {
                form.value.safe_cancel_pin = '';
                form.value.duress_pin = '';
            }
        }
    },
);

// ─── computed ─────────────────────────────────────────────────────────────────
const isHousehold = computed(() => isHouseholdRole(form.value.role));

const filteredChannels = computed(() => {
    if (!form.value.client_id) return [];
    return channels.value.filter((c) => c.client_id == form.value.client_id);
});

// ─── API calls ────────────────────────────────────────────────────────────────
const reloadEmployees = async (url?: string) => {
    try {
        const params = new URLSearchParams(window.location.search);
        const status = params.get('status');
        const endpoint = url || `${import.meta.env.VITE_APP_URL}/api/employees`;
        const { data } = await axios.get(endpoint, {
            params: { status },
            headers: {
                Authorization: `Bearer ${localStorage.getItem('token')}`,
            },
        });
        employees.value = data.employees;
        employeesList.value = data.employees.data;
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
    } catch {}
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
    } catch {}
};

onMounted(() => {
    reloadEmployees();
    handleClients();
    handleChannels();
});

// ─── modal ────────────────────────────────────────────────────────────────────
const openModal = () => {
    isEditing.value = false;
    selectedRole.value = '';
    inComplex.value = false;
    Object.assign(form.value, {
        id: null,
        name: '',
        email: '',
        phone: '+27',
        occupation: '',
        channel_ids: [],
        client_id: '',
        password: '',
        role: 'employee',
        address_line_1: '',
        complex_name: '',
        suburb: '',
        access_code: '',
        latitude: null,
        longitude: null,
        safe_cancel_pin: '',
        duress_pin: '',
    });
    showModal.value = true;
};

const editEmployee = (employee: any) => {
    isEditing.value = true;
    form.value.client_id = employee.client_id;
    form.value.channel_ids = employee.channels || [];
    form.value.id = employee.id;
    form.value.name = employee.user.name;
    form.value.email = employee.user.email;
    form.value.phone = employee.user.phone;
    form.value.occupation = employee.user.occupation;
    form.value.role = employee.user.role || 'employee';
    form.value.address_line_1 = employee.user.address_line_1 || '';
    form.value.complex_name = employee.user.complex_name || '';
    form.value.suburb = employee.user.suburb || '';
    form.value.access_code = employee.user.access_code || '';
    form.value.latitude = employee.user.latitude || null;
    form.value.longitude = employee.user.longitude || null;
    form.value.safe_cancel_pin = employee.user.safe_cancel_pin || '';
    form.value.duress_pin = employee.user.duress_pin || '';
    form.value.unit_number = employee.user.unit_number || '';
    inComplex.value = !!employee.user.complex_name;

    // Set the role dropdown
    const allOptions = roleGroups.flatMap((g) => g.options);
    selectedRole.value =
        allOptions.find((o) => o.value === form.value.occupation) || '';

    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
};

// ─── submit ───────────────────────────────────────────────────────────────────
const submitEmployee = async () => {
    try {
        loading.value = true;
        const payload = {
            ...form.value,
            channel_ids: form.value.channel_ids.map((c: any) => c.id ?? c),
        };

        if (isEditing.value) {
            const { data } = await axios.put(
                `${import.meta.env.VITE_APP_URL}/api/employees/${form.value.id}`,
                payload,
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
                payload,
                {
                    headers: {
                        Authorization: `Bearer ${localStorage.getItem('token')}`,
                    },
                },
            );
            showMessage(data.message);
            errors.value = {};
        }

        closeModal();
        await reloadEmployees();
    } catch (err: any) {
        errors.value = err.response?.data?.errors || {};
    } finally {
        loading.value = false;
    }
};

// ─── delete ───────────────────────────────────────────────────────────────────
const confirmDelete = (id: number) => {
    employeeToDelete.value = id;
    showDeleteModal.value = true;
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
    } catch {}
};

// const toggleStatus = async (employee: any) => {
//     try {
//         const { data } = await axios.patch(
//             `${import.meta.env.VITE_APP_URL}/api/users/${employee.user_id}/toggle-status`,
//             {},
//             {
//                 headers: {
//                     Authorization: `Bearer ${localStorage.getItem('token')}`,
//                 },
//             },
//         );
//         showMessage(data.message);
//         await reloadEmployees();
//     } catch {}
// };

function toggleStatus(employee: any) {
    confirmToggleEmployee.value = employee;
}

async function proceedToggle() {
    if (!confirmToggleEmployee.value) return;
    try {
        const { data } = await axios.patch(
            `${import.meta.env.VITE_APP_URL}/api/users/${confirmToggleEmployee.value.user_id}/toggle-status`,
            {},
            {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                },
            },
        );
        showMessage(data.message);
        await reloadEmployees();
    } catch {
    } finally {
        confirmToggleEmployee.value = null;
    }
}

const regeneratePins = () => {
    form.value.safe_cancel_pin = generatePin();
    form.value.duress_pin = generatePin();
};

const handlePhoneInput = (val: string) => {
    if (!val || !val.startsWith('+27')) {
        form.value.phone = '+27';
        return;
    }
    form.value.phone = val.replace(/\s+/g, '').replace(/[^0-9+]/g, '');
};

const hideSuggestions = () => {
    setTimeout(() => (showSuggestions.value = false), 200);
};
</script>

<template>
    <Head title="Personnels" />

    <AppLayout>
        <div
            class="relative flex h-full w-full flex-col rounded-xl bg-white bg-clip-border text-gray-700 shadow-md"
        >
            <!-- Header -->
            <div
                class="relative mx-4 mt-4 overflow-hidden rounded-none bg-white bg-clip-border text-gray-700"
            >
                <div class="mb-8 flex items-center justify-between gap-8">
                    <div>
                        <p
                            class="mt-1 block font-sans text-base leading-relaxed font-normal text-gray-700 antialiased"
                        >
                            Personnels
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col gap-2 sm:flex-row">
                        <button
                            class="rounded-lg border border-gray-900 px-4 py-2 text-center align-middle font-sans text-xs font-bold text-gray-900 uppercase transition-all select-none hover:opacity-75 focus:ring focus:ring-gray-300 active:opacity-[0.85] disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none"
                            type="button"
                            @click="openModal"
                        >
                            Add Personnel
                        </button>

                        <!-- ── Modal ── -->
                        <form @submit.prevent="submitEmployee()">
                            <div v-if="showModal">
                                <div
                                    class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 py-8 backdrop-blur-[2px]"
                                >
                                    <div
                                        class="w-full max-w-2xl rounded-lg bg-white p-6 shadow-lg"
                                    >
                                        <h2 class="text-heading">
                                            {{
                                                isEditing
                                                    ? 'Edit Personnel'
                                                    : 'Add Personnel'
                                            }}
                                        </h2>

                                        <!-- Role -->
                                        <div class="mb-4 grid gap-2">
                                            <div class="form-group">
                                                <label for="role-select"
                                                    >Assign Personnel
                                                    Role:</label
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
                                            <!-- Name + Email -->
                                            <div
                                                class="grid grid-cols-1 gap-4 md:grid-cols-2"
                                            >
                                                <div class="grid gap-2">
                                                    <Label for="name"
                                                        >Name</Label
                                                    >
                                                    <input
                                                        id="name"
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

                                            <!-- Phone + Client -->
                                            <div
                                                class="grid grid-cols-1 gap-4 md:grid-cols-2"
                                            >
                                                <div class="grid gap-2">
                                                    <Label for="contact"
                                                        >Phone</Label
                                                    >
                                                    <VueTelInput
                                                        v-model="form.phone"
                                                        mode="international"
                                                        :onlyCountries="['ZA']"
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
                                                            {{
                                                                client.user
                                                                    ?.name
                                                            }}
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

                                            <!-- ── Channels — single for household, multi for others ── -->
                                            <div class="grid gap-3">
                                                <div
                                                    class="flex items-center justify-between"
                                                >
                                                    <Label for="channels">
                                                        {{
                                                            isHousehold
                                                                ? 'Channel (one only)'
                                                                : 'Channels'
                                                        }}
                                                    </Label>
                                                    <span
                                                        v-if="isHousehold"
                                                        class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold tracking-wide text-amber-700 uppercase"
                                                    >
                                                        Household — 1 channel
                                                        max
                                                    </span>
                                                </div>

                                                <!-- Single-select for household -->
                                                <Multiselect
                                                    v-if="isHousehold"
                                                    v-model="
                                                        form.channel_ids[0]
                                                    "
                                                    :options="filteredChannels"
                                                    :multiple="false"
                                                    :close-on-select="true"
                                                    placeholder="Select one channel..."
                                                    label="name"
                                                    track-by="id"
                                                    @select="
                                                        (ch) => {
                                                            form.channel_ids = [
                                                                ch,
                                                            ];
                                                        }
                                                    "
                                                    @remove="
                                                        () => {
                                                            form.channel_ids =
                                                                [];
                                                        }
                                                    "
                                                />

                                                <!-- Multi-select for all other roles -->
                                                <Multiselect
                                                    v-else
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

                                            <!-- ── Household Details ── -->
                                            <div
                                                v-if="isHousehold"
                                                class="mt-2 rounded-xl border border-gray-200 bg-gray-50 p-4"
                                            >
                                                <h3
                                                    class="mb-3 text-sm font-semibold tracking-wider text-gray-900 uppercase"
                                                >
                                                    Household Details
                                                </h3>

                                                <!-- Address search -->
                                                <div
                                                    class="relative mb-4 grid gap-2"
                                                >
                                                    <Label for="address_search"
                                                        >Search Address</Label
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
                                                                hideSuggestions
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

                                                <!-- Address fields -->
                                                <!-- Complex toggle — household only -->
                                                <div
                                                    v-if="
                                                        form.role ===
                                                        'household'
                                                    "
                                                    class="mb-3 flex items-center gap-2"
                                                >
                                                    <input
                                                        id="in_complex"
                                                        type="checkbox"
                                                        v-model="inComplex"
                                                        class="h-4 w-4 w-auto rounded border-gray-300 text-indigo-600"
                                                        @change="
                                                            () => {
                                                                if (!inComplex)
                                                                    form.complex_name =
                                                                        '';
                                                            }
                                                        "
                                                        style="
                                                            width: auto !important;
                                                        "
                                                    />
                                                    <label
                                                        for="in_complex"
                                                        class="cursor-pointer text-sm text-gray-700 select-none"
                                                    >
                                                        This household is inside
                                                        an estate or complex
                                                    </label>
                                                </div>

                                                <div
                                                    class="grid grid-cols-1 gap-4 md:grid-cols-2"
                                                >
                                                    <div class="grid gap-2">
                                                        <Label
                                                            for="unit_number"
                                                        >
                                                            {{
                                                                inComplex ||
                                                                form.role ===
                                                                    'resident'
                                                                    ? 'Unit Number'
                                                                    : 'House Number'
                                                            }}
                                                        </Label>
                                                        <input
                                                            id="unit_number"
                                                            v-model="
                                                                form.unit_number
                                                            "
                                                            :placeholder="
                                                                inComplex ||
                                                                form.role ===
                                                                    'resident'
                                                                    ? 'e.g. Unit 4B'
                                                                    : 'e.g. 2354'
                                                            "
                                                        />
                                                    </div>
                                                    <div
                                                        v-if="
                                                            inComplex ||
                                                            form.role ===
                                                                'resident'
                                                        "
                                                        class="grid gap-2"
                                                    >
                                                        <Label for="complex"
                                                            >Complex / Estate
                                                            Name</Label
                                                        >
                                                        <input
                                                            id="complex"
                                                            v-model="
                                                                form.complex_name
                                                            "
                                                            placeholder="e.g. Green Valley Estate"
                                                            :required="
                                                                inComplex
                                                            "
                                                        />
                                                    </div>
                                                </div>

                                                <div
                                                    class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2"
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
                                                        <Label for="suburb"
                                                            >Suburb /
                                                            Area</Label
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

                                                <!-- ── Security PINs ── -->
                                                <div
                                                    class="mt-5 rounded-lg border border-red-100 bg-white p-4"
                                                >
                                                    <div
                                                        class="mb-3 flex items-center justify-between"
                                                    >
                                                        <div>
                                                            <h4
                                                                class="text-sm font-bold text-gray-900"
                                                            >
                                                                Security Codes
                                                            </h4>
                                                            <p
                                                                class="mt-0.5 text-[11px] text-gray-500"
                                                            >
                                                                Auto-generated.
                                                                Send to
                                                                household via
                                                                their first
                                                                login.
                                                            </p>
                                                        </div>
                                                        <button
                                                            type="button"
                                                            @click="
                                                                regeneratePins
                                                            "
                                                            class="rounded-md border border-gray-200 px-2.5 py-1.5 text-[11px] font-bold text-gray-600 uppercase hover:bg-gray-50 active:scale-95"
                                                            title="Generate new codes"
                                                        >
                                                            ↻ Regenerate
                                                        </button>
                                                    </div>

                                                    <div
                                                        class="grid grid-cols-2 gap-3"
                                                    >
                                                        <!-- Safe cancel PIN -->
                                                        <div
                                                            class="grid gap-1.5"
                                                        >
                                                            <Label
                                                                for="safe_cancel_pin"
                                                            >
                                                                <span
                                                                    class="flex items-center gap-1.5"
                                                                >
                                                                    <span
                                                                        class="inline-block h-2 w-2 rounded-full bg-green-500"
                                                                    ></span>
                                                                    Cancel Code
                                                                </span>
                                                            </Label>
                                                            <div
                                                                class="relative"
                                                            >
                                                                <input
                                                                    id="safe_cancel_pin"
                                                                    v-model="
                                                                        form.safe_cancel_pin
                                                                    "
                                                                    maxlength="6"
                                                                    class="w-full rounded-md border-gray-300 bg-gray-50 pr-8 font-mono text-lg font-bold tracking-widest shadow-sm"
                                                                    placeholder="——————"
                                                                    readonly
                                                                />
                                                            </div>
                                                            <p
                                                                class="text-[13px] text-gray-400"
                                                            >
                                                                Genuine false
                                                                alarm cancel
                                                            </p>
                                                        </div>

                                                        <!-- Duress PIN -->
                                                        <div
                                                            class="grid gap-1.5"
                                                        >
                                                            <Label
                                                                for="duress_pin"
                                                            >
                                                                <span
                                                                    class="flex items-center gap-1.5"
                                                                >
                                                                    <span
                                                                        class="inline-block h-2 w-2 rounded-full bg-red-500"
                                                                    ></span>
                                                                    Duress Code
                                                                </span>
                                                            </Label>
                                                            <div
                                                                class="relative"
                                                            >
                                                                <input
                                                                    id="duress_pin"
                                                                    v-model="
                                                                        form.duress_pin
                                                                    "
                                                                    maxlength="6"
                                                                    class="w-full rounded-md border-red-200 bg-red-50 pr-8 font-mono text-lg font-bold tracking-widest shadow-sm focus:border-red-400 focus:ring-red-200"
                                                                    placeholder="——————"
                                                                    readonly
                                                                />
                                                            </div>
                                                            <p
                                                                class="text-[13px] text-gray-400"
                                                            >
                                                                Covert — keeps
                                                                patrollers on
                                                                route
                                                            </p>
                                                        </div>
                                                    </div>

                                                    <div
                                                        class="mt-3 rounded-md border border-amber-100 bg-amber-50 px-3 py-2 text-[11px] text-amber-700"
                                                    >
                                                        Never share the duress
                                                        code label with the
                                                        household — they should
                                                        only know it as their
                                                        "emergency code". Both
                                                        codes look identical
                                                        when entered.
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Password -->
                                            <div class="grid gap-3">
                                                <Label for="password"
                                                    >Set New Password</Label
                                                >
                                                <input
                                                    id="password"
                                                    v-model="form.password"
                                                    type="password"
                                                />
                                                <p
                                                    v-if="errors.password"
                                                    class="text-sm text-red-600"
                                                >
                                                    {{ errors.password[0] }}
                                                </p>
                                            </div>

                                            <!-- Actions -->
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
                                                    <span
                                                        v-if="loading"
                                                        class="loader mr-2"
                                                    ></span>
                                                    <span>{{
                                                        loading
                                                            ? isEditing
                                                                ? 'Updating...'
                                                                : 'Adding...'
                                                            : isEditing
                                                              ? 'Update Personnel'
                                                              : 'Add Personnel'
                                                    }}</span>
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

            <!-- Flash -->
            <div class="pt-0 pr-4 pb-0 pl-4">
                <div
                    v-if="flashMessage"
                    class="mb-4 rounded bg-green-100 p-2 text-green-700"
                >
                    {{ flashMessage }}
                </div>
            </div>

            <!-- Table -->
            <div class="overflow-scroll p-0 px-0">
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
                                <p class="text-blue-gray-900 text-sm">
                                    {{ employee.user.phone }}
                                </p>
                            </td>
                            <td
                                class="border-blue-gray-50 border-b p-4 text-sm"
                            >
                                {{
                                    employee.client
                                        ? employee.client.user.name
                                        : 'No Client Assigned'
                                }}
                            </td>
                            <td
                                class="border-blue-gray-50 border-b p-4 text-sm"
                            >
                                <span class="flex items-center gap-1.5">
                                    <span
                                        v-if="
                                            isHouseholdRole(
                                                employee.user.occupation,
                                            )
                                        "
                                        class="inline-block h-2 w-2 rounded-full bg-amber-400"
                                    ></span>
                                    {{ employee.user.occupation }}
                                </span>
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
                                                : 'Deactivated'
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

            <!-- Pagination -->
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

    <!-- Delete confirm modal -->
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

    <!-- Add this modal at the bottom of your template, before closing tag -->
    <div
        v-if="confirmToggleEmployee"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
    >
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
            <!-- Header -->
            <div class="mb-4 flex items-center gap-3">
                <div
                    :class="[
                        'flex h-10 w-10 items-center justify-center rounded-full',
                        confirmToggleEmployee.user.is_active
                            ? 'bg-red-100'
                            : 'bg-green-100',
                    ]"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5"
                        :class="
                            confirmToggleEmployee.user.is_active
                                ? 'text-red-600'
                                : 'text-green-600'
                        "
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M12 9v2m0 4h.01M12 3a9 9 0 100 18A9 9 0 0012 3z"
                        />
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-semibold text-gray-900">
                        {{
                            confirmToggleEmployee.user.is_active
                                ? 'Deactivate Personnel'
                                : 'Activate Personnel'
                        }}
                    </h3>
                    <p class="text-sm text-gray-500">
                        {{ confirmToggleEmployee.user.name }}
                    </p>
                </div>
            </div>

            <!-- Body -->
            <div
                v-if="confirmToggleEmployee.user.is_active"
                class="mb-5 rounded-lg border border-red-100 bg-red-50 p-4 text-sm text-red-800"
            >
                <p class="font-semibold">Before you deactivate:</p>
                <ul class="mt-2 list-inside list-disc space-y-1">
                    <li>They'll be logged out of Echo Link immediately</li>
                    <li>They won't be able to log back in until reactivated</li>
                    <li>All active channel sessions will be terminated</li>
                </ul>
            </div>
            <div
                v-else
                class="mb-5 rounded-lg border border-green-100 bg-green-50 p-4 text-sm text-green-800"
            >
                <p>
                    Personnel will regain access to the Echo Link app and can
                    log in with their existing credentials.
                </p>
            </div>

            <!-- Actions -->
            <div class="flex justify-end gap-3">
                <button
                    @click="confirmToggleEmployee = null"
                    class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50"
                >
                    Cancel
                </button>
                <button
                    @click="proceedToggle"
                    :class="[
                        'rounded-lg px-4 py-2 text-sm font-medium text-white transition-colors',
                        confirmToggleEmployee.user.is_active
                            ? 'bg-red-600 hover:bg-red-700'
                            : 'bg-green-600 hover:bg-green-700',
                    ]"
                >
                    {{
                        confirmToggleEmployee.user.is_active
                            ? 'Yes, Deactivate'
                            : 'Yes, Activate'
                    }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.loader {
    border: 2px solid #f3f3f3;
    border-top: 2px solid #3498db;
    border-radius: 50%;
    width: 16px;
    height: 16px;
    animation: spin 1s linear infinite;
}
@keyframes spin {
    0% {
        transform: rotate(0deg);
    }
    100% {
        transform: rotate(360deg);
    }
}
.vue-tel-input {
    display: flex !important;
    background-color: white;
    min-height: 40px;
    border: 1px solid #d1d5db !important;
}
.vti__input {
    background: transparent !important;
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
}
:deep(.custom-tel-input) {
    display: flex !important;
    height: 40px !important;
    border-radius: 6px;
    border: 1px solid #d1d5db !important;
    background-color: white;
}
:deep(.vti__input) {
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
    font-size: 0.875rem;
}
:deep(.vti__dropdown) {
    border-radius: 6px 0 0 6px;
}
</style>
<style src="vue-multiselect/dist/vue-multiselect.min.css"></style>

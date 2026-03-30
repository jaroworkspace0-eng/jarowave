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

// ─── tab state ────────────────────────────────────────────────────────────────
const activeTab = ref<'personnel' | 'households'>('personnel');

// ─── invite links state ───────────────────────────────────────────────────────
const invites = ref<any[]>([]);
const inviteLoading = ref(true);
const isGenerating = ref(false);
const selectedChannelId = ref('');
const inviteFlash = ref<{ msg: string; type: 'success' | 'error' } | null>(
    null,
);
const copiedId = ref<number | null>(null);
const confirmRegenerateInvite = ref<any>(null);
const isRegenerating = ref(false);

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
const clientChannels = ref<any[]>([]);

const employees = ref<any>({ data: [], from: 0, to: 0, total: 0, links: [] });

// ─── computed lists ───────────────────────────────────────────────────────────
const personnelList = computed(() =>
    employeesList.value.filter((e) => !isHouseholdRole(e.user.occupation)),
);
const householdList = computed(() =>
    employeesList.value.filter((e) => isHouseholdRole(e.user.occupation)),
);

// Channels that don't yet have an invite link
const channelsWithoutInvite = computed(() => {
    const usedChannelIds = new Set(invites.value.map((i) => i.channel_id));
    return clientChannels.value.filter((ch) => !usedChannelIds.has(ch.id));
});

// ─── address search ───────────────────────────────────────────────────────────
let sessionToken: any = null;

const handleAddressSearch = async (event: any) => {
    const query = event.target.value;
    clearTimeout(debounceTimeout);
    if (query.length < 3) {
        addressSuggestions.value = [];
        return;
    }
    debounceTimeout = setTimeout(async () => {
        try {
            await new Promise<void>((resolve) => {
                const check = () => {
                    if ((window as any).google?.maps) resolve();
                    else setTimeout(check, 100);
                };
                check();
            });
            const { AutocompleteSuggestion, AutocompleteSessionToken } = await (
                window as any
            ).google.maps.importLibrary('places');
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
            if (!form.value.safe_cancel_pin)
                form.value.safe_cancel_pin = generatePin();
            if (!form.value.duress_pin) form.value.duress_pin = generatePin();
            if (form.value.channel_ids.length > 1)
                form.value.channel_ids = [form.value.channel_ids[0]];
        } else {
            form.value.role = 'employee';
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

// ─── API ──────────────────────────────────────────────────────────────────────
const getHeaders = () => ({
    headers: { Authorization: `Bearer ${localStorage.getItem('token')}` },
});

const reloadEmployees = async (url?: string) => {
    try {
        const params = new URLSearchParams(window.location.search);
        const status = params.get('status');
        const endpoint = url || `${import.meta.env.VITE_APP_URL}/api/employees`;
        const { data } = await axios.get(endpoint, {
            params: { status },
            ...getHeaders(),
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
            getHeaders(),
        );
        channels.value = data;
    } catch {}
};

const handleClients = async () => {
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/clients/show`,
            getHeaders(),
        );
        clients.value = data;
    } catch {}
};

const loadClientChannels = async () => {
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/channels/mine`,
            getHeaders(),
        );
        clientChannels.value = data;
    } catch (err) {
        console.error('loadClientChannels failed:', err);
    }
};

// ─── invite links ─────────────────────────────────────────────────────────────
const loadInvites = async () => {
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/invite`,
            getHeaders(),
        );
        invites.value = data.invites ?? [];
    } catch {
        invites.value = [];
    } finally {
        inviteLoading.value = false;
    }
};

const generateInviteLink = async () => {
    if (!selectedChannelId.value) {
        showInviteFlash('Please select a channel first.', 'error');
        return;
    }
    try {
        isGenerating.value = true;
        const { data } = await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/invite/generate`,
            { channel_id: selectedChannelId.value },
            getHeaders(),
        );
        invites.value.push(data);
        selectedChannelId.value = '';
        showInviteFlash(`Invite link generated for ${data.channel_name}.`);
    } catch (err: any) {
        const msg =
            err.response?.data?.message ?? 'Failed to generate invite link.';
        showInviteFlash(msg, 'error');
    } finally {
        isGenerating.value = false;
    }
};

const copyInviteLink = async (invite: any) => {
    try {
        await navigator.clipboard.writeText(invite.invite_url);
        copiedId.value = invite.id;
        setTimeout(() => (copiedId.value = null), 2500);
    } catch {
        showInviteFlash('Could not copy — please copy manually.', 'error');
    }
};

const confirmRegenerate = (invite: any) => {
    confirmRegenerateInvite.value = invite;
};

const proceedRegenerate = async () => {
    if (!confirmRegenerateInvite.value) return;
    try {
        isRegenerating.value = true;
        const { data } = await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/invite/${confirmRegenerateInvite.value.id}/regenerate`,
            {},
            getHeaders(),
        );
        const idx = invites.value.findIndex((i) => i.id === data.id);
        if (idx !== -1) invites.value[idx] = data;
        showInviteFlash(
            `New link generated for ${data.channel_name}. Old link is now invalid.`,
        );
    } catch {
        showInviteFlash('Failed to regenerate link.', 'error');
    } finally {
        isRegenerating.value = false;
        confirmRegenerateInvite.value = null;
    }
};

const deleteInvite = async (invite: any) => {
    try {
        await axios.delete(
            `${import.meta.env.VITE_APP_URL}/api/invite/${invite.id}`,
            getHeaders(),
        );
        invites.value = invites.value.filter((i) => i.id !== invite.id);
        showInviteFlash(`Invite link for ${invite.channel_name} deleted.`);
    } catch {
        showInviteFlash('Failed to delete invite link.', 'error');
    }
};

const showInviteFlash = (
    msg: string,
    type: 'success' | 'error' = 'success',
) => {
    inviteFlash.value = { msg, type };
    setTimeout(() => (inviteFlash.value = null), 4000);
};

onMounted(() => {
    reloadEmployees();
    handleClients();
    handleChannels();
    loadInvites();
    loadClientChannels();
});

// ─── modal ────────────────────────────────────────────────────────────────────
const openModal = (forceHousehold = false) => {
    isEditing.value = false;
    selectedRole.value = '';
    inComplex.value = false;
    Object.assign(form.value, {
        id: null,
        name: '',
        email: '',
        phone: '+27',
        occupation: forceHousehold ? 'household' : '',
        channel_ids: [],
        client_id: '',
        password: '',
        role: forceHousehold ? 'household' : 'employee',
        address_line_1: '',
        complex_name: '',
        suburb: '',
        access_code: '',
        latitude: null,
        longitude: null,
        unit_number: '',
        safe_cancel_pin: forceHousehold ? generatePin() : '',
        duress_pin: forceHousehold ? generatePin() : '',
    });
    if (forceHousehold)
        selectedRole.value = { text: 'Household', value: 'household' } as any;
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
    const allOptions = roleGroups.flatMap((g) => g.options);
    selectedRole.value =
        (allOptions.find((o) => o.value === form.value.occupation) as any) ||
        '';
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
                getHeaders(),
            );
            showMessage(data.message);
        } else {
            const { data } = await axios.post(
                `${import.meta.env.VITE_APP_URL}/api/employees`,
                payload,
                getHeaders(),
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
            getHeaders(),
        );
        showMessage(data.message);
        showDeleteModal.value = false;
        employeeToDelete.value = null;
        await reloadEmployees();
    } catch {}
};

function toggleStatus(employee: any) {
    confirmToggleEmployee.value = employee;
}

async function proceedToggle() {
    if (!confirmToggleEmployee.value) return;
    try {
        const { data } = await axios.patch(
            `${import.meta.env.VITE_APP_URL}/api/users/${confirmToggleEmployee.value.user_id}/toggle-status`,
            {},
            getHeaders(),
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
            <!-- ── HEADER ── -->
            <div
                class="relative mx-4 mt-4 overflow-hidden rounded-none bg-white bg-clip-border text-gray-700"
            >
                <div class="mb-4 flex items-center justify-between gap-8">
                    <div>
                        <div
                            class="flex w-fit gap-1 rounded-xl bg-gray-100 p-1"
                        >
                            <button
                                @click="activeTab = 'personnel'"
                                :class="[
                                    'font-600 rounded-lg px-4 py-2 text-sm transition-all',
                                    activeTab === 'personnel'
                                        ? 'bg-white font-semibold text-gray-900 shadow-sm'
                                        : 'text-gray-500 hover:text-gray-700',
                                ]"
                            >
                                Personnel
                                <span
                                    class="ml-1.5 rounded-full bg-gray-200 px-1.5 py-0.5 text-xs font-bold text-gray-600"
                                    >{{ personnelList.length }}</span
                                >
                            </button>
                            <button
                                @click="activeTab = 'households'"
                                :class="[
                                    'rounded-lg px-4 py-2 text-sm transition-all',
                                    activeTab === 'households'
                                        ? 'bg-white font-semibold text-gray-900 shadow-sm'
                                        : 'text-gray-500 hover:text-gray-700',
                                ]"
                            >
                                Households
                                <span
                                    class="ml-1.5 rounded-full bg-gray-200 px-1.5 py-0.5 text-xs font-bold text-gray-600"
                                    >{{ householdList.length }}</span
                                >
                            </button>
                        </div>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <button
                            v-if="activeTab === 'personnel'"
                            class="rounded-lg border border-gray-900 px-4 py-2 text-center align-middle font-sans text-xs font-bold text-gray-900 uppercase transition-all select-none hover:opacity-75 focus:ring focus:ring-gray-300"
                            type="button"
                            @click="openModal(false)"
                        >
                            Add Personnel
                        </button>
                        <button
                            v-if="activeTab === 'households'"
                            class="rounded-lg border border-orange-500 bg-orange-500 px-4 py-2 text-center align-middle font-sans text-xs font-bold text-white uppercase transition-all select-none hover:bg-orange-600"
                            type="button"
                            @click="openModal(true)"
                        >
                            Add Household
                        </button>
                    </div>
                </div>
            </div>

            <!-- ── FLASH ── -->
            <div class="px-4">
                <div
                    v-if="flashMessage"
                    class="mb-4 rounded bg-green-100 p-2 text-green-700"
                >
                    {{ flashMessage }}
                </div>
            </div>

            <!-- ══════════════════════════════════════════ -->
            <!-- PERSONNEL TAB                              -->
            <!-- ══════════════════════════════════════════ -->
            <div v-if="activeTab === 'personnel'">
                <div class="overflow-x-auto">
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
                            <tr v-if="personnelList.length === 0">
                                <td
                                    colspan="8"
                                    class="p-8 text-center text-sm text-gray-400"
                                >
                                    No personnel found.
                                </td>
                            </tr>
                            <tr
                                v-for="employee in personnelList"
                                :key="employee.id"
                                class="hover:bg-gray-50/50"
                            >
                                <td class="border-blue-gray-50 border-b p-4">
                                    <p class="text-sm font-bold text-gray-900">
                                        {{ employee.user.name }}
                                    </p>
                                    <p class="text-sm text-gray-500">
                                        {{ employee.user.email }}
                                    </p>
                                </td>
                                <td
                                    class="border-blue-gray-50 border-b p-4 text-sm"
                                >
                                    {{ employee.user.phone }}
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
                                    {{ employee.user.occupation }}
                                </td>
                                <td class="border-blue-gray-50 border-b p-4">
                                    <div
                                        class="flex max-w-[200px] flex-wrap gap-1"
                                    >
                                        <span
                                            v-for="c in employee.channels"
                                            :key="c.id"
                                            :class="[
                                                'flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-semibold shadow-sm',
                                                c.pivot.is_online
                                                    ? 'border-green-200 bg-green-50 text-green-700'
                                                    : 'border-gray-200 bg-gray-50 text-gray-500',
                                            ]"
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
                                                ? 'Deactivate'
                                                : 'Activate'
                                        "
                                        class="transition-transform active:scale-95"
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
                                    <div class="flex items-center gap-2 p-2">
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
            </div>

            <!-- ══════════════════════════════════════════ -->
            <!-- HOUSEHOLDS TAB                             -->
            <!-- ══════════════════════════════════════════ -->
            <div v-if="activeTab === 'households'" class="p-4">
                <!-- INVITE LINKS CARD -->
                <div
                    class="mb-6 rounded-xl border border-orange-200 bg-orange-50 p-5"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <div class="text-sm font-bold text-gray-900">
                                🔗 Household Invite Links
                            </div>
                            <div class="mt-0.5 text-xs text-gray-500">
                                One permanent link per channel — share with
                                households to join for R80/month
                            </div>
                        </div>
                    </div>

                    <!-- FLASH -->
                    <div
                        v-if="inviteFlash"
                        :class="[
                            'mb-4 rounded-lg px-3 py-2 text-xs font-semibold',
                            inviteFlash.type === 'success'
                                ? 'bg-green-100 text-green-700'
                                : 'bg-red-100 text-red-700',
                        ]"
                    >
                        {{ inviteFlash.type === 'success' ? '✓' : '⚠' }}
                        {{ inviteFlash.msg }}
                    </div>

                    <!-- LOADING -->
                    <div
                        v-if="inviteLoading"
                        class="flex items-center gap-2 py-4 text-sm text-gray-400"
                    >
                        <div
                            class="h-4 w-4 animate-spin rounded-full border-2 border-gray-300 border-t-orange-500"
                        ></div>
                        Loading...
                    </div>

                    <template v-else>
                        <!-- EXISTING INVITE LINKS TABLE -->
                        <div
                            v-if="invites.length > 0"
                            class="mb-4 overflow-hidden rounded-xl border border-orange-200 bg-white"
                        >
                            <table class="w-full text-sm">
                                <thead>
                                    <tr
                                        class="border-b border-gray-100 bg-gray-50"
                                    >
                                        <th
                                            class="p-3 text-left text-xs font-bold tracking-wide text-gray-500 uppercase"
                                        >
                                            Channel
                                        </th>
                                        <th
                                            class="p-3 text-left text-xs font-bold tracking-wide text-gray-500 uppercase"
                                        >
                                            Invite Link
                                        </th>
                                        <th
                                            class="p-3 text-center text-xs font-bold tracking-wide text-gray-500 uppercase"
                                        >
                                            Uses
                                        </th>
                                        <th
                                            class="p-3 text-right text-xs font-bold tracking-wide text-gray-500 uppercase"
                                        >
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="invite in invites"
                                        :key="invite.id"
                                        class="border-b border-gray-50 last:border-0 hover:bg-gray-50/50"
                                    >
                                        <td class="p-3">
                                            <span
                                                class="rounded-full border border-orange-200 bg-orange-100 px-2 py-0.5 text-xs font-bold text-orange-700"
                                            >
                                                {{ invite.channel_name }}
                                            </span>
                                        </td>
                                        <td class="max-w-[200px] p-3">
                                            <span
                                                class="block truncate font-mono text-xs text-gray-500"
                                                >{{ invite.invite_url }}</span
                                            >
                                        </td>
                                        <td class="p-3 text-center">
                                            <span
                                                class="text-xs font-semibold text-gray-600"
                                                >{{ invite.uses }}</span
                                            >
                                        </td>
                                        <td class="p-3">
                                            <div
                                                class="flex items-center justify-end gap-1.5"
                                            >
                                                <!-- Copy -->
                                                <button
                                                    @click="
                                                        copyInviteLink(invite)
                                                    "
                                                    :class="[
                                                        'rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-bold text-gray-500 transition-all hover:border-amber-400 hover:text-amber-600',
                                                        copiedId === invite.id
                                                            ? 'bg-green-500 text-white'
                                                            : 'bg-orange-500 text-white hover:bg-orange-600',
                                                    ]"
                                                    title="Copy Invite Link"
                                                >
                                                    {{
                                                        copiedId === invite.id
                                                            ? '✓'
                                                            : '📋'
                                                    }}
                                                </button>
                                                <!-- WhatsApp -->
                                                <a
                                                    :href="`https://wa.me/?text=${encodeURIComponent('Join our ' + invite.channel_name + ' neighbourhood watch on Echo Link! Register for R80/month: ' + invite.invite_url)}`"
                                                    target="_blank"
                                                    class="rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-bold text-gray-500 transition-all hover:border-amber-400 hover:text-amber-600"
                                                    title="Share on WhatsApp"
                                                    >💬</a
                                                >
                                                <!-- Regenerate -->
                                                <button
                                                    @click="
                                                        confirmRegenerate(
                                                            invite,
                                                        )
                                                    "
                                                    class="rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-bold text-gray-500 transition-all hover:border-amber-400 hover:text-amber-600"
                                                    title="Regenerate link (invalidates old link)"
                                                >
                                                    ↻
                                                </button>
                                                <!-- Delete -->
                                                <button
                                                    @click="
                                                        deleteInvite(invite)
                                                    "
                                                    class="rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-bold text-gray-500 transition-all hover:border-red-400 hover:text-red-600"
                                                    title="Delete invite link"
                                                >
                                                    ✕
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- EMPTY STATE -->
                        <div
                            v-else
                            class="mb-4 rounded-xl border border-dashed border-orange-300 bg-white p-6 text-center"
                        >
                            <div class="mb-2 text-2xl">🔗</div>
                            <div
                                class="mb-1 text-sm font-semibold text-gray-600"
                            >
                                No invite links yet
                            </div>
                            <div class="text-xs text-gray-400">
                                Generate a link below to start onboarding
                                households per channel
                            </div>
                        </div>

                        <!-- GENERATE NEW LINK -->
                        <div v-if="channelsWithoutInvite.length > 0">
                            <div
                                class="mb-2 text-xs font-bold tracking-wide text-gray-500 uppercase"
                            >
                                Generate link for a channel
                            </div>
                            <div class="flex items-center gap-2">
                                <select
                                    v-model="selectedChannelId"
                                    class="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm"
                                >
                                    <option value="">
                                        Select a channel...
                                    </option>
                                    <option
                                        v-for="ch in channelsWithoutInvite"
                                        :key="ch.id"
                                        :value="ch.id"
                                    >
                                        {{ ch.name }}
                                    </option>
                                </select>
                                <button
                                    @click="generateInviteLink"
                                    :disabled="
                                        isGenerating || !selectedChannelId
                                    "
                                    class="inline-flex items-center gap-2 rounded-lg bg-orange-500 px-4 py-2 text-sm font-bold whitespace-nowrap text-white shadow-sm transition-all hover:bg-orange-600 disabled:opacity-60"
                                >
                                    <div
                                        v-if="isGenerating"
                                        class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"
                                    ></div>
                                    <span>{{
                                        isGenerating
                                            ? 'Generating...'
                                            : 'Generate →'
                                    }}</span>
                                </button>
                            </div>
                        </div>
                        <div
                            v-else-if="
                                invites.length > 0 && clientChannels.length > 0
                            "
                            class="mt-2 text-xs text-gray-400"
                        >
                            ✓ All your channels have invite links.
                        </div>
                    </template>
                </div>

                <!-- EARNINGS STRIP -->
                <div class="mb-6 grid grid-cols-4 gap-3">
                    <div class="rounded-xl bg-gray-900 p-4 text-center">
                        <div class="text-xl font-bold text-white">
                            {{ householdList.length }}
                        </div>
                        <div class="mt-1 text-xs text-gray-400">
                            Total Households
                        </div>
                    </div>
                    <div class="rounded-xl bg-gray-900 p-4 text-center">
                        <div class="text-xl font-bold text-orange-400">
                            R{{ (householdList.length * 52).toLocaleString() }}
                        </div>
                        <div class="mt-1 text-xs text-gray-400">
                            Your Monthly Earnings
                        </div>
                    </div>
                    <div class="rounded-xl bg-gray-900 p-4 text-center">
                        <div class="text-xl font-bold text-white">
                            R{{ (householdList.length * 80).toLocaleString() }}
                        </div>
                        <div class="mt-1 text-xs text-gray-400">
                            Total Collected
                        </div>
                    </div>
                    <div class="rounded-xl bg-gray-900 p-4 text-center">
                        <div class="text-xl font-bold text-green-400">
                            R{{
                                (
                                    householdList.length *
                                    52 *
                                    12
                                ).toLocaleString()
                            }}
                        </div>
                        <div class="mt-1 text-xs text-gray-400">
                            Annual Earnings
                        </div>
                    </div>
                </div>

                <!-- HOUSEHOLDS TABLE -->
                <div class="overflow-x-auto rounded-xl border border-gray-200">
                    <table
                        class="w-full min-w-max table-auto text-left text-sm"
                    >
                        <thead>
                            <tr class="bg-gray-50">
                                <th
                                    class="border-b border-gray-200 p-4 font-sans text-xs font-bold tracking-wide text-gray-500 uppercase"
                                >
                                    Household
                                </th>
                                <th
                                    class="border-b border-gray-200 p-4 font-sans text-xs font-bold tracking-wide text-gray-500 uppercase"
                                >
                                    Contact
                                </th>
                                <th
                                    class="border-b border-gray-200 p-4 font-sans text-xs font-bold tracking-wide text-gray-500 uppercase"
                                >
                                    Address
                                </th>
                                <th
                                    class="border-b border-gray-200 p-4 font-sans text-xs font-bold tracking-wide text-gray-500 uppercase"
                                >
                                    Unit
                                </th>
                                <th
                                    class="border-b border-gray-200 p-4 font-sans text-xs font-bold tracking-wide text-gray-500 uppercase"
                                >
                                    Role
                                </th>
                                <th
                                    class="border-b border-gray-200 p-4 font-sans text-xs font-bold tracking-wide text-gray-500 uppercase"
                                >
                                    Monthly Fee
                                </th>
                                <th
                                    class="border-b border-gray-200 p-4 font-sans text-xs font-bold tracking-wide text-gray-500 uppercase"
                                >
                                    Your Share
                                </th>
                                <th
                                    class="border-b border-gray-200 p-4 font-sans text-xs font-bold tracking-wide text-gray-500 uppercase"
                                >
                                    Status
                                </th>
                                <th
                                    class="border-b border-gray-200 p-2 font-sans text-xs font-bold tracking-wide text-gray-500 uppercase"
                                >
                                    Action
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="householdList.length === 0">
                                <td
                                    colspan="9"
                                    class="p-12 text-center text-sm text-gray-400"
                                >
                                    <div class="mb-3 text-3xl">🏠</div>
                                    <div
                                        class="mb-1 font-semibold text-gray-600"
                                    >
                                        No households yet
                                    </div>
                                    <div class="text-xs text-gray-400">
                                        Share your invite links above to start
                                        onboarding households
                                    </div>
                                </td>
                            </tr>
                            <tr
                                v-for="employee in householdList"
                                :key="employee.id"
                                class="border-b border-gray-100 last:border-0 hover:bg-gray-50/50"
                            >
                                <td class="p-4">
                                    <p class="font-semibold text-gray-900">
                                        {{ employee.user.name }}
                                    </p>
                                    <p class="text-xs text-gray-400">
                                        {{ employee.user.email }}
                                    </p>
                                </td>
                                <td class="p-4 text-gray-600">
                                    {{ employee.user.phone }}
                                </td>
                                <td class="max-w-[180px] p-4 text-gray-600">
                                    <span class="block truncate text-xs">{{
                                        employee.user.address_line_1 || '—'
                                    }}</span>
                                    <span
                                        v-if="employee.user.suburb"
                                        class="text-xs text-gray-400"
                                        >{{ employee.user.suburb }}</span
                                    >
                                </td>
                                <td class="p-4 text-xs text-gray-600">
                                    <div v-if="employee.user.unit_number">
                                        {{ employee.user.unit_number }}
                                    </div>
                                    <div
                                        v-if="employee.user.complex_name"
                                        class="text-gray-400"
                                    >
                                        {{ employee.user.complex_name }}
                                    </div>
                                    <span v-if="!employee.user.unit_number"
                                        >—</span
                                    >
                                </td>
                                <td class="p-4">
                                    <span
                                        class="rounded-full border border-amber-200 bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-700 capitalize"
                                        >{{ employee.user.occupation }}</span
                                    >
                                </td>
                                <td class="p-4 font-semibold text-gray-900">
                                    R80
                                </td>
                                <td class="p-4 font-bold text-green-600">
                                    R52
                                </td>
                                <td class="p-4">
                                    <button
                                        @click="toggleStatus(employee)"
                                        class="transition-transform active:scale-95"
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
                                <td class="p-2">
                                    <div class="flex items-center gap-1">
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
            </div>

            <!-- ── PAGINATION (personnel tab only) ── -->
            <div
                v-if="activeTab === 'personnel'"
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

    <!-- ══════════════════════════════════════════ -->
    <!-- ADD / EDIT MODAL                           -->
    <!-- ══════════════════════════════════════════ -->
    <form @submit.prevent="submitEmployee()">
        <div v-if="showModal">
            <div
                class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 py-8 backdrop-blur-[2px]"
            >
                <div class="w-full max-w-2xl rounded-lg bg-white p-6 shadow-lg">
                    <h2 class="text-heading">
                        {{ isEditing ? 'Edit' : 'Add' }}
                        {{ isHousehold ? 'Household' : 'Personnel' }}
                    </h2>

                    <div v-if="!isHousehold" class="mb-4 grid gap-2">
                        <div class="form-group">
                            <label for="role-select"
                                >Assign Personnel Role:</label
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
                                        form.occupation = option.value;
                                    }
                                "
                                @remove="
                                    () => {
                                        form.occupation = '';
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

                    <div
                        v-else
                        class="mb-4 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2"
                    >
                        <span
                            class="inline-block h-2 w-2 rounded-full bg-amber-400"
                        ></span>
                        <span class="text-sm font-semibold text-amber-800"
                            >Household / Resident</span
                        >
                        <span class="ml-auto text-xs text-amber-600"
                            >Invite link recommended for self-registration</span
                        >
                    </div>

                    <div class="grid gap-4">
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="name">Name</Label>
                                <input id="name" v-model="form.name" />
                                <p
                                    v-if="errors.name"
                                    class="text-sm text-red-600"
                                >
                                    {{ errors.name[0] }}
                                </p>
                            </div>
                            <div class="grid gap-2">
                                <Label for="email">Email</Label>
                                <input id="email" v-model="form.email" />
                                <p
                                    v-if="errors.email"
                                    class="text-sm text-red-600"
                                >
                                    {{ errors.email[0] }}
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="contact">Phone</Label>
                                <VueTelInput
                                    v-model="form.phone"
                                    mode="international"
                                    :onlyCountries="['ZA']"
                                    defaultCountry="ZA"
                                    :autoFormat="true"
                                    :inputOptions="{
                                        showDialCode: true,
                                        placeholder: '+27821234567',
                                    }"
                                    @input="handlePhoneInput"
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
                                <Label for="clients">Client</Label>
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
                                        {{ client.user?.name }}
                                    </option>
                                </select>
                                <p
                                    v-if="errors.client_id"
                                    class="text-sm text-red-600"
                                >
                                    {{ errors.client_id[0] }}
                                </p>
                            </div>
                        </div>

                        <div class="grid gap-3">
                            <div class="flex items-center justify-between">
                                <Label for="channels">{{
                                    isHousehold
                                        ? 'Channel (one only)'
                                        : 'Channels'
                                }}</Label>
                                <span
                                    v-if="isHousehold"
                                    class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold tracking-wide text-amber-700 uppercase"
                                    >Household — 1 channel max</span
                                >
                            </div>
                            <Multiselect
                                v-if="isHousehold"
                                v-model="form.channel_ids[0]"
                                :options="filteredChannels"
                                :multiple="false"
                                :close-on-select="true"
                                placeholder="Select one channel..."
                                label="name"
                                track-by="id"
                                @select="
                                    (ch) => {
                                        form.channel_ids = [ch];
                                    }
                                "
                                @remove="
                                    () => {
                                        form.channel_ids = [];
                                    }
                                "
                            />
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

                        <div
                            v-if="isHousehold"
                            class="mt-2 rounded-xl border border-gray-200 bg-gray-50 p-4"
                        >
                            <h3
                                class="mb-3 text-sm font-semibold tracking-wider text-gray-900 uppercase"
                            >
                                Household Details
                            </h3>

                            <div class="relative mb-4 grid gap-2">
                                <Label for="address_search"
                                    >Search Address</Label
                                >
                                <div class="relative">
                                    <input
                                        id="address_search"
                                        type="text"
                                        placeholder="Type your street address..."
                                        class="w-full rounded-md border-gray-300 pl-10 shadow-sm"
                                        @input="handleAddressSearch"
                                        @blur="hideSuggestions"
                                    />
                                    <span
                                        class="absolute top-2.5 left-3 text-gray-400"
                                        ><i class="fas fa-search-location"></i
                                    ></span>
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
                                        @click="selectAddress(item)"
                                        class="cursor-pointer border-b px-4 py-3 text-sm last:border-0 hover:bg-gray-50"
                                    >
                                        <div class="font-medium text-gray-800">
                                            {{ item.display_name }}
                                        </div>
                                    </li>
                                </ul>
                            </div>

                            <div
                                v-if="form.role === 'household'"
                                class="mb-3 flex items-center gap-2"
                            >
                                <input
                                    id="in_complex"
                                    type="checkbox"
                                    v-model="inComplex"
                                    class="h-4 w-4 rounded border-gray-300 text-indigo-600"
                                    @change="
                                        () => {
                                            if (!inComplex)
                                                form.complex_name = '';
                                        }
                                    "
                                    style="width: auto !important"
                                />
                                <label
                                    for="in_complex"
                                    class="cursor-pointer text-sm text-gray-700 select-none"
                                    >This household is inside an estate or
                                    complex</label
                                >
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="unit_number">{{
                                        inComplex || form.role === 'resident'
                                            ? 'Unit Number'
                                            : 'House Number'
                                    }}</Label>
                                    <input
                                        id="unit_number"
                                        v-model="form.unit_number"
                                        :placeholder="
                                            inComplex ||
                                            form.role === 'resident'
                                                ? 'e.g. Unit 4B'
                                                : 'e.g. 2354'
                                        "
                                    />
                                </div>
                                <div
                                    v-if="inComplex || form.role === 'resident'"
                                    class="grid gap-2"
                                >
                                    <Label for="complex"
                                        >Complex / Estate Name</Label
                                    >
                                    <input
                                        id="complex"
                                        v-model="form.complex_name"
                                        placeholder="e.g. Green Valley Estate"
                                        :required="inComplex"
                                    />
                                </div>
                            </div>

                            <div
                                class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2"
                            >
                                <div class="grid gap-2">
                                    <Label for="address_line_1"
                                        >Street Address</Label
                                    >
                                    <input
                                        id="address_line_1"
                                        v-model="form.address_line_1"
                                        placeholder="e.g. 123 Maple Ave"
                                    />
                                    <p
                                        v-if="errors.address_line_1"
                                        class="text-sm text-red-600"
                                    >
                                        {{ errors.address_line_1[0] }}
                                    </p>
                                </div>
                                <div class="grid gap-2">
                                    <Label for="suburb">Suburb / Area</Label>
                                    <input
                                        id="suburb"
                                        v-model="form.suburb"
                                        placeholder="e.g. Morningside"
                                    />
                                    <p
                                        v-if="errors.suburb"
                                        class="text-sm text-red-600"
                                    >
                                        {{ errors.suburb[0] }}
                                    </p>
                                </div>
                            </div>

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
                                            Auto-generated. Send to household
                                            via their first login.
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        @click="regeneratePins"
                                        class="rounded-md border border-gray-200 px-2.5 py-1.5 text-[11px] font-bold text-gray-600 uppercase hover:bg-gray-50 active:scale-95"
                                    >
                                        ↻ Regenerate
                                    </button>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div class="grid gap-1.5">
                                        <Label for="safe_cancel_pin"
                                            ><span
                                                class="flex items-center gap-1.5"
                                                ><span
                                                    class="inline-block h-2 w-2 rounded-full bg-green-500"
                                                ></span>
                                                Cancel Code</span
                                            ></Label
                                        >
                                        <input
                                            id="safe_cancel_pin"
                                            v-model="form.safe_cancel_pin"
                                            maxlength="6"
                                            class="w-full rounded-md border-gray-300 bg-gray-50 pr-8 font-mono text-lg font-bold tracking-widest shadow-sm"
                                            placeholder="——————"
                                            readonly
                                        />
                                        <p class="text-[13px] text-gray-400">
                                            Genuine false alarm cancel
                                        </p>
                                    </div>
                                    <div class="grid gap-1.5">
                                        <Label for="duress_pin"
                                            ><span
                                                class="flex items-center gap-1.5"
                                                ><span
                                                    class="inline-block h-2 w-2 rounded-full bg-red-500"
                                                ></span>
                                                Duress Code</span
                                            ></Label
                                        >
                                        <input
                                            id="duress_pin"
                                            v-model="form.duress_pin"
                                            maxlength="6"
                                            class="w-full rounded-md border-red-200 bg-red-50 pr-8 font-mono text-lg font-bold tracking-widest shadow-sm focus:border-red-400 focus:ring-red-200"
                                            placeholder="——————"
                                            readonly
                                        />
                                        <p class="text-[13px] text-gray-400">
                                            Covert — keeps patrollers on route
                                        </p>
                                    </div>
                                </div>
                                <div
                                    class="mt-3 rounded-md border border-amber-100 bg-amber-50 px-3 py-2 text-[11px] text-amber-700"
                                >
                                    Never share the duress code label with the
                                    household — they should only know it as
                                    their "emergency code".
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-3">
                            <Label for="password">Set New Password</Label>
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
                                <span v-if="loading" class="loader mr-2"></span>
                                <span>{{
                                    loading
                                        ? isEditing
                                            ? 'Updating...'
                                            : 'Adding...'
                                        : isEditing
                                          ? isHousehold
                                              ? 'Update Household'
                                              : 'Update Personnel'
                                          : isHousehold
                                            ? 'Add Household'
                                            : 'Add Personnel'
                                }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- Delete confirm modal -->
    <div
        v-if="showDeleteModal"
        class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 backdrop-blur-sm"
    >
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-2xl">
            <h2 class="text-lg font-bold text-gray-900">Confirm Deletion</h2>
            <p class="mt-2 text-sm text-gray-500">
                Are you sure you want to delete this record? This action is
                permanent.
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
                    Yes, Delete
                </button>
            </div>
        </div>
    </div>

    <!-- Regenerate invite confirmation modal -->
    <div
        v-if="confirmRegenerateInvite"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
    >
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
            <div class="mb-4 flex items-center gap-3">
                <div
                    class="flex h-10 w-10 items-center justify-center rounded-full bg-amber-100"
                >
                    <span class="text-xl">↻</span>
                </div>
                <div>
                    <h3 class="text-base font-semibold text-gray-900">
                        Regenerate Invite Link?
                    </h3>
                    <p class="text-sm text-gray-500">
                        {{ confirmRegenerateInvite.channel_name }}
                    </p>
                </div>
            </div>
            <div
                class="mb-5 rounded-lg border border-amber-100 bg-amber-50 p-4 text-sm text-amber-800"
            >
                <p class="font-semibold">
                    ⚠ This will invalidate the current link.
                </p>
                <p class="mt-1">
                    Anyone who has not yet registered using the old link will
                    need the new link. Households who already registered are not
                    affected.
                </p>
            </div>
            <div class="flex justify-end gap-3">
                <button
                    @click="confirmRegenerateInvite = null"
                    class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50"
                >
                    Cancel
                </button>
                <button
                    @click="proceedRegenerate"
                    :disabled="isRegenerating"
                    class="flex items-center gap-2 rounded-lg bg-amber-500 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-amber-600 disabled:opacity-60"
                >
                    <div
                        v-if="isRegenerating"
                        class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"
                    ></div>
                    <span>{{
                        isRegenerating ? 'Regenerating...' : 'Yes, Regenerate'
                    }}</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Toggle status modal -->
    <div
        v-if="confirmToggleEmployee"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
    >
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
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
                                ? 'Deactivate'
                                : 'Activate'
                        }}
                        {{
                            isHouseholdRole(
                                confirmToggleEmployee.user.occupation,
                            )
                                ? 'Household'
                                : 'Personnel'
                        }}
                    </h3>
                    <p class="text-sm text-gray-500">
                        {{ confirmToggleEmployee.user.name }}
                    </p>
                </div>
            </div>
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
                    They will regain access to the Echo Link app and can log in
                    with their existing credentials.
                </p>
            </div>
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

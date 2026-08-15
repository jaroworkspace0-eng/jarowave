<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import 'intl-tel-input/build/css/intlTelInput.css';
import { computed, onMounted, ref, watch } from 'vue';
import Multiselect from 'vue-multiselect';
import { VueTelInput } from 'vue-tel-input';
import 'vue-tel-input/vue-tel-input.css';

import {
    AlertTriangle,
    Ban,
    CheckCircle2,
    Clock,
    CreditCard,
    History,
    Loader2,
    MapPinOff,
    MoreVertical,
    Pencil,
    ShieldCheck,
    ShieldOff,
    Trash2,
    XCircle,
} from 'lucide-vue-next';

const auth = useAuthStore();

onMounted(() => {
    if (auth.user?.role !== 'admin' && auth.user?.role !== 'client') {
        router.visit('/dashboard');
    }
});

const showInviteModal = ref(false);

// ─── helpers ──────────────────────────────────────────────────────────────────
const generatePin = () => String(Math.floor(100000 + Math.random() * 900000));
const isHouseholdRole = (role: string) =>
    role === 'household' || role === 'resident';

// Date/time formatter used for "Joined" and "Last Payment" columns.
const formatDateTime = (dateStr: string | null | undefined) => {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return '—';
    return d.toLocaleString('en-ZA', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

// Presentable label for a payment method/gateway string (e.g. "payfast" -> "PayFast").
const PAYMENT_METHOD_LABELS: Record<string, string> = {
    payfast: 'PayFast',
    eft: 'EFT',
    manual: 'Manual',
    cash: 'Cash',
};
const formatPaymentMethod = (method: string | null | undefined) => {
    if (!method) return '';
    return (
        PAYMENT_METHOD_LABELS[method.toLowerCase()] ??
        method.charAt(0).toUpperCase() + method.slice(1)
    );
};

// ─── tab state ────────────────────────────────────────────────────────────────
const activeTab = ref<'personnel' | 'households'>('personnel');
const householdSubTab = ref<'estate' | 'standalone'>('estate');

// ─── invite links state ───────────────────────────────────────────────────────
const invites = ref<any[]>([]);
const inviteLoading = ref(true);
const isGenerating = ref(false);
const selectedChannelId = ref('');
const selectedAdminClientId = ref('');
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

const personnel = ref<any>({ data: [], from: 0, to: 0, total: 0, links: [] });
const personnelList = ref<any[]>([]);
const personnelTotal = ref(0);

// Estate and Standalone households are now two independently paginated
// buckets (role='household' only — 'resident' and 'estate_billing' are
// excluded from both, even when is_estate=1). Each carries its own
// from/to/total/links so pagination never bleeds between the two tabs.
const householdEstate = ref<any>({
    data: [],
    from: 0,
    to: 0,
    total: 0,
    links: [],
});
const householdStandalone = ref<any>({
    data: [],
    from: 0,
    to: 0,
    total: 0,
    links: [],
});

const employees = ref<any>({ data: [], from: 0, to: 0, total: 0, links: [] });

// ── Subscription management state ─────────────────────────────────────────────
const subActionMenu = ref<number | null>(null);
const menuPosition = ref({ top: 0, right: 0 });
const subLoading = ref<number | null>(null);
const subFlash = ref<{ msg: string; type: 'success' | 'error' } | null>(null);
const showPayHistory = ref(false);
const payHistorySub = ref<any>(null);
const payHistoryData = ref<any[]>([]);
const payHistoryLoading = ref(false);

const eftModal = ref<any>(null);
const eftAmount = ref('80');
const eftNote = ref('');
const eftProof = ref<File | null>(null);
const eftProofName = ref('');

const confirmSubAction = ref<{
    sub: any;
    action: string;
    label: string;
} | null>(null);

const conductBlockModal = ref<any>(null);
const conductBlockReason = ref('');

const searchQuery = ref('');
let searchTimeout: any = null;

const confirmDeleteInvite = ref<any>(null);
const isDeletingInvite = ref(false);

const promptDeleteInvite = (invite: any) => {
    confirmDeleteInvite.value = invite;
};

const proceedDeleteInvite = async () => {
    if (!confirmDeleteInvite.value) return;
    try {
        isDeletingInvite.value = true;
        await axios.delete(
            `${import.meta.env.VITE_APP_URL}/api/invite/${confirmDeleteInvite.value.id}`,
            getHeaders(),
        );
        invites.value = invites.value.filter(
            (i) => i.id !== confirmDeleteInvite.value.id,
        );
        showInviteFlash(
            `Invite link for ${confirmDeleteInvite.value.channel_name} deleted.`,
        );
    } catch {
        showInviteFlash('Failed to delete invite link.', 'error');
    } finally {
        isDeletingInvite.value = false;
        confirmDeleteInvite.value = null;
    }
};

const handleSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        reloadEmployees();
    }, 400);
};

// ── Subscription helpers ──────────────────────────────────────────────────────
const subStatusLabel: Record<string, string> = {
    active: '✓ Active',
    trialing: '⏳ Trial',
    past_due: '⚠ Overdue',
    cancelled: '✕ Cancelled',
};

const subStatusClass: Record<string, string> = {
    active: 'border-green-200 bg-green-100 text-green-700',
    trialing: 'border-orange-200 bg-orange-100 text-orange-700',
    past_due: 'border-red-200 bg-red-100 text-red-700',
    cancelled: 'border-gray-200 bg-gray-100 text-gray-500',
};

function showSubFlash(msg: string, type: 'success' | 'error' = 'success') {
    subFlash.value = { msg, type };
    setTimeout(() => (subFlash.value = null), 4000);
}

function toggleSubMenu(subId: number, event: MouseEvent) {
    if (subActionMenu.value === subId) {
        subActionMenu.value = null;
        return;
    }
    const btn = event.currentTarget as HTMLElement;
    const rect = btn.getBoundingClientRect();
    const dropdownHeight = 320;
    const spaceBelow = window.innerHeight - rect.bottom;

    menuPosition.value = {
        top:
            spaceBelow > dropdownHeight
                ? rect.bottom + 4
                : rect.top - dropdownHeight - 4,
        right: window.innerWidth - rect.right,
    };
    subActionMenu.value = subId;
}

function closeSubMenus() {
    subActionMenu.value = null;
}

async function openPayHistory(employee: any) {
    const sub = employee.user?.subscription;
    if (!sub) return;
    payHistorySub.value = { ...sub, userName: employee.user.name };
    payHistoryData.value = [];
    showPayHistory.value = true;
    payHistoryLoading.value = true;
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/admin/subscriptions/${sub.id}/payments`,
            getHeaders(),
        );
        payHistoryData.value = data;
    } catch {
        payHistoryData.value = [];
    } finally {
        payHistoryLoading.value = false;
    }
}

function openEftModal(employee: any) {
    eftModal.value = {
        ...employee.user.subscription,
        userName: employee.user.name,
    };
    eftAmount.value = '80';
    eftNote.value = '';
    eftProof.value = null;
    eftProofName.value = '';
    subActionMenu.value = null;
}

async function submitEftPayment() {
    if (!eftModal.value) return;
    subLoading.value = eftModal.value.id;
    try {
        const formData = new FormData();
        formData.append('amount', eftAmount.value);
        formData.append('note', eftNote.value);
        if (eftProof.value) formData.append('proof', eftProof.value);

        const { data } = await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/admin/subscriptions/${eftModal.value.id}/eft-payment`,
            formData,
            {
                headers: {
                    ...getHeaders().headers,
                },
            },
        );
        showSubFlash(data.message);
        eftModal.value = null;
        await reloadEmployees();
    } catch (err: any) {
        console.error('EFT error:', err.response?.data);
        showSubFlash(
            err.response?.data?.message ?? 'Failed to record payment.',
            'error',
        );
    } finally {
        subLoading.value = null;
    }
}

function promptSubAction(employee: any, action: string) {
    const sub = employee.user?.subscription;
    if (!sub) return;
    const labels: Record<string, string> = {
        suspend: 'Suspend SOS for this household?',
        unsuspend: 'Reinstate SOS for this household?',
        cancel: 'Cancel this subscription?',
    };
    confirmSubAction.value = { sub, action, label: labels[action] };
    subActionMenu.value = null;
}

async function proceedSubAction() {
    if (!confirmSubAction.value) return;
    const { sub, action } = confirmSubAction.value;
    subLoading.value = sub.id;
    try {
        const { data } = await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/admin/subscriptions/${sub.id}/${action}`,
            {},
            getHeaders(),
        );
        showSubFlash(data.message);
        await reloadEmployees();
    } catch (err: any) {
        showSubFlash(err.response?.data?.message ?? 'Action failed.', 'error');
    } finally {
        subLoading.value = null;
        confirmSubAction.value = null;
    }
}

function promptConductBlock(employee: any) {
    const sub = employee.user?.subscription;
    if (!sub) return;
    conductBlockModal.value = { ...sub, userName: employee.user.name };
    conductBlockReason.value = '';
}

async function submitConductBlock() {
    if (!conductBlockModal.value) return;
    subLoading.value = conductBlockModal.value.id;
    try {
        const { data } = await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/admin/subscriptions/${conductBlockModal.value.id}/conduct-block`,
            { reason: conductBlockReason.value },
            getHeaders(),
        );
        showSubFlash(data.message);
        conductBlockModal.value = null;
        await reloadEmployees();
    } catch (err: any) {
        showSubFlash(err.response?.data?.message ?? 'Block failed.', 'error');
    } finally {
        subLoading.value = null;
    }
}

async function toggleActivationFee(employee: any) {
    const sub = employee.user?.subscription;
    if (!sub) return;
    subLoading.value = sub.id;
    try {
        const { data } = await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/admin/subscriptions/${sub.id}/activation-fee`,
            { paid: !sub.activation_fee_paid },
            getHeaders(),
        );
        sub.activation_fee_paid = !sub.activation_fee_paid;
        showSubFlash(data.message);
    } catch {
        showSubFlash('Failed to update activation fee.', 'error');
    } finally {
        subLoading.value = null;
    }
}

// ─── computed lists ───────────────────────────────────────────────────────────

// Which paginated bucket is currently on screen — drives the table body
// and the pagination bar for the Households tab.
const activeHouseholdPage = computed(() =>
    householdSubTab.value === 'estate'
        ? householdEstate.value
        : householdStandalone.value,
);

const clientOrgType = computed(() => {
    const combined = [
        ...householdEstate.value.data,
        ...householdStandalone.value.data,
    ];
    const first = combined.find((e) => e.user?.subscription?.client_type);
    return first?.user?.subscription?.client_type ?? 'watch';
});

const unitPrice = computed(() => 80);

const clientShare = computed(() =>
    clientOrgType.value === 'estate' ? 30 : 52,
);

const platformShare = computed(() =>
    clientOrgType.value === 'estate' ? 50 : 28,
);

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
    setTimeout(() => (flashMessage.value = null), 3500);
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
    phone: '',
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
    activation_fee_paid: false,
    is_estate: false,
    is_gate_guard: false,
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

// Gate guards can only be tied to a single channel (single client/estate),
// since their pay is a fixed share of that specific estate's guard fee.
watch(
    () => form.value.is_gate_guard,
    (isGateGuard) => {
        if (isGateGuard && form.value.channel_ids.length > 1) {
            form.value.channel_ids = [form.value.channel_ids[0]];
        }
    },
);

watch(selectedAdminClientId, (clientId) => {
    if (clientId) {
        loadClientChannels(clientId);
        loadInvites(clientId);
    } else {
        clientChannels.value = [];
        invites.value = [];
    }
});

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

const reloadEmployees = async (
    personnelUrl?: string,
    householdUrl?: string,
) => {
    try {
        const params = new URLSearchParams(window.location.search);
        const status = params.get('status');
        const endpoint = `${import.meta.env.VITE_APP_URL}/api/employees`;
        const { data } = await axios.get(
            personnelUrl || householdUrl || endpoint,
            {
                params: {
                    status,
                    search: searchQuery.value || undefined,
                },
                ...getHeaders(),
            },
        );
        personnel.value = data.personnel;
        personnelList.value = data.personnel.data;
        personnelTotal.value = data.personnel_total;

        householdEstate.value = data.household_estate;
        householdStandalone.value = data.household_standalone;
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

const loadClientChannels = async (clientId?: string) => {
    try {
        const params = clientId ? { client_id: clientId } : {};
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/channels/mine`,
            { ...getHeaders(), params },
        );
        clientChannels.value = data;
    } catch (err) {
        console.error('loadClientChannels failed:', err);
    }
};

const loadInvites = async (clientId?: string) => {
    try {
        const params = clientId ? { client_id: clientId } : {};
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/invite`,
            { ...getHeaders(), params },
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
        const payload: any = { channel_id: selectedChannelId.value };
        if (auth.user?.role === 'admin') {
            payload.client_id = selectedAdminClientId.value;
        }
        const { data } = await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/invite/generate`,
            payload,
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
    if (auth.user?.role === 'admin') {
        inviteLoading.value = false;
    } else {
        loadInvites();
        loadClientChannels();
    }
});

// ─── modal ────────────────────────────────────────────────────────────────────
const openModal = (forceHousehold = false) => {
    isEditing.value = false;
    selectedRole.value = '';
    inComplex.value = false;
    errors.value = {};
    Object.assign(form.value, {
        id: null,
        name: '',
        email: '',
        phone: '',
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
        activation_fee_paid: false,
        is_gate_guard: false,
        is_estate: false,
    });
    if (forceHousehold)
        selectedRole.value = { text: 'Household', value: 'household' } as any;
    showModal.value = true;
};

const editEmployee = (employee: any) => {
    isEditing.value = true;
    errors.value = {};
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
    form.value.is_gate_guard = employee.user.is_gate_guard ?? false;
    form.value.is_estate = employee.user.is_estate ?? false;

    // Safety net for pre-fix data: a gate guard should only ever have one
    // channel. If an older record somehow has more than one, keep just the
    // first so the single-select UI and payload stay consistent.
    if (form.value.is_gate_guard && form.value.channel_ids.length > 1) {
        form.value.channel_ids = [form.value.channel_ids[0]];
    }

    form.value.activation_fee_paid =
        employee.user.subscription?.activation_fee_paid ?? false;
    const allOptions = roleGroups.flatMap((g) => g.options);
    selectedRole.value =
        (allOptions.find((o) => o.value === form.value.occupation) as any) ||
        '';
    showModal.value = true;
    form.value.is_estate = employee.user.is_estate ?? false;
    inComplex.value = employee.user.is_estate ?? !!employee.user.complex_name;
};

const closeModal = () => {
    showModal.value = false;
};

// ─── submit ───────────────────────────────────────────────────────────────────
const submitEmployee = async () => {
    try {
        loading.value = true;
        form.value.is_estate = inComplex.value;
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

const confirmNoCoverage = ref(null);

const deactivateNoCoverage = (employee) => {
    confirmNoCoverage.value = employee;
    subActionMenu.value = null;
};

const proceedNoCoverage = async () => {
    const employee = confirmNoCoverage.value;
    if (!employee) return;

    try {
        subLoading.value = employee.user.subscription?.id ?? 'no_coverage';
        const { data } = await axios.post(
            `/api/users/${employee.user.id}/deactivate-no-coverage`,
        );
        employee.user.is_active = false;
        if (employee.user.subscription) {
            employee.user.subscription.status = 'cancelled';
        }
        confirmNoCoverage.value = null;
        if (data.billing_notes?.length) {
            alert(
                `Deactivated with notes:\n\n${data.billing_notes.join('\n')}`,
            );
        }
    } catch (e) {
        console.log(
            '❌ deactivateNoCoverage error:',
            e.response?.status,
            e.response?.data,
        );
        alert(
            e.response?.data?.message ??
                'Deactivation failed. Please try again.',
        );
    } finally {
        subLoading.value = null;
    }
};
</script>
<template>
    <Head title="Personnel" />

    <AppLayout>
        <div class="page-root">
            <!-- PAGE HEADER -->
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Directory</div>
                    <h1 class="page-header__title">
                        {{
                            activeTab === 'personnel'
                                ? 'Field Units'
                                : 'Households'
                        }}
                    </h1>
                </div>
                <div class="page-header__right">
                    <button
                        v-if="activeTab === 'households'"
                        class="btn-ghost"
                        @click="showInviteModal = true"
                    >
                        Invite Links
                    </button>
                    <button
                        v-if="activeTab === 'personnel'"
                        class="btn-primary"
                        @click="openModal(false)"
                    >
                        Add Field Unit
                    </button>
                    <button
                        v-if="activeTab === 'households'"
                        class="btn-primary"
                        @click="openModal(true)"
                    >
                        Add Household
                    </button>
                </div>
            </div>

            <!-- TAB + SEARCH BAR -->
            <div class="filter-bar">
                <div class="filter-bar__chips">
                    <button
                        class="chip"
                        :class="{ 'chip--active': activeTab === 'personnel' }"
                        @click="activeTab = 'personnel'"
                    >
                        Field Units
                        <span class="chip__count">{{ personnelTotal }}</span>
                    </button>
                    <button
                        class="chip"
                        :class="{ 'chip--active': activeTab === 'households' }"
                        @click="activeTab = 'households'"
                    >
                        Households
                        <span class="chip__count">{{
                            householdEstate.total + householdStandalone.total
                        }}</span>
                    </button>
                </div>
                <div class="search-wrap">
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="search-wrap__icon"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M21 21l-4.35-4.35M17 11A6 6 0 105 11a6 6 0 0012 0z"
                        />
                    </svg>
                    <input
                        v-model="searchQuery"
                        @input="handleSearch"
                        type="text"
                        class="search-wrap__input"
                        :placeholder="
                            activeTab === 'personnel'
                                ? 'Search field units…'
                                : 'Search households…'
                        "
                    />
                    <span
                        v-if="searchQuery"
                        class="search-wrap__clear"
                        @click="
                            searchQuery = '';
                            reloadEmployees();
                        "
                        >×</span
                    >
                </div>
            </div>

            <!-- ══════════════════════════════════════════ -->
            <!-- FIELD UNITS TAB                            -->
            <!-- ══════════════════════════════════════════ -->
            <div v-if="activeTab === 'personnel'" class="table-card">
                <div v-if="personnelList.length === 0" class="empty-state">
                    <div class="empty-state__icon">
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-8 w-8"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="1.2"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8zm6 1.13a4 4 0 00-3-3.87M9 12a4 4 0 100-8 4 4 0 000 8z"
                            />
                        </svg>
                    </div>
                    <p class="empty-state__title">No field units found</p>
                    <p class="empty-state__sub">
                        Add your first field unit to get started
                    </p>
                </div>
                <div v-else style="overflow-x: auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Contact</th>
                                <th>Assigned Client</th>
                                <th>Role</th>
                                <th>Channels</th>
                                <th>Online / Offline</th>
                                <th>Account</th>
                                <th>Joined</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="employee in personnelList"
                                :key="employee.id"
                            >
                                <td class="td-announce">
                                    <div class="td-announce__title">
                                        {{ employee.user.name }}
                                    </div>
                                    <div class="td-announce__sub">
                                        {{ employee.user.email }}
                                    </div>
                                </td>
                                <td class="td-muted">
                                    {{ employee.user.phone }}
                                </td>
                                <td class="td-muted">
                                    {{
                                        employee.client
                                            ? employee.client.user.name
                                            : 'No Client Assigned'
                                    }}
                                </td>
                                <td class="td-muted">
                                    {{ employee.user.occupation }}
                                </td>
                                <td>
                                    <div
                                        style="
                                            display: flex;
                                            flex-wrap: wrap;
                                            gap: 4px;
                                            max-width: 200px;
                                        "
                                    >
                                        <span
                                            v-for="c in employee.channels"
                                            :key="c.id"
                                            class="channel-pill"
                                        >
                                            <span
                                                class="channel-pill__dot"
                                            ></span>
                                            {{ c.name }}
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <span
                                        class="type-badge"
                                        :class="
                                            employee.user.status === 'online'
                                                ? 'bg-emerald-50 text-emerald-700'
                                                : 'bg-red-50 text-red-600'
                                        "
                                    >
                                        {{
                                            employee.user.status === 'online'
                                                ? 'Online'
                                                : 'Offline'
                                        }}
                                    </span>
                                </td>
                                <td>
                                    <button
                                        @click="toggleStatus(employee)"
                                        class="status-toggle-btn"
                                        :title="
                                            employee.user.is_active
                                                ? 'Deactivate'
                                                : 'Activate'
                                        "
                                    >
                                        <span
                                            class="type-badge"
                                            :class="
                                                employee.user.is_active
                                                    ? 'bg-emerald-50 text-emerald-700'
                                                    : 'bg-red-50 text-red-600'
                                            "
                                        >
                                            {{
                                                employee.user.is_active
                                                    ? 'Active'
                                                    : 'Deactivated'
                                            }}
                                        </span>
                                    </button>
                                </td>
                                <td class="td-muted" style="font-size: 12px">
                                    {{
                                        formatDateTime(employee.user.created_at)
                                    }}
                                </td>
                                <td>
                                    <div style="display: flex; gap: 2px">
                                        <button
                                            @click="editEmployee(employee)"
                                            class="icon-btn icon-btn--edit"
                                            title="Edit"
                                        >
                                            <svg
                                                xmlns="http://www.w3.org/2000/svg"
                                                class="h-4 w-4"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                stroke="currentColor"
                                                stroke-width="2"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
                                                />
                                            </svg>
                                        </button>
                                        <button
                                            @click="confirmDelete(employee.id)"
                                            class="icon-btn icon-btn--danger"
                                            title="Delete"
                                        >
                                            <svg
                                                xmlns="http://www.w3.org/2000/svg"
                                                class="h-4 w-4"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                stroke="currentColor"
                                                stroke-width="2"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
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

                <!-- Field unit pagination -->
                <div class="pagination-bar" v-if="personnel.data.length > 0">
                    <span class="pagination-bar__info">
                        Showing {{ personnel.from || 0 }}–{{
                            personnel.to || 0
                        }}
                        of {{ personnel.total }}
                    </span>
                    <div class="pagination-bar__pages">
                        <template
                            v-for="(link, index) in personnel.links"
                            :key="index"
                        >
                            <button
                                v-if="link.url"
                                @click="reloadEmployees(link.url, undefined)"
                                v-html="link.label"
                                class="page-btn"
                                :class="{ 'page-btn--active': link.active }"
                            />
                            <span
                                v-else
                                v-html="link.label"
                                class="page-btn page-btn--disabled"
                            />
                        </template>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════ -->
            <!-- HOUSEHOLDS TAB                             -->
            <!-- ══════════════════════════════════════════ -->
            <div v-if="activeTab === 'households'">
                <!-- ESTATE / STANDALONE SUB-TABS -->
                <div class="filter-bar__chips" style="margin-bottom: 12px">
                    <button
                        class="chip"
                        :class="{
                            'chip--active': householdSubTab === 'estate',
                        }"
                        @click="householdSubTab = 'estate'"
                    >
                        Estates
                        <span class="chip__count">{{
                            householdEstate.total
                        }}</span>
                    </button>
                    <button
                        class="chip"
                        :class="{
                            'chip--active': householdSubTab === 'standalone',
                        }"
                        @click="householdSubTab = 'standalone'"
                    >
                        Standalone
                        <span class="chip__count">{{
                            householdStandalone.total
                        }}</span>
                    </button>
                </div>

                <!-- HOUSEHOLDS TABLE -->
                <div class="table-card" style="margin-top: 20px">
                    <div
                        v-if="activeHouseholdPage.data.length === 0"
                        class="empty-state"
                    >
                        <div class="empty-state__icon">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-8 w-8"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="1.2"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"
                                />
                            </svg>
                        </div>
                        <div
                            v-if="activeHouseholdPage.data.length === 0"
                            class="empty-state"
                        >
                            <p class="empty-state__title">
                                {{
                                    householdSubTab === 'estate'
                                        ? 'No estate households yet'
                                        : 'No standalone households yet'
                                }}
                            </p>
                        </div>
                        <p class="empty-state__sub">
                            Share your invite links above to start onboarding
                            households
                        </p>
                    </div>
                    <div v-else style="overflow-x: auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Household</th>
                                    <th>Contact</th>
                                    <th>Address</th>
                                    <th>Unit</th>
                                    <th>Role</th>
                                    <th>Joined</th>
                                    <th>Trial Ends</th>
                                    <th>Monthly Fee</th>
                                    <th>Your Share</th>
                                    <th>Status</th>
                                    <th>Activation Fee</th>
                                    <th>Last Payment</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="employee in activeHouseholdPage.data"
                                    :key="employee.id"
                                >
                                    <td class="td-announce">
                                        <div class="td-announce__title">
                                            {{ employee.user.name }}
                                        </div>
                                        <div class="td-announce__sub">
                                            {{ employee.user.email }}
                                        </div>
                                    </td>
                                    <td class="td-muted">
                                        {{ employee.user.phone }}
                                    </td>
                                    <td
                                        class="td-muted"
                                        style="max-width: 180px"
                                    >
                                        <span
                                            style="
                                                display: block;
                                                white-space: nowrap;
                                                overflow: hidden;
                                                text-overflow: ellipsis;
                                                font-size: 12px;
                                            "
                                            >{{
                                                employee.user.address_line_1 ||
                                                '—'
                                            }}</span
                                        >
                                        <span
                                            v-if="employee.user.suburb"
                                            style="
                                                font-size: 11px;
                                                color: #94a3b8;
                                            "
                                            >{{ employee.user.suburb }}</span
                                        >
                                    </td>
                                    <td
                                        class="td-muted"
                                        style="font-size: 12px"
                                    >
                                        <div v-if="employee.user.unit_number">
                                            {{ employee.user.unit_number }}
                                        </div>
                                        <div
                                            v-if="employee.user.complex_name"
                                            style="color: #94a3b8"
                                        >
                                            {{ employee.user.complex_name }}
                                        </div>
                                        <span v-if="!employee.user.unit_number"
                                            >—</span
                                        >
                                    </td>
                                    <td>
                                        <span
                                            class="type-badge bg-amber-50 text-amber-700"
                                            style="text-transform: capitalize"
                                            >{{
                                                employee.user.occupation
                                            }}</span
                                        >
                                    </td>
                                    <td
                                        class="td-muted"
                                        style="font-size: 12px"
                                    >
                                        {{
                                            formatDateTime(
                                                employee.user.created_at,
                                            )
                                        }}
                                    </td>
                                    <td
                                        class="td-muted"
                                        style="font-size: 12px"
                                    >
                                        <div
                                            v-if="
                                                employee.user.subscription
                                                    ?.status === 'trialing' &&
                                                employee.user.subscription
                                                    ?.trial_ends_at
                                            "
                                        >
                                            <div
                                                :style="
                                                    (new Date(
                                                        employee.user.subscription.trial_ends_at,
                                                    ),
                                                    new Date(
                                                        Date.now() +
                                                            7 *
                                                                24 *
                                                                60 *
                                                                60 *
                                                                1000,
                                                    )
                                                        ? 'font-weight:700;color:#dc2626'
                                                        : 'font-weight:600;color:#475569')
                                                "
                                            >
                                                {{
                                                    new Date(
                                                        employee.user.subscription.trial_ends_at,
                                                    ).toLocaleDateString(
                                                        'en-ZA',
                                                        {
                                                            day: 'numeric',
                                                            month: 'short',
                                                            year: 'numeric',
                                                        },
                                                    )
                                                }}
                                            </div>
                                            <div style="color: #94a3b8">
                                                {{
                                                    Math.max(
                                                        0,
                                                        Math.ceil(
                                                            (new Date(
                                                                employee.user.subscription.trial_ends_at,
                                                            ) -
                                                                new Date()) /
                                                                (1000 *
                                                                    60 *
                                                                    60 *
                                                                    24),
                                                        ),
                                                    )
                                                }}
                                                days left
                                            </div>
                                        </div>
                                        <span v-else style="color: #94a3b8"
                                            >—</span
                                        >
                                    </td>
                                    <td class="td-announce__title">
                                        R{{ unitPrice }}
                                    </td>
                                    <td>
                                        <div
                                            style="
                                                font-weight: 700;
                                                color: #16a34a;
                                            "
                                        >
                                            R{{ clientShare }}
                                        </div>
                                        <div
                                            style="
                                                font-size: 10px;
                                                color: #94a3b8;
                                            "
                                        >
                                            {{
                                                clientOrgType === 'estate'
                                                    ? 'Estate rate'
                                                    : 'Watch rate'
                                            }}
                                        </div>
                                    </td>
                                    <td>
                                        <div
                                            style="
                                                display: flex;
                                                flex-direction: column;
                                                gap: 6px;
                                                align-items: flex-start;
                                            "
                                        >
                                            <button
                                                @click="toggleStatus(employee)"
                                                class="status-toggle-btn"
                                            >
                                                <span
                                                    class="type-badge"
                                                    :class="
                                                        employee.user.is_active
                                                            ? 'bg-emerald-50 text-emerald-700'
                                                            : 'bg-red-50 text-red-600'
                                                    "
                                                >
                                                    {{
                                                        employee.user.is_active
                                                            ? 'Active'
                                                            : 'Deactivated'
                                                    }}
                                                </span>
                                            </button>
                                            <span
                                                v-if="
                                                    employee.user.subscription
                                                "
                                                class="type-badge"
                                                style="font-size: 10px"
                                                :class="{
                                                    'bg-emerald-50 text-emerald-700':
                                                        employee.user
                                                            .subscription
                                                            .status ===
                                                        'active',
                                                    'bg-orange-50 text-orange-700':
                                                        employee.user
                                                            .subscription
                                                            .status ===
                                                        'trialing',
                                                    'bg-red-50 text-red-600':
                                                        employee.user
                                                            .subscription
                                                            .status ===
                                                        'past_due',
                                                    'bg-slate-100 text-slate-500':
                                                        employee.user
                                                            .subscription
                                                            .status ===
                                                        'cancelled',
                                                }"
                                            >
                                                <CheckCircle2
                                                    v-if="
                                                        employee.user
                                                            .subscription
                                                            .status === 'active'
                                                    "
                                                    class="h-3 w-3"
                                                />
                                                <Clock
                                                    v-else-if="
                                                        employee.user
                                                            .subscription
                                                            .status ===
                                                        'trialing'
                                                    "
                                                    class="h-3 w-3"
                                                />
                                                <AlertTriangle
                                                    v-else-if="
                                                        employee.user
                                                            .subscription
                                                            .status ===
                                                        'past_due'
                                                    "
                                                    class="h-3 w-3"
                                                />
                                                <XCircle
                                                    v-else
                                                    class="h-3 w-3"
                                                />
                                                {{
                                                    {
                                                        active: 'Paying',
                                                        trialing: 'Trial',
                                                        past_due: 'Overdue',
                                                        cancelled: 'Cancelled',
                                                    }[
                                                        employee.user
                                                            .subscription.status
                                                    ] ??
                                                    employee.user.subscription
                                                        .status
                                                }}
                                            </span>
                                            <span
                                                v-else
                                                style="
                                                    font-size: 10px;
                                                    color: #94a3b8;
                                                "
                                                >No subscription</span
                                            >
                                        </div>
                                    </td>
                                    <td>
                                        <span
                                            v-if="
                                                employee.user?.subscription
                                                    ?.activation_fee_paid
                                            "
                                            class="type-badge bg-emerald-50 text-emerald-700"
                                        >
                                            ✓ Paid
                                        </span>
                                        <span
                                            v-else
                                            class="type-badge bg-orange-50 text-orange-700"
                                        >
                                            R50 Pending
                                        </span>
                                    </td>
                                    <td
                                        class="td-muted"
                                        style="font-size: 12px"
                                    >
                                        <div
                                            v-if="
                                                employee.user?.subscription
                                                    ?.last_payment_at
                                            "
                                        >
                                            <div
                                                style="
                                                    font-weight: 600;
                                                    color: #1a2332;
                                                "
                                            >
                                                {{
                                                    formatDateTime(
                                                        employee.user
                                                            .subscription
                                                            .last_payment_at,
                                                    )
                                                }}
                                            </div>
                                            <div style="color: #94a3b8">
                                                {{
                                                    formatPaymentMethod(
                                                        employee.user
                                                            .subscription
                                                            .last_payment_gateway,
                                                    ) || '—'
                                                }}
                                            </div>
                                        </div>
                                        <span v-else style="color: #94a3b8"
                                            >—</span
                                        >
                                    </td>
                                    <td
                                        class="relative"
                                        style="overflow: visible"
                                    >
                                        <div
                                            style="
                                                display: flex;
                                                align-items: center;
                                                gap: 2px;
                                            "
                                            @click.stop
                                        >
                                            <button
                                                @click="editEmployee(employee)"
                                                class="icon-btn icon-btn--edit"
                                                title="Edit"
                                            >
                                                <Pencil class="h-4 w-4" />
                                            </button>

                                            <!-- Subscription actions dropdown -->
                                            <div
                                                class="relative"
                                                v-if="
                                                    employee.user?.subscription
                                                "
                                            >
                                                <button
                                                    @click="
                                                        toggleSubMenu(
                                                            employee.user
                                                                .subscription
                                                                .id,
                                                            $event,
                                                        )
                                                    "
                                                    :disabled="
                                                        subLoading ===
                                                        employee.user
                                                            .subscription.id
                                                    "
                                                    class="icon-btn"
                                                    style="margin-right: 10px"
                                                    title="Subscription actions"
                                                >
                                                    <Loader2
                                                        v-if="
                                                            subLoading ===
                                                            employee.user
                                                                .subscription.id
                                                        "
                                                        class="spin h-4 w-4"
                                                    />
                                                    <MoreVertical
                                                        v-else
                                                        class="h-4 w-4"
                                                    />
                                                </button>

                                                <!-- Dropdown -->
                                                <div
                                                    v-if="
                                                        subActionMenu ===
                                                        employee.user
                                                            .subscription.id
                                                    "
                                                    class="sub-menu"
                                                    :style="{
                                                        top:
                                                            menuPosition.top +
                                                            'px',
                                                        right:
                                                            menuPosition.right +
                                                            'px',
                                                    }"
                                                >
                                                    <button
                                                        @click="
                                                            toggleActivationFee(
                                                                employee,
                                                            );
                                                            subActionMenu =
                                                                null;
                                                        "
                                                        class="sub-menu__item"
                                                    >
                                                        <CreditCard
                                                            class="sub-menu__item-icon"
                                                        />
                                                        {{
                                                            employee.user
                                                                .subscription
                                                                .activation_fee_paid
                                                                ? 'Unmark Activation Fee'
                                                                : 'Mark Activation Fee Paid'
                                                        }}
                                                    </button>

                                                    <div
                                                        class="sub-menu__divider"
                                                    ></div>

                                                    <button
                                                        @click="
                                                            openEftModal(
                                                                employee,
                                                            )
                                                        "
                                                        class="sub-menu__item"
                                                    >
                                                        <CreditCard
                                                            class="sub-menu__item-icon"
                                                        />
                                                        Mark EFT Paid
                                                    </button>

                                                    <button
                                                        v-if="
                                                            employee.user
                                                                .subscription
                                                                .status !==
                                                            'cancelled'
                                                        "
                                                        @click="
                                                            promptSubAction(
                                                                employee,
                                                                employee.user
                                                                    .subscription
                                                                    .sos_suspended_at
                                                                    ? 'unsuspend'
                                                                    : 'suspend',
                                                            )
                                                        "
                                                        class="sub-menu__item"
                                                    >
                                                        <component
                                                            :is="
                                                                employee.user
                                                                    .subscription
                                                                    .sos_suspended_at
                                                                    ? ShieldCheck
                                                                    : ShieldOff
                                                            "
                                                            class="sub-menu__item-icon"
                                                        />
                                                        {{
                                                            employee.user
                                                                .subscription
                                                                .sos_suspended_at
                                                                ? 'Reinstate SOS'
                                                                : 'Suspend SOS'
                                                        }}
                                                    </button>

                                                    <button
                                                        v-if="
                                                            employee.user
                                                                .subscription
                                                                .status !==
                                                            'cancelled'
                                                        "
                                                        @click="
                                                            promptSubAction(
                                                                employee,
                                                                'cancel',
                                                            )
                                                        "
                                                        class="sub-menu__item sub-menu__item--danger"
                                                    >
                                                        <Ban
                                                            class="sub-menu__item-icon"
                                                        />
                                                        Cancel Subscription
                                                    </button>

                                                    <button
                                                        @click="
                                                            deactivateNoCoverage(
                                                                employee,
                                                            )
                                                        "
                                                        class="sub-menu__item sub-menu__item--danger"
                                                    >
                                                        <MapPinOff
                                                            class="sub-menu__item-icon"
                                                        />
                                                        No Coverage - Deactivate
                                                    </button>

                                                    <div
                                                        class="sub-menu__divider"
                                                    ></div>

                                                    <button
                                                        v-if="
                                                            !employee.user
                                                                .subscription
                                                                .conduct_blocked_at
                                                        "
                                                        @click="
                                                            promptConductBlock(
                                                                employee,
                                                            );
                                                            subActionMenu =
                                                                null;
                                                        "
                                                        class="sub-menu__item sub-menu__item--danger"
                                                    >
                                                        <Ban
                                                            class="sub-menu__item-icon"
                                                        />
                                                        Conduct Block
                                                    </button>
                                                    <button
                                                        v-else
                                                        @click="
                                                            promptSubAction(
                                                                employee,
                                                                'conduct-unblock',
                                                            );
                                                            subActionMenu =
                                                                null;
                                                        "
                                                        class="sub-menu__item"
                                                    >
                                                        <ShieldCheck
                                                            class="sub-menu__item-icon"
                                                        />
                                                        Lift Conduct Block
                                                    </button>

                                                    <div
                                                        class="sub-menu__divider"
                                                    ></div>

                                                    <button
                                                        @click="
                                                            openPayHistory(
                                                                employee,
                                                            );
                                                            subActionMenu =
                                                                null;
                                                        "
                                                        class="sub-menu__item"
                                                    >
                                                        <History
                                                            class="sub-menu__item-icon"
                                                        />
                                                        Payment History
                                                    </button>

                                                    <button
                                                        @click="
                                                            confirmDelete(
                                                                employee.id,
                                                            );
                                                            subActionMenu =
                                                                null;
                                                        "
                                                        class="sub-menu__item sub-menu__item--danger"
                                                    >
                                                        <Trash2
                                                            class="sub-menu__item-icon"
                                                        />
                                                        Delete
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- Delete (fallback if no subscription) -->
                                            <button
                                                v-else
                                                @click="
                                                    confirmDelete(employee.id)
                                                "
                                                class="icon-btn icon-btn--danger"
                                                title="Delete"
                                            >
                                                <svg
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    class="h-4 w-4"
                                                    fill="none"
                                                    viewBox="0 0 24 24"
                                                    stroke="currentColor"
                                                    stroke-width="2"
                                                >
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
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

                    <!-- Households pagination -->
                    <div
                        class="pagination-bar"
                        v-if="activeHouseholdPage.data.length > 0"
                    >
                        <span class="pagination-bar__info">
                            Showing {{ activeHouseholdPage.from || 0 }}–{{
                                activeHouseholdPage.to || 0
                            }}
                            of {{ activeHouseholdPage.total }}
                        </span>
                        <div class="pagination-bar__pages">
                            <template
                                v-for="(
                                    link, index
                                ) in activeHouseholdPage.links"
                                :key="index"
                            >
                                <button
                                    v-if="link.url"
                                    @click="
                                        reloadEmployees(undefined, link.url)
                                    "
                                    v-html="link.label"
                                    class="page-btn"
                                    :class="{ 'page-btn--active': link.active }"
                                />
                                <span
                                    v-else
                                    v-html="link.label"
                                    class="page-btn page-btn--disabled"
                                />
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- INVITE LINKS MODAL -->
        <transition name="modal">
            <div
                v-if="showInviteModal"
                class="modal-backdrop"
                @click.self="showInviteModal = false"
            >
                <div class="modal-sheet" style="max-width: 920px">
                    <div class="modal-sheet__header">
                        <div class="modal-sheet__header-left">
                            <div>
                                <div class="modal-sheet__title">
                                    Invitation Links
                                </div>
                                <div class="modal-sheet__sub">
                                    One permanent link per channel - share with
                                    households to join your neighbourhood watch
                                    on Echo Link.
                                </div>
                            </div>
                        </div>
                        <button
                            class="close-btn"
                            @click="showInviteModal = false"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-4 w-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M6 18L18 6M6 6l12 12"
                                />
                            </svg>
                        </button>
                    </div>

                    <div class="modal-sheet__body">
                        <div
                            v-if="inviteFlash"
                            class="invite-flash"
                            :class="
                                inviteFlash.type === 'success'
                                    ? 'invite-flash--success'
                                    : 'invite-flash--error'
                            "
                        >
                            {{ inviteFlash.type === 'success' ? '✓' : '⚠' }}
                            {{ inviteFlash.msg }}
                        </div>

                        <div v-if="inviteLoading" class="invite-loading">
                            <svg
                                class="spin h-4 w-4 text-slate-400"
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                            >
                                <circle
                                    class="opacity-25"
                                    cx="12"
                                    cy="12"
                                    r="10"
                                    stroke="currentColor"
                                    stroke-width="4"
                                />
                                <path
                                    class="opacity-75"
                                    fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                                />
                            </svg>
                            Loading…
                        </div>

                        <template v-else>
                            <div
                                v-if="invites.length > 0"
                                class="invite-table-wrap"
                            >
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Channel</th>
                                            <th>Invite Link</th>
                                            <th style="text-align: center">
                                                Uses
                                            </th>
                                            <th style="text-align: right">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="invite in invites"
                                            :key="invite.id"
                                        >
                                            <td>
                                                <span
                                                    class="type-badge bg-orange-50 text-orange-700"
                                                >
                                                    {{ invite.channel_name }}
                                                </span>
                                            </td>
                                            <td style="max-width: 400px">
                                                <span
                                                    class="token-text"
                                                    style="
                                                        display: block;
                                                        overflow: hidden;
                                                        text-overflow: ellipsis;
                                                        white-space: nowrap;
                                                    "
                                                    >{{
                                                        invite.invite_url
                                                    }}</span
                                                >
                                            </td>
                                            <td
                                                class="td-muted"
                                                style="text-align: center"
                                            >
                                                {{ invite.uses }}
                                            </td>
                                            <td>
                                                <div
                                                    style="
                                                        display: flex;
                                                        align-items: center;
                                                        justify-content: flex-end;
                                                        gap: 6px;
                                                    "
                                                >
                                                    <button
                                                        @click="
                                                            copyInviteLink(
                                                                invite,
                                                            )
                                                        "
                                                        class="icon-btn"
                                                        :class="{
                                                            'icon-btn--edit':
                                                                copiedId ===
                                                                invite.id,
                                                        }"
                                                        title="Copy Invite Link"
                                                    >
                                                        <svg
                                                            v-if="
                                                                copiedId ===
                                                                invite.id
                                                            "
                                                            xmlns="http://www.w3.org/2000/svg"
                                                            class="h-4 w-4"
                                                            fill="none"
                                                            viewBox="0 0 24 24"
                                                            stroke="currentColor"
                                                            stroke-width="2"
                                                        >
                                                            <path
                                                                stroke-linecap="round"
                                                                stroke-linejoin="round"
                                                                d="M5 13l4 4L19 7"
                                                            />
                                                        </svg>
                                                        <svg
                                                            v-else
                                                            xmlns="http://www.w3.org/2000/svg"
                                                            class="h-4 w-4"
                                                            fill="none"
                                                            viewBox="0 0 24 24"
                                                            stroke="currentColor"
                                                            stroke-width="2"
                                                        >
                                                            <path
                                                                stroke-linecap="round"
                                                                stroke-linejoin="round"
                                                                d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"
                                                            />
                                                        </svg>
                                                    </button>

                                                    <a
                                                        :href="`https://wa.me/?text=${encodeURIComponent('Join our ' + invite.channel_name + ' neighbourhood watch on Echo Link! Register for R80/month: ' + invite.invite_url)}`"
                                                        target="_blank"
                                                        class="icon-btn"
                                                        title="Share on WhatsApp"
                                                    >
                                                        <svg
                                                            xmlns="http://www.w3.org/2000/svg"
                                                            class="h-4 w-4"
                                                            fill="none"
                                                            viewBox="0 0 24 24"
                                                            stroke="currentColor"
                                                            stroke-width="2"
                                                        >
                                                            <path
                                                                stroke-linecap="round"
                                                                stroke-linejoin="round"
                                                                d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"
                                                            />
                                                        </svg>
                                                    </a>
                                                    <button
                                                        @click="
                                                            confirmRegenerate(
                                                                invite,
                                                            )
                                                        "
                                                        class="icon-btn"
                                                        title="Regenerate link"
                                                    >
                                                        <svg
                                                            xmlns="http://www.w3.org/2000/svg"
                                                            class="h-4 w-4"
                                                            fill="none"
                                                            viewBox="0 0 24 24"
                                                            stroke="currentColor"
                                                            stroke-width="2"
                                                        >
                                                            <path
                                                                stroke-linecap="round"
                                                                stroke-linejoin="round"
                                                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                                                            />
                                                        </svg>
                                                    </button>
                                                    <button
                                                        @click="
                                                            promptDeleteInvite(
                                                                invite,
                                                            )
                                                        "
                                                        class="icon-btn icon-btn--danger"
                                                        title="Delete invite link"
                                                    >
                                                        <svg
                                                            xmlns="http://www.w3.org/2000/svg"
                                                            class="h-4 w-4"
                                                            fill="none"
                                                            viewBox="0 0 24 24"
                                                            stroke="currentColor"
                                                            stroke-width="2"
                                                        >
                                                            <path
                                                                stroke-linecap="round"
                                                                stroke-linejoin="round"
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

                            <div v-else class="invite-empty">
                                <div style="font-size: 22px">🔗</div>
                                <div class="invite-empty__title">
                                    No invite links yet
                                </div>
                                <div class="invite-empty__sub">
                                    Generate a link below to start onboarding
                                    households per channel
                                </div>
                            </div>

                            <!-- ADMIN ONLY: choose which client this invite link is for -->
                            <div
                                v-if="auth.user?.role === 'admin'"
                                class="field"
                                style="margin-top: 16px"
                            >
                                <label class="field__label"
                                    >Acting on behalf of client</label
                                >
                                <div class="select-wrapper">
                                    <select
                                        v-model="selectedAdminClientId"
                                        class="field__select"
                                    >
                                        <option value="">
                                            -- Select a client --
                                        </option>
                                        <option
                                            v-for="client in clients"
                                            :key="client.id"
                                            :value="client.id"
                                        >
                                            {{ client.user?.name }}
                                        </option>
                                    </select>
                                    <svg
                                        class="select-caret"
                                        xmlns="http://www.w3.org/2000/svg"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        stroke-width="2"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M19 9l-7 7-7-7"
                                        />
                                    </svg>
                                </div>
                            </div>

                            <div
                                v-if="channelsWithoutInvite.length > 0"
                                class="field"
                                style="margin-top: 16px"
                            >
                                <label class="field__label"
                                    >Generate link for a channel</label
                                >
                                <div style="display: flex; gap: 8px">
                                    <div class="select-wrapper" style="flex: 1">
                                        <select
                                            v-model="selectedChannelId"
                                            class="field__select"
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
                                        <svg
                                            class="select-caret"
                                            xmlns="http://www.w3.org/2000/svg"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                            stroke-width="2"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M19 9l-7 7-7-7"
                                            />
                                        </svg>
                                    </div>
                                    <button
                                        @click="generateInviteLink"
                                        :disabled="
                                            isGenerating || !selectedChannelId
                                        "
                                        class="btn-primary"
                                        style="white-space: nowrap"
                                    >
                                        <svg
                                            v-if="isGenerating"
                                            class="spin h-4 w-4"
                                            xmlns="http://www.w3.org/2000/svg"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                        >
                                            <circle
                                                class="opacity-25"
                                                cx="12"
                                                cy="12"
                                                r="10"
                                                stroke="currentColor"
                                                stroke-width="4"
                                            />
                                            <path
                                                class="opacity-75"
                                                fill="currentColor"
                                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                                            />
                                        </svg>
                                        {{
                                            isGenerating
                                                ? 'Generating…'
                                                : 'Generate →'
                                        }}
                                    </button>
                                </div>
                            </div>
                            <div
                                v-else-if="
                                    invites.length > 0 &&
                                    clientChannels.length > 0
                                "
                                style="
                                    margin-top: 8px;
                                    font-size: 12px;
                                    color: #94a3b8;
                                "
                            >
                                ✓ All your channels have invite links.
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </transition>

        <!-- Delete invite confirmation modal -->
        <transition name="modal">
            <div
                v-if="confirmDeleteInvite"
                class="modal-backdrop"
                @click.self="confirmDeleteInvite = null"
            >
                <div class="confirm-modal">
                    <div class="confirm-modal__icon">
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-7 w-7 text-red-500"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="1.5"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                            />
                        </svg>
                    </div>
                    <h2 class="confirm-modal__title">Delete Invite Link?</h2>
                    <p class="confirm-modal__body" style="margin-bottom: 2px">
                        {{ confirmDeleteInvite.channel_name }}
                    </p>
                    <div
                        class="toggle-warning toggle-warning--danger"
                        style="text-align: left"
                    >
                        <p style="font-weight: 700; margin-bottom: 4px">
                            This link will stop working immediately.
                        </p>
                        <p>
                            Anyone who hasn't registered using this link yet
                            won't be able to. This action cannot be undone -
                            you'd need to generate a new link afterward.
                        </p>
                    </div>
                    <div class="confirm-modal__actions">
                        <button
                            @click="confirmDeleteInvite = null"
                            class="btn-ghost"
                        >
                            Cancel
                        </button>
                        <button
                            @click="proceedDeleteInvite"
                            :disabled="isDeletingInvite"
                            class="btn-danger"
                            style="flex: 1.4; justify-content: center"
                        >
                            {{ isDeletingInvite ? 'Deleting…' : 'Yes, Delete' }}
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- ══════════════════════════════════════════ -->
        <!-- ADD / EDIT MODAL                           -->
        <!-- ══════════════════════════════════════════ -->
        <transition name="modal">
            <div
                v-if="showModal"
                class="modal-backdrop"
                @click.self="closeModal"
            >
                <div class="modal-sheet" style="max-width: 680px">
                    <div class="modal-sheet__header">
                        <div class="modal-sheet__header-left">
                            <div>
                                <div class="modal-sheet__title">
                                    {{ isEditing ? 'Edit' : 'Add' }}
                                    {{
                                        isHousehold ? 'Household' : 'Field Unit'
                                    }}
                                </div>
                            </div>
                        </div>
                        <button class="close-btn" @click="closeModal">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-4 w-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M6 18L18 6M6 6l12 12"
                                />
                            </svg>
                        </button>
                    </div>

                    <form
                        @submit.prevent="submitEmployee()"
                        class="modal-sheet__body"
                    >
                        <div v-if="!isHousehold">
                            <div class="field">
                                <label class="field__label"
                                    >Assign Personnel Role</label
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
                                <span
                                    v-if="errors.occupation"
                                    class="field__error"
                                    >{{ errors.occupation[0] }}</span
                                >
                            </div>

                            <!-- Gate Guard checkbox -->
                            <div
                                v-if="
                                    form.occupation === 'security_guard' ||
                                    form.occupation === 'patrol_officer'
                                "
                                class="callout callout--info"
                                style="margin-top: 10px"
                            >
                                <input
                                    id="is_gate_guard"
                                    v-model="form.is_gate_guard"
                                    type="checkbox"
                                    class="callout__checkbox"
                                />
                                <label
                                    for="is_gate_guard"
                                    class="callout__label"
                                >
                                    <span class="callout__label-title"
                                        >This is a gate guard</span
                                    >
                                    <!-- <span class="callout__label-sub">
                                        Gate guards are paid a fixed share of
                                        the estate's guard fee, separate from
                                        responding security earnings.
                                    </span> -->
                                </label>
                            </div>
                        </div>

                        <div v-else class="callout callout--amber">
                            <span class="callout__dot"></span>
                            <span class="callout__inline-title"
                                >Household / Resident</span
                            >
                            <span class="callout__inline-hint"
                                >Invite link recommended for
                                self-registration</span
                            >
                        </div>

                        <div
                            style="
                                display: grid;
                                grid-template-columns: 1fr 1fr;
                                gap: 14px;
                            "
                        >
                            <div class="field">
                                <label class="field__label">Name</label>
                                <input
                                    id="name"
                                    v-model="form.name"
                                    class="field__input"
                                    :class="{
                                        'field__input--error': errors.name,
                                    }"
                                />
                                <span v-if="errors.name" class="field__error">{{
                                    errors.name[0]
                                }}</span>
                            </div>
                            <div class="field">
                                <label class="field__label">Email</label>
                                <input
                                    id="email"
                                    v-model="form.email"
                                    class="field__input"
                                    :class="{
                                        'field__input--error': errors.email,
                                    }"
                                />
                                <span
                                    v-if="errors.email"
                                    class="field__error"
                                    >{{ errors.email[0] }}</span
                                >
                            </div>
                        </div>

                        <div
                            style="
                                display: grid;
                                grid-template-columns: 1fr 1fr;
                                gap: 14px;
                            "
                        >
                            <div class="field">
                                <label class="field__label">Phone</label>
                                <VueTelInput
                                    v-model="form.phone"
                                    mode="international"
                                    :onlyCountries="['za']"
                                    defaultCountry="za"
                                    :autoFormat="true"
                                    :inputOptions="{
                                        showDialCode: true,
                                        placeholder: '+27 82 123 4567',
                                    }"
                                    @input="handlePhoneInput"
                                    class="custom-tel-input"
                                />
                                <span
                                    v-if="errors.phone"
                                    class="field__error"
                                    >{{ errors.phone[0] }}</span
                                >
                            </div>
                            <div class="field">
                                <label class="field__label">Client</label>
                                <div class="select-wrapper">
                                    <select
                                        id="clients"
                                        v-model="form.client_id"
                                        class="field__select"
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
                                    <svg
                                        class="select-caret"
                                        xmlns="http://www.w3.org/2000/svg"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        stroke-width="2"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M19 9l-7 7-7-7"
                                        />
                                    </svg>
                                </div>
                                <span
                                    v-if="errors.client_id"
                                    class="field__error"
                                    >{{ errors.client_id[0] }}</span
                                >
                            </div>
                        </div>

                        <div class="field">
                            <label class="field__label">
                                {{
                                    isHousehold
                                        ? 'Channel (one only)'
                                        : form.is_gate_guard
                                          ? 'Channel (one only - gate guard)'
                                          : 'Channels'
                                }}
                                <span
                                    v-if="isHousehold"
                                    class="field__hint"
                                    style="
                                        background: #fff7ed;
                                        color: #ea580c;
                                        padding: 2px 8px;
                                        border-radius: 20px;
                                        font-style: normal;
                                        font-weight: 700;
                                    "
                                    >Household - 1 max</span
                                >
                                <span
                                    v-else-if="form.is_gate_guard"
                                    class="field__hint"
                                    style="
                                        background: #eff6ff;
                                        color: #2563eb;
                                        padding: 2px 8px;
                                        border-radius: 20px;
                                        font-style: normal;
                                        font-weight: 700;
                                    "
                                    >Gate Guard - 1 max</span
                                >
                            </label>
                            <Multiselect
                                v-if="isHousehold || form.is_gate_guard"
                                :key="'single-' + form.client_id"
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
                                :key="'multi-' + form.client_id"
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
                            <span
                                v-if="errors.channel_ids"
                                class="field__error"
                                >{{ errors.channel_ids[0] }}</span
                            >
                        </div>

                        <div v-if="isHousehold" class="household-panel">
                            <div class="household-panel__heading">
                                Household Details
                            </div>

                            <div class="field" style="position: relative">
                                <label class="field__label"
                                    >Search Address</label
                                >
                                <input
                                    id="address_search"
                                    type="text"
                                    placeholder="Type your street address..."
                                    class="field__input"
                                    @input="handleAddressSearch"
                                    @blur="hideSuggestions"
                                />
                                <ul
                                    v-if="
                                        showSuggestions &&
                                        addressSuggestions.length
                                    "
                                    class="address-suggestions"
                                >
                                    <li
                                        v-for="item in addressSuggestions"
                                        :key="item.place_id"
                                        @click="selectAddress(item)"
                                        class="address-suggestions__item"
                                    >
                                        {{ item.display_name }}
                                    </li>
                                </ul>
                            </div>

                            <div
                                v-if="form.role === 'household'"
                                class="callout-checkbox"
                            >
                                <input
                                    id="in_complex"
                                    type="checkbox"
                                    v-model="inComplex"
                                    class="callout__checkbox"
                                    @change="
                                        () => {
                                            if (!inComplex)
                                                form.complex_name = '';
                                        }
                                    "
                                />
                                <label
                                    for="in_complex"
                                    style="
                                        font-size: 13px;
                                        color: #475569;
                                        cursor: pointer;
                                    "
                                    >This household is inside an estate or
                                    complex</label
                                >
                            </div>

                            <div
                                style="
                                    display: grid;
                                    grid-template-columns: 1fr 1fr;
                                    gap: 14px;
                                "
                            >
                                <div class="field">
                                    <label class="field__label">{{
                                        inComplex || form.role === 'resident'
                                            ? 'Unit Number'
                                            : 'House Number'
                                    }}</label>
                                    <input
                                        id="unit_number"
                                        v-model="form.unit_number"
                                        class="field__input"
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
                                    class="field"
                                >
                                    <label class="field__label"
                                        >Complex / Estate Name</label
                                    >
                                    <input
                                        id="complex"
                                        v-model="form.complex_name"
                                        class="field__input"
                                        placeholder="e.g. Green Valley Estate"
                                        :required="inComplex"
                                    />
                                </div>
                            </div>

                            <div
                                style="
                                    display: grid;
                                    grid-template-columns: 1fr 1fr;
                                    gap: 14px;
                                "
                            >
                                <div class="field">
                                    <label class="field__label"
                                        >Street Address</label
                                    >
                                    <input
                                        id="address_line_1"
                                        v-model="form.address_line_1"
                                        class="field__input"
                                        placeholder="e.g. 123 Maple Ave"
                                    />
                                    <span
                                        v-if="errors.address_line_1"
                                        class="field__error"
                                        >{{ errors.address_line_1[0] }}</span
                                    >
                                </div>
                                <div class="field">
                                    <label class="field__label"
                                        >Suburb / Area</label
                                    >
                                    <input
                                        id="suburb"
                                        v-model="form.suburb"
                                        class="field__input"
                                        placeholder="e.g. Morningside"
                                    />
                                    <span
                                        v-if="errors.suburb"
                                        class="field__error"
                                        >{{ errors.suburb[0] }}</span
                                    >
                                </div>
                            </div>

                            <div class="pin-panel">
                                <div class="pin-panel__header">
                                    <div>
                                        <div class="pin-panel__title">
                                            Security Codes
                                        </div>
                                        <div class="pin-panel__sub">
                                            Auto-generated. Send to household
                                            via their first login.
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        @click="regeneratePins"
                                        class="pin-panel__regen"
                                    >
                                        ↻ Regenerate
                                    </button>
                                </div>
                                <div
                                    style="
                                        display: grid;
                                        grid-template-columns: 1fr 1fr;
                                        gap: 12px;
                                    "
                                >
                                    <div class="field" style="gap: 4px">
                                        <label class="field__label">
                                            <span
                                                class="pin-dot pin-dot--green"
                                            ></span>
                                            Cancel Code
                                        </label>
                                        <input
                                            id="safe_cancel_pin"
                                            v-model="form.safe_cancel_pin"
                                            maxlength="6"
                                            class="field__input pin-input pin-input--green"
                                            placeholder="——————"
                                            readonly
                                        />
                                        <span
                                            class="field__hint"
                                            style="color: #94a3b8"
                                            >Genuine false alarm cancel</span
                                        >
                                    </div>
                                    <div class="field" style="gap: 4px">
                                        <label class="field__label">
                                            <span
                                                class="pin-dot pin-dot--red"
                                            ></span>
                                            Duress Code
                                        </label>
                                        <input
                                            id="duress_pin"
                                            v-model="form.duress_pin"
                                            maxlength="6"
                                            class="field__input pin-input pin-input--red"
                                            placeholder="——————"
                                            readonly
                                        />
                                        <span
                                            class="field__hint"
                                            style="color: #94a3b8"
                                            >Covert — keeps patrollers on
                                            route</span
                                        >
                                    </div>
                                </div>
                                <div
                                    class="callout callout--amber"
                                    style="margin-top: 12px"
                                >
                                    Never share the duress code label with the
                                    household — they should only know it as
                                    their "emergency code".
                                </div>

                                <div
                                    v-if="auth.user?.role === 'admin'"
                                    class="callout callout--info"
                                    style="margin-top: 12px"
                                >
                                    <input
                                        id="activation_fee_paid"
                                        v-model="form.activation_fee_paid"
                                        type="checkbox"
                                        class="callout__checkbox"
                                    />
                                    <label
                                        for="activation_fee_paid"
                                        class="callout__label"
                                    >
                                        <span class="callout__label-title"
                                            >R50 activation fee paid</span
                                        >
                                        <span class="callout__label-sub"
                                            >If unchecked, R50 will be added to
                                            first billing cycle (R130
                                            total)</span
                                        >
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="field">
                            <label class="field__label">Set New Password</label>
                            <input
                                id="password"
                                v-model="form.password"
                                type="password"
                                class="field__input"
                            />
                            <span v-if="errors.password" class="field__error">{{
                                errors.password[0]
                            }}</span>
                        </div>

                        <div class="modal-actions">
                            <button
                                type="button"
                                @click="closeModal"
                                class="btn-ghost"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                class="btn-primary"
                                :disabled="loading"
                            >
                                <svg
                                    v-if="loading"
                                    class="spin h-4 w-4"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <circle
                                        class="opacity-25"
                                        cx="12"
                                        cy="12"
                                        r="10"
                                        stroke="currentColor"
                                        stroke-width="4"
                                    />
                                    <path
                                        class="opacity-75"
                                        fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                                    />
                                </svg>
                                {{
                                    loading
                                        ? isEditing
                                            ? 'Updating…'
                                            : 'Adding…'
                                        : isEditing
                                          ? isHousehold
                                              ? 'Update Household'
                                              : 'Update Field Unit'
                                          : isHousehold
                                            ? 'Add Household'
                                            : 'Add Field Unit'
                                }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </transition>

        <!-- Delete confirm modal -->
        <transition name="modal">
            <div
                v-if="showDeleteModal"
                class="modal-backdrop"
                @click.self="showDeleteModal = false"
            >
                <div class="confirm-modal">
                    <div class="confirm-modal__icon">
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-7 w-7 text-red-500"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="1.5"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                            />
                        </svg>
                    </div>
                    <h2 class="confirm-modal__title">Confirm Deletion</h2>
                    <p class="confirm-modal__body">
                        Are you sure you want to delete this record? If this
                        user is a tenant linked to an estate, they will be
                        removed from that estate, their subscription will be
                        cancelled, and they'll lose access to the app
                        immediately. This action is permanent and cannot be
                        undone.
                    </p>
                    <div class="confirm-modal__actions">
                        <button
                            @click="showDeleteModal = false"
                            class="btn-ghost"
                        >
                            Keep it
                        </button>
                        <button @click="executeDelete" class="btn-danger">
                            Yes, Delete
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- Regenerate invite confirmation modal -->
        <transition name="modal">
            <div
                v-if="confirmRegenerateInvite"
                class="modal-backdrop"
                @click.self="confirmRegenerateInvite = null"
            >
                <div class="confirm-modal">
                    <div
                        class="confirm-modal__icon"
                        style="background: #fffbeb"
                    >
                        <span style="font-size: 22px">↻</span>
                    </div>
                    <h2 class="confirm-modal__title">
                        Regenerate Invite Link?
                    </h2>
                    <p class="confirm-modal__body" style="margin-bottom: 2px">
                        {{ confirmRegenerateInvite.channel_name }}
                    </p>
                    <div
                        class="toggle-warning toggle-warning--danger"
                        style="
                            text-align: left;
                            background: #fffbeb;
                            border-color: #fcd34d;
                            color: #92400e;
                        "
                    >
                        <p style="font-weight: 700; margin-bottom: 4px">
                            This will invalidate the current link.
                        </p>
                        <p>
                            Anyone who has not yet registered using the old link
                            will need the new link. Households who already
                            registered are not affected.
                        </p>
                    </div>
                    <div class="confirm-modal__actions">
                        <button
                            @click="confirmRegenerateInvite = null"
                            class="btn-ghost"
                        >
                            Cancel
                        </button>
                        <button
                            @click="proceedRegenerate"
                            :disabled="isRegenerating"
                            class="btn-primary"
                            style="flex: 1.4; justify-content: center"
                        >
                            <svg
                                v-if="isRegenerating"
                                class="spin h-4 w-4"
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                            >
                                <circle
                                    class="opacity-25"
                                    cx="12"
                                    cy="12"
                                    r="10"
                                    stroke="currentColor"
                                    stroke-width="4"
                                />
                                <path
                                    class="opacity-75"
                                    fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                                />
                            </svg>
                            {{
                                isRegenerating
                                    ? 'Regenerating…'
                                    : 'Yes, Regenerate'
                            }}
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- Toggle status modal -->
        <transition name="modal">
            <div
                v-if="confirmToggleEmployee"
                class="modal-backdrop"
                @click.self="confirmToggleEmployee = null"
            >
                <div class="confirm-modal">
                    <div
                        class="confirm-modal__icon"
                        :style="
                            confirmToggleEmployee.user.is_active
                                ? 'background:#fef2f2'
                                : 'background:#f0fdf4'
                        "
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-7 w-7"
                            :style="
                                confirmToggleEmployee.user.is_active
                                    ? 'color:#dc2626'
                                    : 'color:#16a34a'
                            "
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="1.5"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M12 9v2m0 4h.01M12 3a9 9 0 100 18A9 9 0 0012 3z"
                            />
                        </svg>
                    </div>
                    <h2 class="confirm-modal__title">
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
                                : 'Field Unit'
                        }}
                    </h2>
                    <p class="confirm-modal__body" style="margin-bottom: 4px">
                        {{ confirmToggleEmployee.user.name }}
                    </p>
                    <div
                        v-if="confirmToggleEmployee.user.is_active"
                        class="toggle-warning toggle-warning--danger"
                    >
                        <p style="font-weight: 700; margin-bottom: 4px">
                            Before you deactivate:
                        </p>
                        <ul>
                            <li>
                                They'll be logged out of Echo Link immediately
                            </li>
                            <li>
                                They won't be able to log back in until
                                reactivated
                            </li>
                            <li>
                                All active channel sessions will be terminated
                            </li>
                        </ul>
                    </div>
                    <div v-else class="toggle-warning toggle-warning--success">
                        They will regain access to the Echo Link app and can log
                        in with their existing credentials.
                    </div>
                    <div class="confirm-modal__actions">
                        <button
                            @click="confirmToggleEmployee = null"
                            class="btn-ghost"
                        >
                            Cancel
                        </button>
                        <button
                            @click="proceedToggle"
                            :class="
                                confirmToggleEmployee.user.is_active
                                    ? 'btn-danger'
                                    : 'btn-success'
                            "
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
        </transition>

        <!-- Subscription flash -->
        <transition name="toast">
            <div
                v-if="subFlash"
                class="toast"
                :style="
                    subFlash.type === 'success'
                        ? 'background:#16a34a;border-left-color:#15803d'
                        : 'background:#dc2626;border-left-color:#b91c1c'
                "
            >
                {{ subFlash.type === 'success' ? '✓' : '⚠' }}
                {{ subFlash.msg }}
            </div>
        </transition>

        <!-- Click-outside overlay to close dropdown -->
        <div
            v-if="subActionMenu !== null"
            class="fixed inset-0 z-40"
            @click="closeSubMenus"
        />

        <!-- EFT Payment Modal -->
        <transition name="modal">
            <div
                v-if="eftModal"
                class="modal-backdrop"
                @click.self="
                    eftModal = null;
                    eftProof = null;
                    eftProofName = '';
                "
            >
                <div class="modal-sheet" style="max-width: 480px">
                    <div class="modal-sheet__header">
                        <div class="modal-sheet__header-left">
                            <div>
                                <div class="modal-sheet__title">
                                    Mark EFT Paid
                                </div>
                                <div class="modal-sheet__sub">
                                    Recording payment for
                                    {{ eftModal.userName }}
                                </div>
                            </div>
                        </div>
                        <button
                            class="close-btn"
                            @click="
                                eftModal = null;
                                eftProof = null;
                                eftProofName = '';
                            "
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-4 w-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M6 18L18 6M6 6l12 12"
                                />
                            </svg>
                        </button>
                    </div>

                    <div class="modal-sheet__body">
                        <div class="field">
                            <label class="field__label">Amount (ZAR)</label>
                            <input
                                v-model="eftAmount"
                                type="number"
                                min="1"
                                required
                                placeholder="80"
                                class="field__input"
                            />
                        </div>

                        <div class="field">
                            <label class="field__label">Payment Note</label>
                            <input
                                v-model="eftNote"
                                type="text"
                                required
                                placeholder="e.g. EFT received 10 Apr 2026"
                                class="field__input"
                            />
                        </div>

                        <div class="field">
                            <label class="field__label">Proof of Payment</label>
                            <label class="upload-dropzone">
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-7 w-7"
                                    style="color: #94a3b8; margin-bottom: 6px"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="1.5"
                                        d="M4 16v1a2 2 0 002 2h12a2 2 0 002-2v-1M12 12V4m0 0L8 8m4-4l4 4"
                                    />
                                </svg>
                                <span
                                    v-if="eftProofName"
                                    style="
                                        font-size: 12px;
                                        font-weight: 700;
                                        color: #16a34a;
                                    "
                                    >{{ eftProofName }}</span
                                >
                                <span
                                    v-else
                                    style="font-size: 12px; color: #94a3b8"
                                    >Click to upload PDF, JPG or PNG (max
                                    5MB)</span
                                >
                                <input
                                    type="file"
                                    accept=".pdf,.jpg,.jpeg,.png"
                                    style="display: none"
                                    @change="
                                        (e: any) => {
                                            eftProof = e.target.files[0];
                                            eftProofName =
                                                e.target.files[0]?.name ?? '';
                                        }
                                    "
                                />
                            </label>
                        </div>

                        <div class="modal-actions">
                            <button
                                @click="
                                    eftModal = null;
                                    eftProof = null;
                                    eftProofName = '';
                                "
                                class="btn-ghost"
                            >
                                Cancel
                            </button>
                            <button
                                @click="submitEftPayment"
                                :disabled="
                                    !eftAmount ||
                                    !eftNote ||
                                    !eftProof ||
                                    subLoading === eftModal?.id
                                "
                                class="btn-primary"
                            >
                                {{
                                    subLoading === eftModal?.id
                                        ? 'Processing…'
                                        : 'Confirm Payment'
                                }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </transition>

        <!-- Confirm Subscription Action Modal -->
        <transition name="modal">
            <div
                v-if="confirmSubAction"
                class="modal-backdrop"
                @click.self="confirmSubAction = null"
            >
                <div class="confirm-modal">
                    <div
                        class="confirm-modal__icon"
                        :style="
                            confirmSubAction.action === 'unsuspend'
                                ? 'background:#f0fdf4'
                                : 'background:#fef2f2'
                        "
                    >
                        <span style="font-size: 20px">
                            {{
                                confirmSubAction.action === 'suspend'
                                    ? '⏸'
                                    : confirmSubAction.action === 'unsuspend'
                                      ? '▶'
                                      : '✕'
                            }}
                        </span>
                    </div>
                    <h2 class="confirm-modal__title">
                        {{ confirmSubAction.label }}
                    </h2>
                    <p class="confirm-modal__body" style="margin-bottom: 2px">
                        Subscription #{{ confirmSubAction.sub.id }}
                    </p>
                    <div
                        class="toggle-warning"
                        :class="
                            confirmSubAction.action === 'cancel'
                                ? 'toggle-warning--danger'
                                : confirmSubAction.action === 'suspend'
                                  ? 'toggle-warning--danger'
                                  : 'toggle-warning--success'
                        "
                        :style="
                            confirmSubAction.action === 'suspend'
                                ? 'background:#fffbeb;border-color:#fcd34d;color:#92400e'
                                : ''
                        "
                    >
                        <template v-if="confirmSubAction.action === 'suspend'">
                            The household's SOS panic button will be disabled
                            and a warning banner will appear on their device.
                            Their subscription remains active.
                        </template>
                        <template
                            v-else-if="confirmSubAction.action === 'unsuspend'"
                        >
                            SOS will be reinstated immediately and the warning
                            banner will disappear from their device.
                        </template>
                        <template v-else>
                            The subscription will be cancelled. The household
                            retains access until the end of the current billing
                            period, after which SOS will be disabled.
                        </template>
                    </div>
                    <div class="confirm-modal__actions">
                        <button
                            @click="confirmSubAction = null"
                            class="btn-ghost"
                        >
                            Cancel
                        </button>
                        <button
                            @click="proceedSubAction"
                            :disabled="subLoading !== null"
                            :class="
                                confirmSubAction.action === 'unsuspend'
                                    ? 'btn-success'
                                    : 'btn-danger'
                            "
                        >
                            {{
                                subLoading !== null ? 'Processing…' : 'Confirm'
                            }}
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- No Coverage Deactivation Modal -->
        <transition name="modal">
            <div
                v-if="confirmNoCoverage"
                class="modal-backdrop"
                @click.self="confirmNoCoverage = null"
            >
                <div class="confirm-modal">
                    <div class="confirm-modal__icon">
                        <span style="font-size: 20px">🏚</span>
                    </div>
                    <h2 class="confirm-modal__title">
                        No Coverage — Deactivate Household
                    </h2>
                    <p class="confirm-modal__body" style="margin-bottom: 2px">
                        {{ confirmNoCoverage.user.name }}
                    </p>
                    <div class="toggle-warning toggle-warning--danger">
                        This household has moved to an area with no Echo Link
                        coverage. Taking this action will:
                        <ul>
                            <li>
                                Opt them out of estate billing (if applicable)
                            </li>
                            <li>Cancel their subscription immediately</li>
                            <li>Remove their channel assignment</li>
                            <li>Deactivate their account</li>
                            <li>Send them an email explaining why</li>
                        </ul>
                        <p style="font-weight: 700; margin-top: 6px">
                            This cannot be automatically undone.
                        </p>
                    </div>
                    <div class="confirm-modal__actions">
                        <button
                            @click="confirmNoCoverage = null"
                            class="btn-ghost"
                        >
                            Cancel
                        </button>
                        <button
                            @click="proceedNoCoverage"
                            :disabled="subLoading !== null"
                            class="btn-danger"
                        >
                            {{
                                subLoading !== null
                                    ? 'Processing…'
                                    : 'Deactivate'
                            }}
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- Payment History Modal -->
        <transition name="modal">
            <div
                v-if="showPayHistory"
                class="modal-backdrop"
                @click.self="showPayHistory = false"
            >
                <div class="ca-modal">
                    <div class="ca-modal__header">
                        <div class="ca-modal__header-left">
                            <div>
                                <div class="ca-modal__title">
                                    Payment History
                                </div>
                                <div class="ca-modal__sub">
                                    {{ payHistorySub?.userName }}
                                </div>
                            </div>
                        </div>
                        <button
                            class="close-btn"
                            @click="showPayHistory = false"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-4 w-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M6 18L18 6M6 6l12 12"
                                />
                            </svg>
                        </button>
                    </div>

                    <div class="ca-modal__body">
                        <div v-if="payHistoryLoading" class="empty-state">
                            <svg
                                class="spin h-6 w-6 text-slate-400"
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                            >
                                <circle
                                    class="opacity-25"
                                    cx="12"
                                    cy="12"
                                    r="10"
                                    stroke="currentColor"
                                    stroke-width="4"
                                />
                                <path
                                    class="opacity-75"
                                    fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                                />
                            </svg>
                        </div>

                        <div
                            v-else-if="payHistoryData.length === 0"
                            class="empty-state"
                        >
                            <p class="empty-state__title">
                                No payments recorded yet
                            </p>
                        </div>

                        <table v-else class="data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Gateway</th>
                                    <th>Reference</th>
                                    <th style="text-align: right">Amount</th>
                                    <th style="text-align: center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in payHistoryData" :key="p.id">
                                    <td class="td-muted">
                                        {{
                                            new Date(
                                                p.created_at,
                                            ).toLocaleDateString('en-ZA', {
                                                day: 'numeric',
                                                month: 'short',
                                                year: 'numeric',
                                            })
                                        }}
                                    </td>
                                    <td>
                                        <span
                                            class="type-badge bg-slate-100 text-slate-600"
                                            style="text-transform: capitalize"
                                        >
                                            {{ p.gateway ?? 'unknown' }}
                                        </span>
                                    </td>
                                    <td class="token-text">
                                        {{ p.merchant_reference ?? '—' }}
                                    </td>
                                    <td
                                        class="td-announce__title"
                                        style="text-align: right"
                                    >
                                        R{{ (p.amount / 100).toFixed(2) }}
                                    </td>
                                    <td style="text-align: center">
                                        <span
                                            class="type-badge"
                                            :class="
                                                p.status === 'complete'
                                                    ? 'bg-emerald-50 text-emerald-700'
                                                    : p.status === 'failed'
                                                      ? 'bg-red-50 text-red-600'
                                                      : 'bg-slate-100 text-slate-500'
                                            "
                                            >{{ p.status }}</span
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </transition>

        <!-- Conduct Block Modal -->
        <transition name="modal">
            <div
                v-if="conductBlockModal"
                class="modal-backdrop"
                @click.self="conductBlockModal = null"
            >
                <div class="modal-sheet" style="max-width: 460px">
                    <div class="modal-sheet__header">
                        <div class="modal-sheet__header-left">
                            <div>
                                <div class="modal-sheet__title">
                                    Conduct Block
                                </div>
                                <div class="modal-sheet__sub">
                                    {{ conductBlockModal.userName }}
                                </div>
                            </div>
                        </div>
                        <button
                            class="close-btn"
                            @click="conductBlockModal = null"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-4 w-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M6 18L18 6M6 6l12 12"
                                />
                            </svg>
                        </button>
                    </div>
                    <div class="modal-sheet__body">
                        <div class="toggle-warning toggle-warning--danger">
                            This will immediately disable the household's SOS
                            panic button. They will see a block notice on their
                            device. Document the reason clearly.
                        </div>
                        <div class="field">
                            <label class="field__label">Reason for block</label>
                            <textarea
                                v-model="conductBlockReason"
                                rows="3"
                                class="field__input field__textarea"
                                placeholder="e.g. Repeated false panic alerts reported by 3 patrollers on 10 Apr 2026..."
                            ></textarea>
                        </div>
                        <div class="modal-actions">
                            <button
                                @click="conductBlockModal = null"
                                class="btn-ghost"
                            >
                                Cancel
                            </button>
                            <button
                                @click="submitConductBlock"
                                :disabled="
                                    !conductBlockReason.trim() ||
                                    subLoading !== null
                                "
                                class="btn-danger"
                            >
                                {{
                                    subLoading !== null
                                        ? 'Blocking…'
                                        : 'Block Household'
                                }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </transition>

        <!-- Generic flash toast (personnel messages) -->
        <transition name="toast">
            <div v-if="flashMessage" class="toast">
                {{ flashMessage }}
            </div>
        </transition>
    </AppLayout>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap');

.page-root,
.modal-backdrop,
.toast {
    --c-bg: #f4f6f9;
    --c-surface: #ffffff;
    --c-border: #e4e8ef;
    --c-text: #1a2332;
    --c-muted: #64748b;
    --c-faint: #94a3b8;
    --c-primary: #ea580c;
    --c-primary-h: #c2410c;
    --c-danger: #dc2626;
    --c-danger-h: #b91c1c;
    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 16px;
    --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
    --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.08);
    --shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.14);
    font-family: 'DM Sans', system-ui, sans-serif;
}

/* PAGE ROOT */
.page-root {
    padding: 28px 32px;
    display: flex;
    flex-direction: column;
    gap: 20px;
    min-height: 100%;
    background: #f4f6f9;
}

/* PAGE HEADER */
.page-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 16px;
}
.page-header__eyebrow {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: #ea580c;
    margin-bottom: 4px;
}
.page-header__title {
    font-size: 22px;
    font-weight: 700;
    color: #1a2332;
    margin: 0;
    letter-spacing: -0.3px;
}
.page-header__right {
    display: flex;
    align-items: center;
    gap: 10px;
}

/* FILTER BAR */
.filter-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.filter-bar__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 20px;
    border: 1px solid #e4e8ef;
    background: #ffffff;
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
    transition: all 0.15s;
}
.chip:hover {
    border-color: #cbd5e1;
    background: #f8fafc;
}
.chip--active {
    background: #fff7ed;
    color: #ea580c;
    border-color: #ea580c;
}
.chip__count {
    background: rgba(0, 0, 0, 0.08);
    border-radius: 20px;
    padding: 1px 7px;
    font-size: 11px;
    font-weight: 700;
}
.chip--active .chip__count {
    background: #ea580c;
    color: #fff;
}

.search-wrap {
    position: relative;
    width: 280px;
    max-width: 100%;
}
.search-wrap__icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    width: 15px;
    height: 15px;
    color: #94a3b8;
}
.search-wrap__input {
    width: 100%;
    box-sizing: border-box;
    background: #ffffff;
    border: 1.5px solid #e4e8ef;
    border-radius: 10px;
    padding: 8px 30px 8px 34px;
    font-size: 13px;
    font-family: inherit;
    color: #1a2332;
    outline: none;
    transition: border-color 0.15s;
}
.search-wrap__input:focus {
    border-color: #ea580c;
}
.search-wrap__input::placeholder {
    color: #94a3b8;
}
.search-wrap__clear {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 16px;
    color: #94a3b8;
    cursor: pointer;
}
.search-wrap__clear:hover {
    color: #64748b;
}

/* TABLE CARD */
.table-card {
    background: #ffffff;
    border: 1px solid #e4e8ef;
    border-radius: 16px;
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 64px 24px;
    gap: 8px;
}
.empty-state__icon {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    background: #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #94a3b8;
    margin-bottom: 6px;
}
.empty-state__title {
    font-size: 15px;
    font-weight: 700;
    color: #1a2332;
}
.empty-state__sub {
    font-size: 13px;
    color: #64748b;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.data-table thead tr {
    background: #f8fafc;
    border-bottom: 1px solid #e4e8ef;
}
.data-table th {
    padding: 11px 16px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #94a3b8;
    text-align: left;
    white-space: nowrap;
}
.data-table tbody tr {
    border-bottom: 1px solid #e4e8ef;
    transition: background 0.12s;
}
.data-table tbody tr:last-child {
    border-bottom: none;
}
.data-table tbody tr:hover {
    background: #fafbfc;
}
.data-table td {
    padding: 13px 16px;
    vertical-align: middle;
    /* Keep row content on a single line by default so columns auto-size
       to their content instead of wrapping. Cells that intentionally need
       to wrap (e.g. multi-line address / channel pill groups) override
       this locally with their own inline styles. */
    white-space: nowrap;
}

.td-announce__title {
    font-weight: 600;
    color: #1a2332;
}
.td-announce__sub {
    font-size: 12px;
    color: #94a3b8;
}
.td-muted {
    color: #64748b;
    font-size: 13px;
}

.type-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 700;
    white-space: nowrap;
}
.status-toggle-btn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
}
.token-text {
    font-family: ui-monospace, monospace;
    font-size: 13px;
    color: #94a3b8;
}

.channel-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    border-radius: 20px;
    padding: 2px 9px;
    font-size: 11px;
    font-weight: 600;
    border: 1px solid #e4e8ef;
    background: #f8fafc;
    color: #64748b;
}
.channel-pill--online {
    border-color: #86efac;
    background: #f0fdf4;
    color: #16a34a;
}
.channel-pill__dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #94a3b8;
}
.channel-pill--online .channel-pill__dot {
    background: #22c55e;
}

.icon-btn {
    padding: 7px;
    border-radius: 8px;
    border: none;
    background: transparent;
    cursor: pointer;
    transition: all 0.15s;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #94a3b8;
}
.icon-btn--edit:hover {
    background: #eff6ff;
    color: #2563eb;
}
.icon-btn--danger:hover {
    background: #fef2f2;
    color: #dc2626;
}

/* PAGINATION */
.pagination-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-top: 1px solid #e4e8ef;
}
.pagination-bar__info {
    font-size: 12px;
    color: #94a3b8;
}
.pagination-bar__pages {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}
.page-btn {
    min-width: 34px;
    height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 8px;
    border: 1px solid #e4e8ef;
    border-radius: 8px;
    background: #ffffff;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
    transition: all 0.15s;
}
.page-btn:hover:not(.page-btn--disabled) {
    border-color: #ea580c;
    color: #ea580c;
}
.page-btn--active {
    background: #ea580c;
    border-color: #ea580c;
    color: #fff;
}
.page-btn--disabled {
    background: #f8fafc;
    color: #94a3b8;
    cursor: default;
}

/* INVITE CARD */
.invite-card {
    background: #fff7ed;
    border: 1px solid #fed7aa;
    border-radius: 16px;
    padding: 20px;
}
.invite-card__header {
    margin-bottom: 14px;
}
.invite-card__title {
    font-size: 14px;
    font-weight: 700;
    color: #1a2332;
}
.invite-card__sub {
    font-size: 12px;
    color: #64748b;
    margin-top: 2px;
    max-width: 560px;
}
.invite-flash {
    margin-bottom: 14px;
    padding: 8px 12px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 600;
}
.invite-flash--success {
    background: #f0fdf4;
    color: #15803d;
}
.invite-flash--error {
    background: #fef2f2;
    color: #b91c1c;
}
.invite-loading {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 16px 0;
    font-size: 13px;
    color: #94a3b8;
}
.invite-table-wrap {
    border: 1px solid #fed7aa;
    border-radius: 12px;
    overflow: hidden;
    background: #ffffff;
    margin-bottom: 14px;
}
.invite-empty {
    border: 1px dashed #fdba8c;
    border-radius: 12px;
    background: #ffffff;
    padding: 22px;
    text-align: center;
    margin-bottom: 14px;
}
.invite-empty__title {
    font-size: 13px;
    font-weight: 700;
    color: #475569;
    margin-top: 4px;
}
.invite-empty__sub {
    font-size: 13px;
    color: #94a3b8;
    margin-top: 2px;
}
.invite-action-btn {
    border: none;
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    background: #ea580c;
    color: #fff;
    transition: all 0.15s;
}
.invite-action-btn:hover {
    background: #c2410c;
}
.invite-action-btn--copied {
    background: #16a34a;
}

/* FIELDS */
.field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 14px;
}
.field__label {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
    font-weight: 700;
    color: #64748b;
    letter-spacing: 0.3px;
    gap: 8px;
}
.field__hint {
    font-weight: 500;
    color: #94a3b8;
    font-style: italic;
}
.field__error {
    font-size: 11px;
    color: #dc2626;
    font-weight: 600;
    margin-top: 2px;
}
.field__input {
    width: 100%;
    box-sizing: border-box;
    background: #f8fafc;
    border: 1.5px solid #e4e8ef;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 14px;
    font-family: inherit;
    color: #1a2332;
    outline: none;
    transition:
        border-color 0.15s,
        background 0.15s;
}
.field__input:focus {
    border-color: #ea580c;
    background: #fff;
}
.field__input--error {
    border-color: #fca5a5;
    background: #fff;
}
.field__textarea {
    resize: vertical;
    min-height: 72px;
    line-height: 1.6;
}

.select-wrapper {
    position: relative;
}
.field__select {
    width: 100%;
    box-sizing: border-box;
    background: #f8fafc;
    border: 1.5px solid #e4e8ef;
    border-radius: 8px;
    padding: 10px 38px 10px 14px;
    font-size: 14px;
    font-family: inherit;
    color: #1a2332;
    outline: none;
    appearance: none;
    cursor: pointer;
    transition: border-color 0.15s;
}
.field__select:focus {
    border-color: #ea580c;
    background: #fff;
}
.select-caret {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    width: 16px;
    height: 16px;
    color: #94a3b8;
    pointer-events: none;
}

/* ADDRESS SUGGESTIONS */
.address-suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 50;
    margin-top: 4px;
    max-height: 220px;
    overflow-y: auto;
    background: #ffffff;
    border: 1px solid #e4e8ef;
    border-radius: 10px;
    box-shadow: var(--shadow-md);
    list-style: none;
    padding: 0;
}
.address-suggestions__item {
    padding: 10px 14px;
    font-size: 13px;
    color: #1a2332;
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
}
.address-suggestions__item:last-child {
    border-bottom: none;
}
.address-suggestions__item:hover {
    background: #fff7ed;
}

/* CALLOUTS */
.callout {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    border-radius: 10px;
    padding: 10px 14px;
    font-size: 12.5px;
    line-height: 1.6;
    margin-bottom: 14px;
}
.callout--info {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1e40af;
}
.callout--amber {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
    align-items: center;
}
.callout__dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #fbbf24;
    flex-shrink: 0;
}
.callout__inline-title {
    font-weight: 700;
}
.callout__inline-hint {
    margin-left: auto;
    font-size: 11px;
}
.callout__checkbox {
    margin-top: 2px;
    width: 15px;
    height: 15px;
    flex-shrink: 0;
}
.callout__label {
    cursor: pointer;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.callout__label-title {
    font-weight: 700;
}
.callout__label-sub {
    font-size: 11px;
    opacity: 0.85;
}
.callout-checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 14px;
}

/* HOUSEHOLD PANEL */
.household-panel {
    margin-top: 4px;
    border: 1px solid #e4e8ef;
    background: #f8fafc;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 14px;
}
.household-panel__heading {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: #64748b;
    margin-bottom: 12px;
}

/* PIN PANEL */
.pin-panel {
    border: 1px solid #fecaca;
    background: #ffffff;
    border-radius: 10px;
    padding: 14px;
    margin-top: 14px;
}
.pin-panel__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 12px;
}
.pin-panel__title {
    font-size: 13px;
    font-weight: 700;
    color: #1a2332;
}
.pin-panel__sub {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 2px;
}
.pin-panel__regen {
    border: 1px solid #e4e8ef;
    background: #f8fafc;
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    cursor: pointer;
}
.pin-panel__regen:hover {
    background: #f1f5f9;
}
.pin-dot {
    display: inline-block;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    margin-right: 4px;
}
.pin-dot--green {
    background: #22c55e;
}
.pin-dot--red {
    background: #ef4444;
}
.pin-input {
    font-family: ui-monospace, monospace;
    font-size: 18px;
    font-weight: 700;
    letter-spacing: 4px;
    text-align: center;
}
.pin-input--green {
    background: #f0fdf4 !important;
}
.pin-input--red {
    background: #fef2f2 !important;
    border-color: #fecaca !important;
}

/* UPLOAD DROPZONE */
.upload-dropzone {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    border: 2px dashed #e4e8ef;
    background: #f8fafc;
    border-radius: 10px;
    padding: 20px;
    cursor: pointer;
    transition: all 0.15s;
}
.upload-dropzone:hover {
    border-color: #cbd5e1;
    background: #f1f5f9;
}

/* SUBSCRIPTION DROPDOWN */
.sub-menu {
    position: fixed;
    z-index: 100;
    width: 220px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 4px;
    box-shadow:
        0 10px 32px rgba(15, 23, 42, 0.12),
        0 2px 8px rgba(15, 23, 42, 0.06);
}
.sub-menu__item {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    text-align: left;
    padding: 8px 10px;
    background: none;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-family: inherit;
    font-size: 13px;
    font-weight: 500;
    color: #1a2332;
    white-space: nowrap;
    transition: background 0.12s;
}
.sub-menu__item:hover {
    background: #f8fafc;
}
.sub-menu__item-icon {
    flex-shrink: 0;
    width: 16px;
    height: 16px;
    color: #64748b;
}
.sub-menu__item--danger {
    color: #b91c1c;
}
.sub-menu__item--danger .sub-menu__item-icon {
    color: #b91c1c;
}
.sub-menu__item--danger:hover {
    background: #fef2f2;
}
.sub-menu__divider {
    border-top: 1px solid #f1f5f9;
    margin: 4px 6px;
}

/* BUTTONS */
.btn-primary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    background: #ea580c !important;
    color: #ffffff !important;
    border: none;
    border-radius: 12px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.18s;
    box-shadow: 0 2px 8px rgba(234, 88, 12, 0.3);
    white-space: nowrap;
    font-family: 'DM Sans', system-ui, sans-serif;
}
.btn-primary:hover:not(:disabled) {
    background: #c2410c !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(234, 88, 12, 0.35);
}
.btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

.btn-ghost {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    background: #f1f5f9;
    color: #64748b;
    border: none;
    border-radius: 12px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
}
.btn-ghost:hover {
    background: #e2e8f0;
}

.btn-danger {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    background: #dc2626;
    color: #fff;
    border: none;
    border-radius: 12px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s;
    box-shadow: 0 2px 8px rgba(220, 38, 38, 0.2);
}
.btn-danger:hover:not(:disabled) {
    background: #b91c1c;
    transform: translateY(-1px);
}
.btn-danger:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

.btn-success {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    background: #16a34a;
    color: #fff;
    border: none;
    border-radius: 12px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s;
    box-shadow: 0 2px 8px rgba(22, 163, 74, 0.2);
}
.btn-success:hover:not(:disabled) {
    background: #15803d;
    transform: translateY(-1px);
}
.btn-success:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* MODAL */
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(10, 18, 30, 0.55) !important;
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    z-index: 9999;
    padding: 32px 24px;
    overflow-y: auto;
}
.modal-sheet {
    background: #ffffff !important;
    border-radius: 20px;
    width: 100%;
    max-width: 580px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.18);
    border: 1px solid #e4e8ef;
}
.modal-sheet__header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 22px 24px;
    border-bottom: 1px solid #e4e8ef;
    position: sticky;
    top: 0;
    background: #ffffff !important;
    z-index: 2;
}
.modal-sheet__header-left {
    display: flex;
    align-items: center;
    gap: 14px;
    flex: 1;
    min-width: 0;
}
.modal-sheet__title {
    font-size: 15px;
    font-weight: 700;
    color: #1a2332;
}
.modal-sheet__sub {
    font-size: 13px;
    color: #94a3b8;
}
.close-btn {
    flex-shrink: 0;
    width: 34px;
    height: 34px;
    background: #f1f5f9;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s;
}
.close-btn:hover {
    background: #e2e8f0;
}
.modal-sheet__body {
    padding: 24px;
    display: flex;
    flex-direction: column;
}

/* CA-MODAL (payment history reuse) */
.ca-modal {
    background: #ffffff;
    border-radius: 20px;
    width: 100%;
    max-width: 640px;
    max-height: 82vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.18);
    border: 1px solid #e4e8ef;
    overflow: hidden;
}
.ca-modal__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 20px 24px;
    border-bottom: 1px solid #e4e8ef;
    flex-shrink: 0;
}
.ca-modal__title {
    font-size: 15px;
    font-weight: 700;
    color: #1a2332;
}
.ca-modal__sub {
    font-size: 12px;
    color: #94a3b8;
    margin-top: 1px;
}
.ca-modal__body {
    flex: 1;
    overflow-y: auto;
}

/* MODAL ACTIONS */
.modal-actions {
    display: flex;
    gap: 10px;
    padding-top: 4px;
}
.modal-actions .btn-ghost {
    flex: 1;
}
.modal-actions .btn-primary {
    flex: 2;
}

/* CONFIRM MODAL */
.confirm-modal {
    background: #ffffff !important;
    border-radius: 20px;
    width: 100%;
    max-width: 420px;
    padding: 32px 28px 26px;
    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.18);
    border: 1px solid #e4e8ef;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 10px;
}
.confirm-modal__icon {
    width: 60px;
    height: 60px;
    background: #fef2f2;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 6px;
}
.confirm-modal__title {
    font-size: 17px;
    font-weight: 800;
    color: #1a2332;
    margin: 0;
}
.confirm-modal__body {
    font-size: 13px;
    color: #64748b;
    line-height: 1.6;
    margin-bottom: 8px;
}
.confirm-modal__actions {
    display: flex;
    gap: 10px;
    width: 100%;
    margin-top: 4px;
}
.confirm-modal__actions .btn-ghost {
    flex: 1;
}
.confirm-modal__actions .btn-danger,
.confirm-modal__actions .btn-success,
.confirm-modal__actions .btn-primary {
    flex: 1.4;
}

/* TOGGLE WARNING BLOCKS */
.toggle-warning {
    width: 100%;
    text-align: left;
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 14.5px;
    line-height: 1.6;
    margin-bottom: 4px;
}
.toggle-warning--danger {
    background: #fef2f2;
    border: 1px solid #fca5a5;
    color: #b91c1c;
}
.toggle-warning--danger ul {
    margin: 6px 0 0;
    padding-left: 18px;
    list-style: disc;
}
.toggle-warning--success {
    background: #f0fdf4;
    border: 1px solid #86efac;
    color: #15803d;
}

/* TOAST */
.toast {
    position: fixed;
    bottom: 28px;
    right: 28px;
    background: #1a2332;
    color: #f1f5f9;
    padding: 12px 18px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 600;
    z-index: 99999;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
    display: flex;
    align-items: center;
    gap: 8px;
    border-left: 3px solid #ea580c;
}

/* TRANSITIONS */
.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.22s ease;
}
.modal-enter-active .modal-sheet,
.modal-leave-active .modal-sheet,
.modal-enter-active .confirm-modal,
.modal-leave-active .confirm-modal,
.modal-enter-active .ca-modal,
.modal-leave-active .ca-modal {
    transition:
        transform 0.22s ease,
        opacity 0.22s ease;
}
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
.modal-enter-from .modal-sheet,
.modal-leave-to .modal-sheet,
.modal-enter-from .confirm-modal,
.modal-leave-to .confirm-modal,
.modal-enter-from .ca-modal,
.modal-leave-to .ca-modal {
    transform: scale(0.97) translateY(12px);
}

.toast-enter-active,
.toast-leave-active {
    transition: all 0.25s ease;
}
.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateY(8px);
}

.spin {
    animation: spin 0.65s linear infinite;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

/* VUE-TEL-INPUT OVERRIDE (kept, restyled to match field tokens) */
:deep(.custom-tel-input),
:deep(.vue-tel-input) {
    display: flex !important;
    height: 42px !important;
    border-radius: 8px;
    border: 1.5px solid #e4e8ef !important;
    background-color: #f8fafc;
}
:deep(.vti__input) {
    background: transparent !important;
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
    font-size: 14px;
    font-family: inherit;
}
:deep(.vti__dropdown) {
    border-radius: 8px 0 0 8px;
}

/* RESPONSIVE */
@media (max-width: 768px) {
    .filter-bar {
        flex-direction: column;
        align-items: stretch;
    }
    .search-wrap {
        width: 100%;
    }
}
@media (max-width: 640px) {
    .page-root {
        padding: 16px;
    }
    /* Field Units table gained a "Joined" column; Households table gained
       "Joined" and "Last Payment" columns — both need more horizontal room
       before the table scrolls instead of wrapping. */
    .data-table {
        min-width: 1200px;
    }
    .table-card,
    .invite-table-wrap {
        overflow-x: auto;
    }
}
</style>
<style src="vue-multiselect/dist/vue-multiselect.min.css"></style>

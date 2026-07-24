<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';

const auth = useAuthStore();

onMounted(() => {
    if (auth.user?.role !== 'admin') {
        router.visit('/dashboard'); // redirect non-admins away
    }
});

// --- State ---
const showModal = ref(false);
const isEditing = ref(false);
const clients = ref<any[]>([]);

const isConfirmingDeletion = ref(false);
const channelToDelete = ref<any | null>(null);
const confirmationName = ref('');

// --- Feedback & Errors ---
const flashMessage = ref<string | null>(null);
const isProcessing = ref(false);
const errors = ref<{ [key: string]: string[] }>({});
const confirmToggleChannel = ref<any>(null);

const defaultForm = {
    id: null,
    client_id: '',
    name: '',
    category: '',
    type: '',
    billing_model: '',
    billing_contact_name: '',
    billing_contact_email: '',
    billing_contact_phone: '',
    amount_per_household: 80,
    amount_per_linked_account: 30,
    channel_type: '',
    guard_fixed_amount: 0,
    security_pool: null,
    security_percentage: null,
};

const form = ref({ ...defaultForm });

function resetForm() {
    form.value = { ...defaultForm };
}

// --- Helpers ---
function showMessage(message: string) {
    flashMessage.value = message;
    setTimeout(() => (flashMessage.value = null), 3500);
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

const channelsLoading = ref(true);

const reloadChannels = async (url?: string) => {
    channelsLoading.value = true;
    try {
        const endpoint = url || `${import.meta.env.VITE_APP_URL}/api/channels`;

        const { data } = await axios.get(endpoint, {
            headers: {
                Authorization: `Bearer ${localStorage.getItem('token')}`,
            },
        });

        channels.value = data.channels; // ✅ full paginator object
    } catch (e) {
        console.error('Error fetching channels', e);
    } finally {
        channelsLoading.value = false;
    }
};

onMounted(() => {
    reloadClients();
    reloadChannels();
});

// --- Stats (current page) ---
const activeCount = computed(
    () => channels.value.data.filter((c: any) => c.is_active).length,
);
const inactiveCount = computed(
    () => channels.value.data.filter((c: any) => !c.is_active).length,
);
const residentialCount = computed(
    () =>
        channels.value.data.filter((c: any) => c.channel_type === 'residential')
            .length,
);

// --- Modal Logic ---
const openModal = () => {
    isEditing.value = false;
    resetForm();
    errors.value = {};
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

function toggleChannelStatus(channel: any) {
    confirmToggleChannel.value = channel;
}

async function proceedChannelToggle() {
    if (!confirmToggleChannel.value) return;
    try {
        isProcessing.value = true;
        await axios.patch(
            `${import.meta.env.VITE_APP_URL}/api/channels/${confirmToggleChannel.value.id}/toggle-status`,
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
        confirmToggleChannel.value = null;
    }
}

// --- Editing ---
const editChannel = (channel: any) => {
    isEditing.value = true;
    errors.value = {};
    form.value = {
        id: channel.id,
        name: channel.name,
        category: channel.category,
        client_id: channel.client_id || channel.client?.id,
        billing_model: channel.billing_model,
        billing_contact_name: channel.billing_contact?.user?.name ?? '',
        billing_contact_email: channel.billing_contact?.user?.email ?? '',
        billing_contact_phone: channel.billing_contact?.user?.phone ?? '',
        amount_per_household: channel.amount_per_household ?? 80,
        amount_per_linked_account: channel.amount_per_linked_account ?? 30,
        channel_type: channel.channel_type,
        type: channel.type,
        guard_fixed_amount: channel.guard_fixed_amount ?? 0,
        security_pool: channel.security_pool,
        security_percentage: channel.security_percentage,
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
    isConfirmingDeletion.value = true;
};

const securityPoolPreview = computed(() =>
    Number(
        form.value.security_pool ??
            Number(form.value.amount_per_household) -
                Number(form.value.guard_fixed_amount || 0),
    ),
);

const securityCutPreview = computed(
    () =>
        securityPoolPreview.value *
        (Number(form.value.security_percentage || 0) / 100),
);

const echoLinkKeepsPreview = computed(
    () =>
        Number(form.value.amount_per_household) -
        Number(form.value.guard_fixed_amount || 0) -
        securityCutPreview.value,
);
</script>

<template>
    <Head title="Channels" />

    <AppLayout>
        <div class="page-root">
            <!-- PAGE HEADER -->
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Operations</div>
                    <h1 class="page-header__title">Channels</h1>
                </div>
                <div class="page-header__right">
                    <button class="btn-primary" @click="openModal">
                        Add Channel
                    </button>
                </div>
            </div>

            <!-- STAT CARDS -->
            <div class="stat-row">
                <div class="stat-card">
                    <div class="stat-card__label">Total Channels</div>
                    <div class="stat-card__value">
                        {{ channels.total || 0 }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Active</div>
                    <div class="stat-card__value stat-card__value--green">
                        {{ activeCount }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Deactivated</div>
                    <div class="stat-card__value stat-card__value--red">
                        {{ inactiveCount }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Residential</div>
                    <div class="stat-card__value stat-card__value--orange">
                        {{ residentialCount }}
                    </div>
                </div>
            </div>

            <!-- TABLE CARD -->
            <div class="table-card">
                <!-- Loading -->
                <div v-if="channelsLoading" class="empty-state">
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
                    <span class="mt-2 text-sm text-slate-400"
                        >Loading channels…</span
                    >
                </div>

                <!-- Empty -->
                <div v-else-if="channels.data.length === 0" class="empty-state">
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
                                d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                            />
                        </svg>
                    </div>
                    <p class="empty-state__title">No channels yet</p>
                    <p class="empty-state__sub">
                        Hit "Add Channel" to create your first channel
                    </p>
                </div>

                <!-- Table -->
                <table v-else class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Associated Client</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Type</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="channel in channels.data" :key="channel.id">
                            <td class="td-announce">
                                <div class="td-announce__title">
                                    {{ channel.name }}
                                </div>
                            </td>
                            <td class="td-muted">
                                {{ channel.client?.user.name }}
                            </td>
                            <td class="td-muted">
                                {{ channel.category }}
                            </td>
                            <td>
                                <button
                                    @click="toggleChannelStatus(channel)"
                                    :title="
                                        channel.is_active
                                            ? 'Deactivate Channel'
                                            : 'Activate Channel'
                                    "
                                    class="status-toggle-btn"
                                >
                                    <span
                                        class="type-badge"
                                        :class="
                                            channel.is_active
                                                ? 'bg-emerald-50 text-emerald-700'
                                                : 'bg-red-50 text-red-600'
                                        "
                                    >
                                        {{
                                            channel.is_active
                                                ? 'Active'
                                                : 'Deactivated'
                                        }}
                                    </span>
                                </button>
                            </td>
                            <td class="td-muted">
                                {{ channel.type }}
                            </td>
                            <td>
                                <div style="display: flex; gap: 2px">
                                    <button
                                        @click="editChannel(channel)"
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
                                        @click="confirmDelete(channel)"
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

                <!-- Pagination -->
                <div
                    class="pagination-bar"
                    v-if="!channelsLoading && channels.data.length > 0"
                >
                    <span class="pagination-bar__info">
                        Showing {{ channels.from || 0 }}–{{
                            channels.to || 0
                        }}
                        of {{ channels.total || 0 }}
                    </span>
                    <div class="pagination-bar__pages">
                        <template v-for="(link, i) in channels.links" :key="i">
                            <button
                                v-if="link.url"
                                @click="reloadChannels(link.url)"
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

        <!-- ═══════════════ COMPOSE / EDIT MODAL ═══════════════ -->
        <transition name="modal">
            <div
                v-if="showModal"
                class="modal-backdrop"
                @click.self="closeModal"
            >
                <div class="modal-sheet">
                    <!-- Header -->
                    <div class="modal-sheet__header">
                        <div class="modal-sheet__header-left">
                            <div>
                                <div class="modal-sheet__title">
                                    {{
                                        isEditing
                                            ? 'Edit Channel'
                                            : 'Add Channel'
                                    }}
                                </div>
                                <div class="modal-sheet__sub">
                                    Configure channel billing and access
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

                    <!-- Body -->
                    <form
                        @submit.prevent="handleSubmit"
                        class="modal-sheet__body"
                    >
                        <!-- Name -->
                        <div class="field">
                            <label class="field__label">Name</label>
                            <input
                                v-model="form.name"
                                type="text"
                                class="field__input"
                                :class="{ 'field__input--error': errors.name }"
                                placeholder="e.g. Sunset Estate"
                            />
                            <span v-if="errors.name" class="field__error">{{
                                errors.name[0]
                            }}</span>
                        </div>

                        <!-- Category -->
                        <div class="field">
                            <label class="field__label">Category</label>
                            <input
                                v-model="form.category"
                                type="text"
                                class="field__input"
                                :class="{
                                    'field__input--error': errors.category,
                                }"
                                placeholder="e.g. Estate Security"
                            />
                            <span v-if="errors.category" class="field__error">{{
                                errors.category[0]
                            }}</span>
                        </div>

                        <!-- Client -->
                        <div class="field">
                            <label class="field__label">Client</label>
                            <div class="select-wrapper">
                                <select
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

                        <!-- Type -->
                        <div class="field">
                            <label class="field__label">Type</label>
                            <div class="select-wrapper">
                                <select
                                    v-model="form.channel_type"
                                    class="field__select"
                                >
                                    <option value="" disabled>
                                        -- Choose type --
                                    </option>
                                    <option value="residential">
                                        Residential
                                    </option>
                                    <option value="operational">
                                        Operational
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
                                v-if="errors.channel_type"
                                class="field__error"
                                >{{ errors.channel_type[0] }}</span
                            >
                        </div>

                        <!-- Billing Model -->
                        <div class="field">
                            <label class="field__label">Billing Model</label>
                            <div class="select-wrapper">
                                <select
                                    v-model="form.billing_model"
                                    class="field__select"
                                >
                                    <option value="" disabled>
                                        -- Choose billing model --
                                    </option>
                                    <option value="individual">
                                        Individual (each household pays
                                        separately)
                                    </option>
                                    <option value="bulk">
                                        Residential / Estate (bulk EFT billing)
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
                                v-if="errors.billing_model"
                                class="field__error"
                                >{{ errors.billing_model[0] }}</span
                            >
                        </div>

                        <!-- Estate Billing Fields -->
                        <transition name="slide-down">
                            <div
                                v-if="form.billing_model === 'bulk'"
                                class="payment-panel"
                                style="
                                    display: flex;
                                    flex-direction: column;
                                    gap: 14px;
                                "
                            >
                                <div class="field" style="margin-bottom: 0">
                                    <label class="field__label"
                                        >Billing Contact Name</label
                                    >
                                    <input
                                        v-model="form.billing_contact_name"
                                        type="text"
                                        class="field__input"
                                        placeholder="e.g. Jane Smith"
                                    />
                                    <span
                                        v-if="errors.billing_contact_name"
                                        class="field__error"
                                        >{{
                                            errors.billing_contact_name[0]
                                        }}</span
                                    >
                                </div>

                                <div class="field" style="margin-bottom: 0">
                                    <label class="field__label"
                                        >Billing Contact Email</label
                                    >
                                    <input
                                        v-model="form.billing_contact_email"
                                        type="email"
                                        class="field__input"
                                        placeholder="billing@estate.co.za"
                                    />
                                    <span
                                        v-if="errors.billing_contact_email"
                                        class="field__error"
                                        >{{
                                            errors.billing_contact_email[0]
                                        }}</span
                                    >
                                </div>

                                <div class="field" style="margin-bottom: 0">
                                    <label class="field__label">
                                        Billing Contact Phone
                                        <span class="field__hint"
                                            >optional</span
                                        >
                                    </label>
                                    <input
                                        v-model="form.billing_contact_phone"
                                        type="text"
                                        class="field__input"
                                        placeholder="+27 82 000 0000"
                                    />
                                </div>
                            </div>
                        </transition>

                        <!-- Amount per household -->
                        <div class="field">
                            <label class="field__label"
                                >Amount Per Household (R)</label
                            >
                            <input
                                v-model="form.amount_per_household"
                                type="number"
                                min="1"
                                step="0.01"
                                class="field__input"
                                :class="{
                                    'field__input--error':
                                        errors.amount_per_household,
                                }"
                                placeholder="80"
                            />
                            <span class="field__hint" style="color: #94a3b8">
                                Default is R80/household/month. Override if
                                needed.
                            </span>
                            <span
                                v-if="errors.amount_per_household"
                                class="field__error"
                                >{{ errors.amount_per_household[0] }}</span
                            >
                        </div>

                        <!-- Amount per linked account -->
                        <div class="field">
                            <label class="field__label"
                                >Amount Per Linked Account (R)</label
                            >
                            <input
                                v-model="form.amount_per_linked_account"
                                type="number"
                                min="0"
                                step="0.01"
                                class="field__input"
                                :class="{
                                    'field__input--error':
                                        errors.amount_per_linked_account,
                                }"
                                placeholder="30"
                            />
                            <span class="field__hint" style="color: #94a3b8">
                                Rate charged per linked account (spouse, child,
                                tenant) under a household on this channel.
                                Charged in addition to the household's own
                                amount.
                            </span>
                            <span
                                v-if="errors.amount_per_linked_account"
                                class="field__error"
                                >{{ errors.amount_per_linked_account[0] }}</span
                            >
                        </div>

                        <!-- Guard fixed amount -->
                        <div class="field">
                            <label class="field__label">
                                Guard Fixed Amount (R)
                                <span class="field__hint"
                                    >optional — gate guards</span
                                >
                            </label>
                            <input
                                v-model="form.guard_fixed_amount"
                                type="number"
                                min="0"
                                step="0.01"
                                class="field__input"
                                :class="{
                                    'field__input--error':
                                        errors.guard_fixed_amount,
                                }"
                                placeholder="0"
                            />
                            <span class="field__hint" style="color: #94a3b8">
                                Fixed amount per household that goes to gate
                                guards, split evenly among them.
                            </span>
                            <span
                                v-if="errors.guard_fixed_amount"
                                class="field__error"
                                >{{ errors.guard_fixed_amount[0] }}</span
                            >
                        </div>

                        <!-- Security pool -->
                        <div class="field">
                            <label class="field__label"
                                >Security Pool (R)</label
                            >
                            <input
                                v-model="form.security_pool"
                                type="number"
                                min="0"
                                step="0.01"
                                class="field__input"
                                :class="{
                                    'field__input--error': errors.security_pool,
                                }"
                                :placeholder="
                                    String(
                                        form.amount_per_household -
                                            (form.guard_fixed_amount || 0),
                                    )
                                "
                            />
                            <span class="field__hint" style="color: #94a3b8">
                                Amount the security percentage is calculated on.
                                Leave blank to use remaining amount after guard
                                fee.
                            </span>
                            <span
                                v-if="errors.security_pool"
                                class="field__error"
                                >{{ errors.security_pool[0] }}</span
                            >
                        </div>

                        <!-- Security cut -->
                        <div class="field">
                            <label class="field__label">Security Cut (%)</label>
                            <input
                                v-model="form.security_percentage"
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                class="field__input"
                                :class="{
                                    'field__input--error':
                                        errors.security_percentage,
                                }"
                                placeholder="e.g. 60"
                            />
                            <span class="field__hint" style="color: #94a3b8">
                                % of the security pool paid to the responding
                                security company. Leave blank to use client's
                                default revenue share.
                            </span>
                            <span
                                v-if="errors.security_percentage"
                                class="field__error"
                                >{{ errors.security_percentage[0] }}</span
                            >
                        </div>

                        <!-- Split preview -->
                        <div
                            v-if="form.amount_per_household"
                            class="split-preview"
                        >
                            <div class="split-preview__title">
                                Split Preview (per household)
                            </div>
                            <div class="split-preview__row">
                                <span>Household pays</span>
                                <strong
                                    >R{{
                                        Number(
                                            form.amount_per_household,
                                        ).toFixed(2)
                                    }}</strong
                                >
                            </div>
                            <div
                                class="split-preview__row"
                                v-if="form.guard_fixed_amount > 0"
                            >
                                <span>Gate guards (flat)</span>
                                <strong
                                    >R{{
                                        Number(form.guard_fixed_amount).toFixed(
                                            2,
                                        )
                                    }}</strong
                                >
                            </div>
                            <div class="split-preview__row">
                                <span>Security pool</span>
                                <strong
                                    >R{{
                                        securityPoolPreview.toFixed(2)
                                    }}</strong
                                >
                            </div>
                            <div class="split-preview__row">
                                <span
                                    >Security gets ({{
                                        form.security_percentage || 0
                                    }}%)</span
                                >
                                <strong
                                    >R{{
                                        securityCutPreview.toFixed(2)
                                    }}</strong
                                >
                            </div>
                            <div
                                class="split-preview__row split-preview__row--total"
                            >
                                <span>Echo Link keeps</span>
                                <strong
                                    >R{{
                                        echoLinkKeepsPreview.toFixed(2)
                                    }}</strong
                                >
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="modal-actions">
                            <button
                                type="button"
                                class="btn-ghost"
                                @click="closeModal"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                class="btn-primary"
                                :disabled="isProcessing"
                            >
                                <svg
                                    v-if="isProcessing"
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
                                    isProcessing
                                        ? isEditing
                                            ? 'Updating…'
                                            : 'Adding…'
                                        : isEditing
                                          ? 'Update Channel'
                                          : 'Add Channel'
                                }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </transition>

        <!-- DELETE MODAL -->
        <transition name="modal">
            <div
                v-if="isConfirmingDeletion"
                class="modal-backdrop"
                @click.self="closeModal"
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
                    <h2 class="confirm-modal__title">Delete Channel?</h2>
                    <p class="confirm-modal__body">
                        This action cannot be undone. Type
                        <strong style="color: #dc2626">{{
                            channelToDelete?.name
                        }}</strong>
                        to confirm.
                    </p>
                    <input
                        v-model="confirmationName"
                        type="text"
                        :placeholder="channelToDelete?.name"
                        class="field__input"
                        autocomplete="off"
                        style="margin-bottom: 4px"
                    />
                    <div class="confirm-modal__actions">
                        <button @click="closeModal" class="btn-ghost">
                            Keep it
                        </button>
                        <button
                            @click="deleteChannel"
                            class="btn-danger"
                            :disabled="
                                isProcessing ||
                                confirmationName !== channelToDelete?.name
                            "
                        >
                            {{
                                isProcessing
                                    ? 'Deleting…'
                                    : 'Yes, Delete Channel'
                            }}
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- TOGGLE STATUS MODAL -->
        <transition name="modal">
            <div
                v-if="confirmToggleChannel"
                class="modal-backdrop"
                @click.self="confirmToggleChannel = null"
            >
                <div class="confirm-modal">
                    <div
                        class="confirm-modal__icon"
                        :style="
                            confirmToggleChannel.is_active
                                ? 'background:#fef2f2'
                                : 'background:#f0fdf4'
                        "
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-7 w-7"
                            :style="
                                confirmToggleChannel.is_active
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
                            confirmToggleChannel.is_active
                                ? 'Deactivate Channel'
                                : 'Activate Channel'
                        }}
                    </h2>
                    <p class="confirm-modal__body" style="margin-bottom: 4px">
                        {{ confirmToggleChannel.name }}
                    </p>

                    <div
                        v-if="confirmToggleChannel.is_active"
                        class="toggle-warning toggle-warning--danger"
                    >
                        <p style="font-weight: 700; margin-bottom: 4px">
                            Before you deactivate:
                        </p>
                        <ul>
                            <li>Connected members will be disconnected</li>
                            <li>
                                No one can transmit or receive on this channel
                            </li>
                            <li>Channel can be reactivated at any time</li>
                        </ul>
                    </div>
                    <div v-else class="toggle-warning toggle-warning--success">
                        Channel will be restored and members will be able to
                        connect and communicate again.
                    </div>

                    <div class="confirm-modal__actions">
                        <button
                            @click="confirmToggleChannel = null"
                            class="btn-ghost"
                        >
                            Cancel
                        </button>
                        <button
                            @click="proceedChannelToggle"
                            :class="
                                confirmToggleChannel.is_active
                                    ? 'btn-danger'
                                    : 'btn-success'
                            "
                        >
                            {{
                                confirmToggleChannel.is_active
                                    ? 'Yes, Deactivate'
                                    : 'Yes, Activate'
                            }}
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- Flash toast -->
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

/* STAT ROW */
.stat-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
}
.stat-card {
    background: #ffffff;
    border: 1px solid #e4e8ef;
    border-radius: 16px;
    padding: 20px 22px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    box-shadow: var(--shadow-sm);
    transition:
        box-shadow 0.2s,
        transform 0.2s;
}
.stat-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-1px);
}
.stat-card__label {
    font-size: 11px;
    font-weight: 600;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.stat-card__value {
    font-size: 30px;
    font-weight: 800;
    color: #1a2332;
    line-height: 1;
    letter-spacing: -1px;
}
.stat-card__value--red {
    color: #dc2626;
}
.stat-card__value--green {
    color: #16a34a;
}
.stat-card__value--orange {
    color: #ea580c;
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
}

.type-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}
.status-toggle-btn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
}

.td-announce__title {
    font-weight: 600;
    color: #1a2332;
}
.td-muted {
    color: #64748b;
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
}
.icon-btn--edit {
    color: #94a3b8;
}
.icon-btn--edit:hover {
    background: #eff6ff;
    color: #2563eb;
}
.icon-btn--danger {
    color: #94a3b8;
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

/* BUTTONS */
.btn-primary {
    display: inline-flex;
    align-items: center;
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
.btn-success:hover {
    background: #15803d;
    transform: translateY(-1px);
}

/* MODAL */
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(10, 18, 30, 0.55) !important;
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 24px;
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
    font-size: 12px;
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
    gap: 18px;
}

/* FIELDS */
.field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.field__label {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    letter-spacing: 0.3px;
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

/* PAYMENT / BULK PANEL */
.payment-panel {
    background: #fafafa;
    border: 1.5px solid #e4e8ef;
    border-radius: 10px;
    padding: 16px;
}

/* SPLIT PREVIEW */
.split-preview {
    background: #fafafa;
    border: 1.5px solid #e4e8ef;
    border-radius: 10px;
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.split-preview__title {
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    margin-bottom: 4px;
}
.split-preview__row {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    color: #475569;
}
.split-preview__row--total {
    font-weight: 700;
    color: #ea580c;
}

/* TOGGLE WARNING BLOCKS */
.toggle-warning {
    width: 100%;
    text-align: left;
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 12.5px;
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

/* MODAL ACTIONS */
.modal-actions {
    display: flex;
    gap: 10px;
    padding-top: 4px;
}
.modal-actions .btn-ghost {
    flex: 1;
    justify-content: center;
}
.modal-actions .btn-primary {
    flex: 2;
    justify-content: center;
}

/* CONFIRM MODAL */
.confirm-modal {
    background: #ffffff !important;
    border-radius: 20px;
    width: 100%;
    max-width: 400px;
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
    justify-content: center;
}
.confirm-modal__actions .btn-danger,
.confirm-modal__actions .btn-success {
    flex: 1.4;
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
.modal-leave-active .confirm-modal {
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
.modal-leave-to .confirm-modal {
    transform: scale(0.97) translateY(12px);
}

.slide-down-enter-active,
.slide-down-leave-active {
    transition: all 0.25s ease;
}
.slide-down-enter-from,
.slide-down-leave-to {
    opacity: 0;
    transform: translateY(-8px);
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

/* RESPONSIVE */
@media (max-width: 768px) {
    .stat-row {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }
}
@media (max-width: 640px) {
    .page-root {
        padding: 16px;
    }
    .stat-card {
        padding: 14px;
    }
    .stat-card__value {
        font-size: 22px;
    }
    .data-table {
        min-width: 600px;
    }
    .table-card {
        overflow-x: auto;
    }
}
</style>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';

import { useAuthStore } from '@/stores/auth';
import { type BreadcrumbItem } from '@/types';
import { router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { onMounted, ref } from 'vue';

const auth = useAuthStore();

onMounted(() => {
    if (auth.user?.role !== 'admin') {
        router.visit('/dashboard'); // redirect non-admins away
    }
});

import '@inertiajs/core';
import { computed } from 'vue';

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
const confirmToggleClient = ref<any>(null);
const clientsLoading = ref(true);

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
    clientsLoading.value = true;
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
    } finally {
        clientsLoading.value = false;
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
    errors.value = {};
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
    password: '',
    organisation_type: '',
    organisation_name: '',
    plan: '',
    billing_cycle: 'monthly',
    partner_type: 'outsourced',
    revenue_share_percentage: 30,
});

function resetForm() {
    form.id = null;
    form.name = '';
    form.phone = '';
    form.email = '';
    form.address = '';
    form.role = '';
    form.password = '';
    form.organisation_type = ''; // ← add
    form.organisation_name = ''; // ← add
    form.plan = ''; // ← add
    form.billing_cycle = 'monthly'; // ← add
}

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
    setTimeout(() => (flashMessage.value = null), 3500); // auto-hide
}

const editClient = (client: any) => {
    isEditing.value = true;
    showModal.value = true;
    errors.value = {};

    form.id = client.id;
    form.name = client.user?.name || '';
    form.email = client.user?.email || '';
    form.phone = client.user?.phone || '';
    form.address = client.user?.address_line_1 || '';
    form.password = ''; // leave blank for edit
    form.value.partner_type = client.partner_type ?? 'outsourced';
    form.value.revenue_share_percentage = client.revenue_share_percentage ?? 30;
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

function toggleClientStatus(client: any) {
    confirmToggleClient.value = client;
}

async function proceedClientToggle() {
    if (!confirmToggleClient.value) return;
    try {
        isProcessing.value = true;
        const response = await axios.patch(
            `${import.meta.env.VITE_APP_URL}/api/clients/${confirmToggleClient.value.id}/toggle-status`,
            {},
            {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                },
            },
        );
        showMessage(response.data.message);
        await reloadClients();
    } catch (err) {
        console.error('Failed to toggle status', err);
    } finally {
        isProcessing.value = false;
        confirmToggleClient.value = null;
    }
}

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

const annualSummary = computed(() => {
    const monthly: Record<string, number> = {
        basic: 499,
        standard: 999,
        premium: 1999,
    };
    const annual: Record<string, number> = {
        basic: 415,
        standard: 829,
        premium: 1659,
    };
    const plan = form.plan;
    if (!plan) return null;
    return {
        monthlyEquivalent: annual[plan],
        billedAnnually: annual[plan] * 12,
        saving: (monthly[plan] - annual[plan]) * 12,
    };
});

const activeCount = computed(
    () => clients.value.data.filter((c: any) => c.user?.is_active).length,
);
const inactiveCount = computed(
    () => clients.value.data.filter((c: any) => !c.user?.is_active).length,
);
const estateCount = computed(
    () =>
        clients.value.data.filter((c: any) => c.organisation_type === 'estate')
            .length,
);
</script>

<template>
    <Head title="Clients" />

    <AppLayout>
        <div class="page-root">
            <!-- PAGE HEADER -->
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Operations</div>
                    <h1 class="page-header__title">Clients</h1>
                </div>
                <div class="page-header__right">
                    <button class="btn-primary" @click="openModal">
                        Add Client
                    </button>
                </div>
            </div>

            <!-- STAT CARDS -->
            <div class="stat-row">
                <div class="stat-card">
                    <div class="stat-card__label">Total Clients</div>
                    <div class="stat-card__value">
                        {{ clients.total || 0 }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Active</div>
                    <div class="stat-card__value stat-card__value--green">
                        {{ activeCount }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Inactive</div>
                    <div class="stat-card__value stat-card__value--red">
                        {{ inactiveCount }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Estates</div>
                    <div class="stat-card__value stat-card__value--orange">
                        {{ estateCount }}
                    </div>
                </div>
            </div>

            <!-- TABLE CARD -->
            <div class="table-card">
                <!-- Loading -->
                <div v-if="clientsLoading" class="empty-state">
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
                        >Loading clients…</span
                    >
                </div>

                <!-- Empty -->
                <div v-else-if="clients.data.length === 0" class="empty-state">
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
                    <p class="empty-state__title">No clients yet</p>
                    <p class="empty-state__sub">
                        Hit "Add Client" to onboard your first client
                    </p>
                </div>

                <!-- Table -->
                <table v-else class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Email</th>
                            <th>Address</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="client in clients.data" :key="client.id">
                            <td class="td-announce">
                                <div class="td-announce__title">
                                    {{ client.user.name }}
                                </div>
                            </td>
                            <td class="td-muted">
                                {{ client.user.phone }}
                            </td>
                            <td class="td-muted">
                                {{ client.user.email }}
                            </td>
                            <td class="td-muted">
                                {{ client.user.address_line_1 ?? 'N/A' }}
                            </td>
                            <td>
                                <button
                                    @click="toggleClientStatus(client)"
                                    type="button"
                                    :title="
                                        client.user.is_active
                                            ? 'Deactivate Client'
                                            : 'Activate Client'
                                    "
                                    class="status-toggle-btn"
                                >
                                    <span
                                        class="type-badge"
                                        :class="
                                            client.user.is_active
                                                ? 'bg-emerald-50 text-emerald-700'
                                                : 'bg-red-50 text-red-600'
                                        "
                                    >
                                        {{
                                            client.user.is_active
                                                ? 'Active'
                                                : 'Inactive'
                                        }}
                                    </span>
                                </button>
                            </td>
                            <td>
                                <div style="display: flex; gap: 2px">
                                    <button
                                        @click="editClient(client)"
                                        class="icon-btn icon-btn--edit"
                                        title="Edit Client"
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
                                        @click="confirmDelete(client)"
                                        class="icon-btn icon-btn--danger"
                                        title="Delete Client"
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
                                    <button
                                        v-if="auth.user?.role === 'admin'"
                                        @click="
                                            router.visit(
                                                `/clients/${client.id}/checkpoints`,
                                            )
                                        "
                                        class="icon-btn icon-btn--checkpoints"
                                        title="View Checkpoints"
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
                                                d="M12 4v1m0 14v1M4.22 4.22l.707.707m12.728 12.728l.707.707M1 12h1m18 0h1M4.22 19.78l.707-.707M18.364 5.636l.707-.707M9 12a3 3 0 106 0 3 3 0 00-6 0z"
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
                    v-if="!clientsLoading && clients.data.length > 0"
                >
                    <span class="pagination-bar__info">
                        Showing {{ clients.from || 0 }}–{{ clients.to || 0 }} of
                        {{ clients.total || 0 }}
                    </span>
                    <div class="pagination-bar__pages">
                        <template v-for="(link, i) in clients.links" :key="i">
                            <button
                                v-if="link.url"
                                @click="reloadClients(link.url)"
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
                                        isEditing ? 'Edit Client' : 'Add Client'
                                    }}
                                </div>
                                <div class="modal-sheet__sub">
                                    Onboard or update a client organisation
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
                        <!-- ORGANISATION TYPE -->
                        <div class="field">
                            <label class="field__label"
                                >Organisation Type</label
                            >
                            <div class="toggle-row">
                                <button
                                    type="button"
                                    class="option-card"
                                    :class="{
                                        'option-card--active':
                                            form.organisation_type === 'watch',
                                    }"
                                    @click="form.organisation_type = 'watch'"
                                >
                                    <span class="option-card__title"
                                        >Neighbourhood Watch</span
                                    >
                                    <span class="option-card__sub"
                                        >CPF / Watch Group / Individual</span
                                    >
                                </button>
                                <button
                                    type="button"
                                    class="option-card"
                                    :class="{
                                        'option-card--active':
                                            form.organisation_type === 'estate',
                                    }"
                                    @click="form.organisation_type = 'estate'"
                                >
                                    <span class="option-card__title"
                                        >Estate / Complex</span
                                    >
                                    <span class="option-card__sub"
                                        >Gated Estate / HOA / Complex</span
                                    >
                                </button>
                            </div>
                            <span
                                v-if="errors.organisation_type"
                                class="field__error"
                                >{{ errors.organisation_type[0] }}</span
                            >
                        </div>

                        <!-- NAME + PHONE -->
                        <div
                            style="
                                display: grid;
                                grid-template-columns: 1fr 1fr;
                                gap: 14px;
                            "
                        >
                            <div class="field">
                                <label class="field__label">Full Name</label>
                                <input
                                    v-model="form.name"
                                    type="text"
                                    class="field__input"
                                    :class="{
                                        'field__input--error': errors.name,
                                    }"
                                    placeholder="John Dlamini"
                                />
                                <span v-if="errors.name" class="field__error">{{
                                    errors.name[0]
                                }}</span>
                            </div>
                            <div class="field">
                                <label class="field__label">Phone</label>
                                <input
                                    v-model="form.phone"
                                    type="text"
                                    class="field__input"
                                    :class="{
                                        'field__input--error': errors.phone,
                                    }"
                                    placeholder="+27 82 000 0000"
                                />
                                <span
                                    v-if="errors.phone"
                                    class="field__error"
                                    >{{ errors.phone[0] }}</span
                                >
                            </div>
                        </div>

                        <!-- ORGANISATION NAME -->
                        <div class="field">
                            <label class="field__label"
                                >Organisation Name</label
                            >
                            <input
                                v-model="form.organisation_name"
                                type="text"
                                class="field__input"
                                :class="{
                                    'field__input--error':
                                        errors.organisation_name,
                                }"
                                :placeholder="
                                    form.organisation_type === 'estate'
                                        ? 'Sunridge Estate'
                                        : 'Midrand North Watch'
                                "
                            />
                            <span
                                v-if="errors.organisation_name"
                                class="field__error"
                                >{{ errors.organisation_name[0] }}</span
                            >
                        </div>

                        <!-- EMAIL -->
                        <div class="field">
                            <label class="field__label">Email</label>
                            <input
                                v-model="form.email"
                                type="email"
                                class="field__input"
                                :class="{ 'field__input--error': errors.email }"
                                placeholder="email@example.com"
                            />
                            <span v-if="errors.email" class="field__error">{{
                                errors.email[0]
                            }}</span>
                        </div>

                        <!-- ADDRESS -->
                        <div class="field">
                            <label class="field__label">Address</label>
                            <textarea
                                v-model="form.address"
                                class="field__input field__textarea"
                                rows="3"
                                :class="{
                                    'field__input--error': errors.address,
                                }"
                            ></textarea>
                            <span v-if="errors.address" class="field__error">{{
                                errors.address[0]
                            }}</span>
                        </div>

                        <!-- PASSWORD -->
                        <div class="field">
                            <label class="field__label">{{
                                isEditing ? 'Set New Password' : 'Password'
                            }}</label>
                            <input
                                v-model="form.password"
                                type="password"
                                class="field__input"
                                :class="{
                                    'field__input--error': errors.password,
                                }"
                                :placeholder="
                                    isEditing
                                        ? 'Leave blank to keep current'
                                        : 'Min. 8 characters'
                                "
                            />
                            <span v-if="errors.password" class="field__error">{{
                                errors.password[0]
                            }}</span>
                        </div>

                        <!-- PARTNER TYPE -->
                        <div class="field">
                            <label class="field__label">Partner Type</label>
                            <div class="toggle-row">
                                <button
                                    type="button"
                                    class="option-card"
                                    :class="{
                                        'option-card--active':
                                            form.partner_type === 'outsourced',
                                    }"
                                    @click="
                                        form.partner_type = 'outsourced';
                                        form.revenue_share_percentage = 30;
                                    "
                                >
                                    <span class="option-card__title"
                                        >Outsourced</span
                                    >
                                    <span class="option-card__sub"
                                        >Echo Link brings the clients</span
                                    >
                                </button>
                                <button
                                    type="button"
                                    class="option-card"
                                    :class="{
                                        'option-card--active':
                                            form.partner_type ===
                                            'existing_clients',
                                    }"
                                    @click="
                                        form.partner_type = 'existing_clients';
                                        form.revenue_share_percentage = 45;
                                    "
                                >
                                    <span class="option-card__title"
                                        >Existing Clients</span
                                    >
                                    <span class="option-card__sub"
                                        >They bring their own clients</span
                                    >
                                </button>
                            </div>
                            <span
                                v-if="errors.partner_type"
                                class="field__error"
                                >{{ errors.partner_type[0] }}</span
                            >
                        </div>

                        <!-- REVENUE SHARE PERCENTAGE -->
                        <div class="field">
                            <label class="field__label">
                                Revenue Share %
                                <span class="field__hint"
                                    >their cut of R80/household</span
                                >
                            </label>
                            <input
                                v-model="form.revenue_share_percentage"
                                type="number"
                                min="0"
                                max="100"
                                step="0.5"
                                class="field__input"
                                :class="{
                                    'field__input--error':
                                        errors.revenue_share_percentage,
                                }"
                                placeholder="30"
                            />
                            <span class="field__hint" style="color: #94a3b8">
                                Partner earns
                                <strong style="color: #ea580c"
                                    >R{{
                                        (
                                            (form.revenue_share_percentage /
                                                100) *
                                            80
                                        ).toFixed(2)
                                    }}</strong
                                >
                                per household · Echo Link keeps
                                <strong style="color: #475569"
                                    >R{{
                                        (
                                            80 -
                                            (form.revenue_share_percentage /
                                                100) *
                                                80
                                        ).toFixed(2)
                                    }}</strong
                                >
                            </span>
                            <span
                                v-if="errors.revenue_share_percentage"
                                class="field__error"
                                >{{ errors.revenue_share_percentage[0] }}</span
                            >
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
                                          ? 'Update Client'
                                          : 'Add Client'
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
                @click.self="closeDeleteModal"
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
                    <h2 class="confirm-modal__title">Delete Client?</h2>
                    <p class="confirm-modal__body">
                        This action cannot be undone. Type
                        <strong style="color: #dc2626">{{
                            clientToDelete?.user?.name
                        }}</strong>
                        to confirm.
                    </p>
                    <input
                        v-model="confirmationName"
                        type="text"
                        :placeholder="clientToDelete?.user?.name"
                        class="field__input"
                        autocomplete="off"
                        style="margin-bottom: 4px"
                    />
                    <div class="confirm-modal__actions">
                        <button @click="closeDeleteModal" class="btn-ghost">
                            Keep it
                        </button>
                        <button
                            @click="deleteClient"
                            class="btn-danger"
                            :disabled="
                                confirmationName !== clientToDelete?.user?.name
                            "
                        >
                            {{
                                deleteForm.processing
                                    ? 'Deleting…'
                                    : 'Yes, Delete Client'
                            }}
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- TOGGLE STATUS MODAL -->
        <transition name="modal">
            <div
                v-if="confirmToggleClient"
                class="modal-backdrop"
                @click.self="confirmToggleClient = null"
            >
                <div class="confirm-modal">
                    <div
                        class="confirm-modal__icon"
                        :style="
                            confirmToggleClient.user.is_active
                                ? 'background:#fef2f2'
                                : 'background:#f0fdf4'
                        "
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-7 w-7"
                            :style="
                                confirmToggleClient.user.is_active
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
                            confirmToggleClient.user.is_active
                                ? 'Deactivate Client'
                                : 'Activate Client'
                        }}
                    </h2>
                    <p class="confirm-modal__body" style="margin-bottom: 4px">
                        {{ confirmToggleClient.user.name }}
                    </p>

                    <div
                        v-if="confirmToggleClient.user.is_active"
                        class="toggle-warning toggle-warning--danger"
                    >
                        <p style="font-weight: 700; margin-bottom: 4px">
                            Before you deactivate:
                        </p>
                        <ul>
                            <li>
                                Linked personnel will be unable to log in to
                                Echo Link
                            </li>
                            <li>
                                Connected personnel will be dropped from their
                                channels
                            </li>
                            <li>Client can be reactivated at any time</li>
                        </ul>
                    </div>
                    <div v-else class="toggle-warning toggle-warning--success">
                        Client and all linked personnel will regain access to
                        Echo Link.
                    </div>

                    <div class="confirm-modal__actions">
                        <button
                            @click="confirmToggleClient = null"
                            class="btn-ghost"
                        >
                            Cancel
                        </button>
                        <button
                            @click="proceedClientToggle"
                            :class="
                                confirmToggleClient.user.is_active
                                    ? 'btn-danger'
                                    : 'btn-success'
                            "
                        >
                            {{
                                confirmToggleClient.user.is_active
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
.icon-btn--checkpoints {
    color: #94a3b8;
}
.icon-btn--checkpoints:hover {
    background: #fff7ed;
    color: #ea580c;
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
.field__textarea {
    resize: vertical;
    min-height: 72px;
    line-height: 1.6;
}

/* OPTION CARD (org type / partner type toggles) */
.toggle-row {
    display: flex;
    gap: 10px;
}
.option-card {
    flex: 1;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 3px;
    text-align: left;
    padding: 12px 14px;
    background: #f8fafc;
    border: 1.5px solid #e4e8ef;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.15s;
    font-family: inherit;
}
.option-card:hover {
    border-color: #fdba8c;
}
.option-card--active {
    border-color: #ea580c;
    background: #fff7ed;
}
.option-card__title {
    font-size: 13px;
    font-weight: 700;
    color: #1a2332;
}
.option-card__sub {
    font-size: 11px;
    color: #94a3b8;
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
    .toggle-row {
        flex-wrap: wrap;
    }
    .option-card {
        flex: none;
        width: 100%;
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
        min-width: 640px;
    }
    .table-card {
        overflow-x: auto;
    }
}
</style>

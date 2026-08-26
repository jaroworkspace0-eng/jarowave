<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { VueTelInput } from 'vue-tel-input';
import 'vue-tel-input/vue-tel-input.css';

const getHeaders = () => ({
    headers: { Authorization: `Bearer ${localStorage.getItem('token')}` },
});

const guards = ref<any[]>([]);
const myChannels = ref<any[]>([]);
const loading = ref(true);
const togglingId = ref<number | null>(null);
const flashMessage = ref<string | null>(null);
const confirmToggle = ref<any | null>(null);
const searchQuery = ref('');

function showMessage(message: string) {
    flashMessage.value = message;
    setTimeout(() => (flashMessage.value = null), 3500);
}

const filteredGuards = computed(() => {
    if (!searchQuery.value.trim()) return guards.value;
    const q = searchQuery.value.trim().toLowerCase();
    return guards.value.filter(
        (g) =>
            g.user.name?.toLowerCase().includes(q) ||
            g.user.email?.toLowerCase().includes(q) ||
            g.user.phone?.toLowerCase().includes(q),
    );
});

const loadMyChannels = async () => {
    const { data } = await axios.get(
        `${import.meta.env.VITE_APP_URL}/api/estate/tenants/channels`,
        getHeaders(),
    );
    myChannels.value = data;
};

const loadGuards = async () => {
    loading.value = true;
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/estate/guards`,
            getHeaders(),
        );
        guards.value = data;
    } finally {
        loading.value = false;
    }
};

const toggleAccess = (guard: any) => {
    confirmToggle.value = guard;
};

const proceedToggle = async () => {
    const guard = confirmToggle.value;
    if (!guard) return;
    confirmToggle.value = null;
    togglingId.value = guard.id;
    try {
        const { data } = await axios.patch(
            `${import.meta.env.VITE_APP_URL}/api/estate/guards/${guard.id}/dashboard-access`,
            {},
            getHeaders(),
        );
        guard.has_dashboard_access = data.has_dashboard_access;
        showMessage(data.message);
    } catch (err: any) {
        showMessage(err.response?.data?.message ?? 'Failed to update access.');
    } finally {
        togglingId.value = null;
    }
};

// ── add / edit guard ────────────────────────────────────────────────────
const showModal = ref(false);
const isEditing = ref(false);
const loadingAdd = ref(false);
const errors = ref<Record<string, string[]>>({});
const newGuardPassword = ref<string | null>(null);

const form = ref({
    id: null as number | null,
    name: '',
    email: '',
    phone: '+27',
    channel_id: '' as any,
});

const openModal = () => {
    isEditing.value = false;
    errors.value = {};
    newGuardPassword.value = null;
    form.value = {
        id: null,
        name: '',
        email: '',
        phone: '+27',
        channel_id: myChannels.value[0]?.id ?? '',
    };
    showModal.value = true;
};

const editGuard = (guard: any) => {
    isEditing.value = true;
    errors.value = {};
    newGuardPassword.value = null;
    form.value = {
        id: guard.id,
        name: guard.user.name,
        email: guard.user.email,
        phone: guard.user.phone,
        channel_id: guard.channels?.[0]?.id ?? myChannels.value[0]?.id ?? '',
    };
    showModal.value = true;
};

const closeModal = () => (showModal.value = false);

const handlePhoneInput = (val: string) => {
    if (!val || !val.startsWith('+27')) {
        form.value.phone = '+27';
        return;
    }
    form.value.phone = val.replace(/\s+/g, '').replace(/[^0-9+]/g, '');
};

const submitGuard = async () => {
    loadingAdd.value = true;
    errors.value = {};
    try {
        if (isEditing.value) {
            const { data } = await axios.put(
                `${import.meta.env.VITE_APP_URL}/api/estate/guards/${form.value.id}`,
                form.value,
                getHeaders(),
            );
            showMessage(data.message ?? 'Guard updated.');
            closeModal();
        } else {
            const { data } = await axios.post(
                `${import.meta.env.VITE_APP_URL}/api/estate/guards`,
                form.value,
                getHeaders(),
            );
            newGuardPassword.value = data.temp_password;
        }
        await loadGuards();
    } catch (err: any) {
        errors.value = err.response?.data?.errors || {};
    } finally {
        loadingAdd.value = false;
    }
};

// ── delete guard ─────────────────────────────────────────────────────────
const confirmDelete = ref<any | null>(null);
const deleting = ref(false);

const promptDelete = (guard: any) => {
    confirmDelete.value = guard;
};

const executeDelete = async () => {
    if (!confirmDelete.value) return;
    deleting.value = true;
    try {
        const { data } = await axios.delete(
            `${import.meta.env.VITE_APP_URL}/api/estate/guards/${confirmDelete.value.id}`,
            getHeaders(),
        );
        showMessage(data.message ?? 'Guard removed.');
        confirmDelete.value = null;
        await loadGuards();
    } catch (err: any) {
        showMessage(err.response?.data?.message ?? 'Failed to remove guard.');
    } finally {
        deleting.value = false;
    }
};

onMounted(async () => {
    await loadMyChannels();
    await loadGuards();
});
</script>

<template>
    <Head title="Guards" />

    <AppLayout>
        <div class="page-root">
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Estate</div>
                    <h1 class="page-header__title">Guards</h1>
                </div>
                <div class="page-header__right">
                    <button class="btn-primary" @click="openModal">
                        Add Guard
                    </button>
                </div>
            </div>

            <div class="filter-bar">
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
                        type="text"
                        class="search-wrap__input"
                        placeholder="Search guards…"
                    />
                    <span
                        v-if="searchQuery"
                        class="search-wrap__clear"
                        @click="searchQuery = ''"
                        >×</span
                    >
                </div>
            </div>

            <div class="table-card">
                <div v-if="loading" class="empty-state">
                    <p class="empty-state__title">Loading guards…</p>
                </div>
                <div
                    v-else-if="filteredGuards.length === 0"
                    class="empty-state"
                >
                    <p class="empty-state__title">
                        {{
                            searchQuery
                                ? 'No guards match your search'
                                : 'No guards yet'
                        }}
                    </p>
                    <p class="empty-state__sub">
                        {{
                            searchQuery
                                ? 'Try a different name, email, or number'
                                : 'Add your first guard to get started'
                        }}
                    </p>
                </div>
                <div v-else style="overflow-x: auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Guard</th>
                                <th>Contact</th>
                                <th>Channel</th>
                                <th>Dashboard Access</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="g in filteredGuards" :key="g.id">
                                <td class="td-announce">
                                    <div class="td-announce__title">
                                        {{ g.user.name }}
                                    </div>
                                    <div class="td-announce__sub">
                                        {{ g.user.email }}
                                    </div>
                                </td>
                                <td class="td-muted">{{ g.user.phone }}</td>
                                <td class="td-muted">
                                    {{ g.channels?.[0]?.name || '—' }}
                                </td>
                                <td>
                                    <span
                                        class="type-badge"
                                        :class="
                                            g.has_dashboard_access
                                                ? 'bg-emerald-50 text-emerald-700'
                                                : 'bg-slate-100 text-slate-500'
                                        "
                                    >
                                        {{
                                            g.has_dashboard_access
                                                ? '✓ Granted'
                                                : 'No Access'
                                        }}
                                    </span>
                                </td>
                                <td>
                                    <div
                                        style="
                                            display: flex;
                                            align-items: center;
                                            gap: 2px;
                                        "
                                    >
                                        <button
                                            @click="toggleAccess(g)"
                                            :disabled="togglingId === g.id"
                                            class="btn-ghost"
                                            style="
                                                padding: 6px 14px;
                                                font-size: 12px;
                                            "
                                        >
                                            {{
                                                togglingId === g.id
                                                    ? 'Updating…'
                                                    : g.has_dashboard_access
                                                      ? 'Revoke Access'
                                                      : 'Grant Access'
                                            }}
                                        </button>
                                        <button
                                            @click="editGuard(g)"
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
                                            @click="promptDelete(g)"
                                            class="icon-btn icon-btn--danger"
                                            title="Remove"
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
            </div>
        </div>

        <!-- DASHBOARD ACCESS CONFIRM -->
        <transition name="modal">
            <div
                v-if="confirmToggle"
                class="modal-backdrop"
                @click.self="confirmToggle = null"
            >
                <div class="confirm-modal">
                    <h2 class="confirm-modal__title">
                        {{
                            confirmToggle.has_dashboard_access
                                ? 'Revoke'
                                : 'Grant'
                        }}
                        Dashboard Access
                    </h2>
                    <p class="confirm-modal__body">
                        {{ confirmToggle.user.name }}
                    </p>
                    <div
                        class="toggle-warning"
                        :class="
                            confirmToggle.has_dashboard_access
                                ? 'toggle-warning--success'
                                : 'toggle-warning--danger'
                        "
                    >
                        <template v-if="!confirmToggle.has_dashboard_access">
                            This guard will be able to see the live alerts feed
                            for every household on your estate - including home
                            addresses and live GPS location during an active
                            alert. Only grant this to guards you trust with that
                            level of access.
                        </template>
                        <template v-else>
                            This guard will lose access to the dashboard and
                            live alerts feed immediately.
                        </template>
                    </div>
                    <div class="confirm-modal__actions">
                        <button @click="confirmToggle = null" class="btn-ghost">
                            Cancel
                        </button>
                        <button
                            @click="proceedToggle"
                            class="btn-primary"
                            style="flex: 1.4"
                        >
                            {{
                                confirmToggle.has_dashboard_access
                                    ? 'Yes, Revoke'
                                    : 'Yes, Grant Access'
                            }}
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- ADD / EDIT MODAL -->
        <transition name="modal">
            <div
                v-if="showModal"
                class="modal-backdrop"
                @click.self="closeModal"
            >
                <div class="modal-sheet" style="max-width: 480px">
                    <div class="modal-sheet__header">
                        <div class="modal-sheet__title">
                            {{ isEditing ? 'Edit' : 'Add' }} Guard
                        </div>
                        <button class="close-btn" @click="closeModal">✕</button>
                    </div>

                    <div v-if="newGuardPassword" class="modal-sheet__body">
                        <div
                            class="callout"
                            style="
                                background: #f0fdf4;
                                border: 1px solid #86efac;
                                color: #15803d;
                            "
                        >
                            <span
                                style="
                                    width: 100%;
                                    display: flex;
                                    flex-direction: column;
                                    gap: 6px;
                                "
                            >
                                <span class="callout__label-title"
                                    >Guard added successfully</span
                                >
                                <span class="callout__label-sub">
                                    Temporary password - share this with the
                                    guard directly, it won't be shown again:
                                </span>
                                <span
                                    style="
                                        font-family: ui-monospace, monospace;
                                        font-size: 18px;
                                        font-weight: 700;
                                        letter-spacing: 2px;
                                    "
                                >
                                    {{ newGuardPassword }}
                                </span>
                            </span>
                        </div>
                        <div class="modal-actions">
                            <button
                                class="btn-primary"
                                style="flex: 1"
                                @click="closeModal"
                            >
                                Done
                            </button>
                        </div>
                    </div>

                    <form
                        v-else
                        @submit.prevent="submitGuard"
                        class="modal-sheet__body"
                    >
                        <div v-if="myChannels.length > 1" class="field">
                            <label class="field__label">Channel</label>
                            <div class="select-wrapper">
                                <select
                                    v-model="form.channel_id"
                                    class="field__select"
                                >
                                    <option
                                        v-for="ch in myChannels"
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
                        </div>
                        <div class="field">
                            <label class="field__label">Name</label>
                            <input v-model="form.name" class="field__input" />
                            <span v-if="errors.name" class="field__error">{{
                                errors.name[0]
                            }}</span>
                        </div>
                        <div class="field">
                            <label class="field__label">Email</label>
                            <input
                                v-model="form.email"
                                class="field__input"
                                :disabled="isEditing"
                            />
                            <span
                                v-if="isEditing"
                                style="
                                    font-size: 11px;
                                    color: #94a3b8;
                                    margin-top: 2px;
                                "
                            >
                                Can only be changed by the guard from their own
                                account.
                            </span>
                            <span v-if="errors.email" class="field__error">{{
                                errors.email[0]
                            }}</span>
                        </div>
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
                            <span v-if="errors.phone" class="field__error">{{
                                errors.phone[0]
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
                                :disabled="loadingAdd"
                            >
                                {{
                                    loadingAdd
                                        ? 'Saving…'
                                        : isEditing
                                          ? 'Update Guard'
                                          : 'Add Guard'
                                }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </transition>

        <!-- DELETE CONFIRM -->
        <transition name="modal">
            <div
                v-if="confirmDelete"
                class="modal-backdrop"
                @click.self="confirmDelete = null"
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
                    <h2 class="confirm-modal__title">Remove Guard?</h2>
                    <p class="confirm-modal__body">
                        {{ confirmDelete.user.name }} will lose access to Echo
                        Link on this estate immediately, including any dashboard
                        access already granted.
                    </p>
                    <div class="confirm-modal__actions">
                        <button @click="confirmDelete = null" class="btn-ghost">
                            Keep them
                        </button>
                        <button
                            @click="executeDelete"
                            :disabled="deleting"
                            class="btn-danger"
                        >
                            {{ deleting ? 'Removing…' : 'Yes, Remove' }}
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <transition name="toast">
            <div v-if="flashMessage" class="toast">{{ flashMessage }}</div>
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

.page-root {
    padding: 28px 32px;
    display: flex;
    flex-direction: column;
    gap: 20px;
    min-height: 100%;
    background: #f4f6f9;
}

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

.filter-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
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
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
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
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    letter-spacing: 0.3px;
    gap: 8px;
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
.callout__label-title {
    font-weight: 700;
}
.callout__label-sub {
    font-size: 13px;
    opacity: 0.85;
}

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
.modal-sheet__title {
    font-size: 15px;
    font-weight: 700;
    color: #1a2332;
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
    margin-left: auto;
}
.close-btn:hover {
    background: #e2e8f0;
}
.modal-sheet__body {
    padding: 24px;
    display: flex;
    flex-direction: column;
}

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
.confirm-modal__actions .btn-primary {
    flex: 1.4;
}

.toggle-warning {
    width: 100%;
    text-align: left;
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 13.5px;
    line-height: 1.6;
    margin-bottom: 4px;
}
.toggle-warning--danger {
    background: #fef2f2;
    border: 1px solid #fca5a5;
    color: #b91c1c;
}
.toggle-warning--success {
    background: #f0fdf4;
    border: 1px solid #86efac;
    color: #15803d;
}

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
    .data-table {
        min-width: 700px;
    }
    .table-card {
        overflow-x: auto;
    }
}
</style>
<style src="vue-multiselect/dist/vue-multiselect.min.css"></style>

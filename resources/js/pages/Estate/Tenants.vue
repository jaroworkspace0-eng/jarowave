<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { onMounted, ref } from 'vue';
import { VueTelInput } from 'vue-tel-input';
import 'vue-tel-input/vue-tel-input.css';

const generatePin = () => String(Math.floor(100000 + Math.random() * 900000));

const getHeaders = () => ({
    headers: { Authorization: `Bearer ${localStorage.getItem('token')}` },
});

const showInviteModal = ref(false);

// ── my channels ─────────────────────────────────────────────────────────────
const myChannels = ref<any[]>([]);
const loadMyChannels = async () => {
    const { data } = await axios.get(
        `${import.meta.env.VITE_APP_URL}/api/estate/tenants/channels`,
        getHeaders(),
    );
    myChannels.value = data;
};

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

// ── tenants list ────────────────────────────────────────────────────────────
const households = ref<any>({ data: [], from: 0, to: 0, total: 0, links: [] });
const householdList = ref<any[]>([]);
const searchQuery = ref('');
let searchTimeout: any = null;

const reloadTenants = async (url?: string) => {
    try {
        const { data } = await axios.get(
            url || `${import.meta.env.VITE_APP_URL}/api/estate/tenants`,
            {
                params: { search: searchQuery.value || undefined },
                ...getHeaders(),
            },
        );
        households.value = data.households;
        householdList.value = data.households.data;
    } catch (e) {
        console.error('Error fetching tenants', e);
    }
};

const handleSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => reloadTenants(), 400);
};

// ── flash ───────────────────────────────────────────────────────────────────
const flashMessage = ref<string | null>(null);
function showMessage(message: string) {
    flashMessage.value = message;
    setTimeout(() => (flashMessage.value = null), 3500);
}

// ── add / edit modal ─────────────────────────────────────────────────────────
const showModal = ref(false);
const isEditing = ref(false);
const loading = ref(false);
const errors = ref<Record<string, string[]>>({});

const form = ref({
    id: null as number | null,
    name: '',
    email: '',
    phone: '+27',
    unit_number: '',
    safe_cancel_pin: '',
    duress_pin: '',
    channel_id: '' as any,
});

const selectedChannel = computed(() =>
    myChannels.value.find((c) => c.id === form.value.channel_id),
);

const openModal = () => {
    isEditing.value = false;
    errors.value = {};
    form.value = {
        id: null,
        name: '',
        email: '',
        phone: '+27',
        unit_number: '',
        safe_cancel_pin: generatePin(),
        duress_pin: generatePin(),
        channel_id: myChannels.value[0]?.id ?? '',
    };
    showModal.value = true;
};

const editTenant = (employee: any) => {
    isEditing.value = true;
    errors.value = {};
    form.value = {
        id: employee.id,
        name: employee.user.name,
        email: employee.user.email,
        phone: employee.user.phone,
        unit_number: employee.user.unit_number || '',
        safe_cancel_pin: employee.user.safe_cancel_pin || '',
        duress_pin: employee.user.duress_pin || '',
        channel_id: employee.channels?.[0]?.id ?? myChannels.value[0]?.id ?? '',
    };
    showModal.value = true;
};

const closeModal = () => (showModal.value = false);

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

const submitTenant = async () => {
    loading.value = true;
    errors.value = {};
    try {
        if (isEditing.value) {
            const { data } = await axios.put(
                `${import.meta.env.VITE_APP_URL}/api/estate/tenants/${form.value.id}`,
                form.value,
                getHeaders(),
            );
            showMessage(data.message);
        } else {
            const { data } = await axios.post(
                `${import.meta.env.VITE_APP_URL}/api/estate/tenants`,
                form.value,
                getHeaders(),
            );
            showMessage(data.message);
        }
        closeModal();
        await reloadTenants();
    } catch (err: any) {
        errors.value = err.response?.data?.errors || {};
    } finally {
        loading.value = false;
    }
};

// ── delete ───────────────────────────────────────────────────────────────────
const showDeleteModal = ref(false);
const tenantToDelete = ref<number | null>(null);
const deleting = ref(false);

const confirmDelete = (id: number) => {
    tenantToDelete.value = id;
    showDeleteModal.value = true;
};

const executeDelete = async () => {
    deleting.value = true;
    try {
        const { data } = await axios.delete(
            `${import.meta.env.VITE_APP_URL}/api/estate/tenants/${tenantToDelete.value}`,
            getHeaders(),
        );
        showMessage(data.message);
        showDeleteModal.value = false;
        tenantToDelete.value = null;
        await reloadTenants();
    } catch {
        showMessage('Failed to delete tenant.');
    } finally {
        deleting.value = false;
    }
};

// ── invite links ────────────────────────────────────────────────────────────
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

const channelsWithoutInvite = computed(() => {
    const usedChannelIds = new Set(invites.value.map((i) => i.channel_id));
    return myChannels.value.filter((ch) => !usedChannelIds.has(ch.id));
});

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

const showInviteFlash = (
    msg: string,
    type: 'success' | 'error' = 'success',
) => {
    inviteFlash.value = { msg, type };
    setTimeout(() => (inviteFlash.value = null), 4000);
};

onMounted(async () => {
    await loadMyChannels();
    await reloadTenants();
    // await loadInvites();
});
</script>

<script lang="ts">
import { computed } from 'vue';
</script>

<template>
    <Head title="Tenants" />

    <AppLayout>
        <div class="page-root">
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Estate</div>
                    <h1 class="page-header__title">Tenants</h1>
                </div>
                <div
                    class="page-header__right"
                    style="display: flex; gap: 10px"
                >
                    <button
                        class="btn-ghost"
                        @click="
                            showInviteModal = true;
                            loadInvites();
                        "
                    >
                        Invite Links
                    </button>
                    <button class="btn-primary" @click="openModal">
                        Add Tenant
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
                        @input="handleSearch"
                        type="text"
                        class="search-wrap__input"
                        placeholder="Search tenants…"
                    />
                    <span
                        v-if="searchQuery"
                        class="search-wrap__clear"
                        @click="
                            searchQuery = '';
                            reloadTenants();
                        "
                        >×</span
                    >
                </div>
            </div>

            <div class="table-card">
                <div v-if="householdList.length === 0" class="empty-state">
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
                    <p class="empty-state__title">No tenants yet</p>
                    <p class="empty-state__sub">
                        Add your first tenant to get started
                    </p>
                </div>
                <div v-else style="overflow-x: auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Tenant</th>
                                <th>Contact</th>
                                <th>Unit</th>
                                <th>Estate</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="t in householdList" :key="t.id">
                                <td class="td-announce">
                                    <div class="td-announce__title">
                                        {{ t.user.name }}
                                    </div>
                                    <div class="td-announce__sub">
                                        {{ t.user.email }}
                                    </div>
                                </td>
                                <td class="td-muted">{{ t.user.phone }}</td>
                                <td class="td-muted">
                                    {{ t.user.unit_number || '—' }}
                                </td>
                                <td class="td-muted">
                                    {{ t.channels?.[0]?.name || '—' }}
                                </td>
                                <td>
                                    <div style="display: flex; gap: 2px">
                                        <button
                                            @click="editTenant(t)"
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
                                            @click="confirmDelete(t.id)"
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

                <div class="pagination-bar" v-if="householdList.length > 0">
                    <span class="pagination-bar__info">
                        Showing {{ households.from || 0 }}–{{
                            households.to || 0
                        }}
                        of {{ households.total }}
                    </span>
                    <div class="pagination-bar__pages">
                        <template
                            v-for="(link, index) in households.links"
                            :key="index"
                        >
                            <button
                                v-if="link.url"
                                @click="reloadTenants(link.url)"
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
                                    One permanent link per estate/channel -
                                    share with tenants to self-register on Echo
                                    Link.
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
                                                        :href="`https://wa.me/?text=${encodeURIComponent('Join ' + invite.channel_name + ' on Echo Link! Register for R80/month: ' + invite.invite_url)}`"
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
                                    tenants
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
                                                : 'Generate'
                                        }}
                                    </button>
                                </div>
                            </div>
                            <div
                                v-else-if="
                                    invites.length > 0 && myChannels.length > 0
                                "
                                style="
                                    margin-top: 8px;
                                    font-size: 14px;
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

        <!-- ADD / EDIT MODAL -->
        <transition name="modal">
            <div
                v-if="showModal"
                class="modal-backdrop"
                @click.self="closeModal"
            >
                <div class="modal-sheet" style="max-width: 620px">
                    <div class="modal-sheet__header">
                        <div class="modal-sheet__header-left">
                            <div class="modal-sheet__title">
                                {{ isEditing ? 'Edit' : 'Add' }} Tenant
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
                        @submit.prevent="submitTenant"
                        class="modal-sheet__body"
                    >
                        <div v-if="myChannels.length > 1" class="field">
                            <label class="field__label">Estate / Channel</label>
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
                                    :onlyCountries="['ZA']"
                                    defaultCountry="ZA"
                                    :autoFormat="true"
                                    :inputOptions="{
                                        showDialCode: true,
                                        placeholder: '+27821234567',
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
                                <label class="field__label">Unit Number</label>
                                <input
                                    v-model="form.unit_number"
                                    class="field__input"
                                    placeholder="e.g. Unit 4B"
                                />
                            </div>
                        </div>

                        <div class="callout callout--info">
                            <span
                                style="
                                    width: 100%;
                                    display: flex;
                                    flex-direction: column;
                                "
                            >
                                <span class="callout__label-title"
                                    >Address (inherited from estate)</span
                                >
                                <span
                                    class="callout__label-sub"
                                    style="margin-top: 2px"
                                >
                                    {{
                                        selectedChannel?.address_line_1 ||
                                        'Not set for this estate yet - contact an admin to configure the estate address'
                                    }}
                                    <template v-if="selectedChannel?.suburb">
                                        , {{ selectedChannel.suburb }}
                                    </template>
                                </span>
                            </span>
                        </div>

                        <div class="pin-panel">
                            <div class="pin-panel__header">
                                <div>
                                    <div class="pin-panel__title">
                                        Security Codes
                                    </div>
                                    <div class="pin-panel__sub">
                                        Auto-generated. Send to the tenant on
                                        their first login.
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
                                        v-model="form.safe_cancel_pin"
                                        maxlength="6"
                                        class="field__input pin-input pin-input--green"
                                        readonly
                                    />
                                </div>
                                <div class="field" style="gap: 4px">
                                    <label class="field__label">
                                        <span
                                            class="pin-dot pin-dot--red"
                                        ></span>
                                        Duress Code
                                    </label>
                                    <input
                                        v-model="form.duress_pin"
                                        maxlength="6"
                                        class="field__input pin-input pin-input--red"
                                        readonly
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="modal-actions mt-3">
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
                                {{
                                    loading
                                        ? 'Saving…'
                                        : isEditing
                                          ? 'Update Tenant'
                                          : 'Add Tenant'
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
                    <h2 class="confirm-modal__title">Confirm Removal</h2>
                    <p class="confirm-modal__body">
                        This action should only be used when a tenant is moving
                        out. Once removed, they will no longer have access to
                        Echo Link on this estate. Are you sure you want to
                        remove this tenant?
                    </p>
                    <div class="confirm-modal__actions">
                        <button
                            @click="showDeleteModal = false"
                            class="btn-ghost"
                        >
                            Keep it
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
                            Anyone who hasn't registered using the old link will
                            need the new one. Tenants who already registered are
                            not affected.
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

        <!-- FLASH -->
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
}

.td-announce__title {
    font-weight: 600;
    color: #1a2332;
}
.td-announce__sub {
    font-size: 13px;
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
    font-size: 14px;
    font-weight: 700;
    color: #475569;
    margin-top: 4px;
}
.invite-empty__sub {
    font-size: 14px;
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
    font-size: 12px;
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
    border: 1px solid #e4e8ef;
    border-radius: 12px;
    padding: 4px 0;
    box-shadow: var(--shadow-lg);
}
.sub-menu__item {
    display: block;
    width: 100%;
    text-align: left;
    padding: 10px 16px;
    background: none;
    border: none;
    cursor: pointer;
    font-family: inherit;
    transition: background 0.12s;
}
.sub-menu__item:hover {
    background: #f8fafc;
}
.sub-menu__item-title {
    font-size: 13px;
    font-weight: 700;
    color: #1a2332;
}
.sub-menu__item-sub {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 1px;
}
.sub-menu__item--warn .sub-menu__item-title {
    color: #b45309;
}
.sub-menu__item--success .sub-menu__item-title {
    color: #15803d;
}
.sub-menu__item--danger .sub-menu__item-title {
    color: #dc2626;
}
.sub-menu__item--danger:hover {
    background: #fef2f2;
}
.sub-menu__divider {
    border-top: 1px solid #f1f5f9;
    margin: 4px 0;
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
    font-size: 14px;
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
    .data-table {
        min-width: 900px;
    }
    .table-card,
    .invite-table-wrap {
        overflow-x: auto;
    }
}
</style>
<style src="vue-multiselect/dist/vue-multiselect.min.css"></style>

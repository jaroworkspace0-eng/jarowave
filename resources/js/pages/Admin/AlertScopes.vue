<script setup>
import { useCurrentUser } from '@/composables/useCurrentUser';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps({
    channels: { type: Array, default: () => [] }, // [{ id, name }]
    households: { type: Array, default: () => [] }, // [{ id, name }]
});

const page = usePage();

const { currentUser, loadCurrentUser } = useCurrentUser();
const currentAdminId = computed(() => currentUser.value?.id ?? null);

const loading = ref(true);
const admins = ref([]); // [{ id, name, email }] — loaded via searchAdmins()
const adminsLoading = ref(false);
let adminSearchTimer = null;

const scopes = ref([]); // [{ id, admin_id, scope_type, scope_id, admin: { name, email } }]
const activeFilter = ref('all'); // all | channel | household
const flashMsg = ref('');

const showCompose = ref(false);
const sending = ref(false);
const showDeleteModal = ref(false);
const deleteTargetId = ref(null);

const adminSearch = ref('');
const channelSearch = ref('');
const householdSearch = ref('');

const form = ref({
    admin_id: null,
    scope_type: 'channel',
    channel_ids: [],
    household_ids: [],
});

const filteredList = computed(() => {
    if (activeFilter.value === 'all') return scopes.value;
    return scopes.value.filter((s) => s.scope_type === activeFilter.value);
});

const claimingAdminCount = computed(
    () => new Set(scopes.value.map((s) => s.admin_id)).size,
);
const channelScopeCount = computed(
    () => scopes.value.filter((s) => s.scope_type === 'channel').length,
);
const householdScopeCount = computed(
    () => scopes.value.filter((s) => s.scope_type === 'household').length,
);

// Every channel/household already claimed by someone (any admin) — these
// can't be picked again in the compose modal, since a scope is exclusive
// to whoever holds it first.
const claimedChannelIds = computed(
    () =>
        new Set(
            scopes.value
                .filter((s) => s.scope_type === 'channel')
                .map((s) => s.scope_id),
        ),
);
const claimedHouseholdIds = computed(
    () =>
        new Set(
            scopes.value
                .filter((s) => s.scope_type === 'household')
                .map((s) => s.scope_id),
        ),
);

const filteredChannels = computed(() => {
    const q = channelSearch.value.toLowerCase().trim();
    return props.channels.filter(
        (c) =>
            !claimedChannelIds.value.has(c.id) &&
            (!q || (c.name || '').toLowerCase().includes(q)),
    );
});
const filteredHouseholds = computed(() => {
    const q = householdSearch.value.toLowerCase().trim();
    return props.households.filter(
        (h) =>
            !claimedHouseholdIds.value.has(h.id) &&
            (!q || (h.name || '').toLowerCase().includes(q)),
    );
});

const canSubmit = computed(() => {
    if (!form.value.admin_id) return false;
    return (
        form.value.channel_ids.length > 0 || form.value.household_ids.length > 0
    );
});

function labelFor(scope) {
    const list =
        scope.scope_type === 'channel' ? props.channels : props.households;
    return (
        list.find((item) => item.id === scope.scope_id)?.name ||
        `#${scope.scope_id}`
    );
}

function adminLabel(scope) {
    return (
        scope.admin?.name || scope.admin?.email || `Admin #${scope.admin_id}`
    );
}

async function loadScopes() {
    loading.value = true;
    try {
        const { data } = await axios.get('/api/admin/alert-scopes');
        scopes.value = data;
    } catch (e) {
        console.error(e);
    } finally {
        loading.value = false;
    }
}

async function searchAdmins(q = '') {
    adminsLoading.value = true;
    try {
        const { data } = await axios.get('/api/admin/alert-scopes/admins', {
            params: { q },
        });
        admins.value = data;
    } catch (e) {
        console.error(e);
    } finally {
        adminsLoading.value = false;
    }
}

watch(adminSearch, (q) => {
    clearTimeout(adminSearchTimer);
    adminSearchTimer = setTimeout(() => searchAdmins(q), 250);
});

function selectAdmin(id) {
    form.value.admin_id = id;
}

function toggleChannelPick(id) {
    const idx = form.value.channel_ids.indexOf(id);
    if (idx === -1) form.value.channel_ids.push(id);
    else form.value.channel_ids.splice(idx, 1);
}
function toggleHouseholdPick(id) {
    const idx = form.value.household_ids.indexOf(id);
    if (idx === -1) form.value.household_ids.push(id);
    else form.value.household_ids.splice(idx, 1);
}

function openCompose() {
    form.value = {
        admin_id: null,
        scope_type: 'channel',
        channel_ids: [],
        household_ids: [],
    };
    adminSearch.value = '';
    channelSearch.value = '';
    householdSearch.value = '';
    searchAdmins('');
    showCompose.value = true;
}
function closeCompose() {
    showCompose.value = false;
}

async function submitScope() {
    if (!canSubmit.value) return;
    sending.value = true;
    try {
        const requests = [
            ...form.value.channel_ids.map((scope_id) => ({
                admin_id: form.value.admin_id,
                scope_type: 'channel',
                scope_id,
            })),
            ...form.value.household_ids.map((scope_id) => ({
                admin_id: form.value.admin_id,
                scope_type: 'household',
                scope_id,
            })),
        ];

        const results = await Promise.allSettled(
            requests.map((payload) =>
                axios.post('/api/admin/alert-scopes', payload),
            ),
        );

        const failed = results.filter((r) => r.status === 'rejected').length;
        const ok = results.length - failed;

        if (failed === 0) {
            flash(`${ok} scope${ok !== 1 ? 's' : ''} claimed.`);
            closeCompose();
        } else if (ok === 0) {
            alert(
                'None of the selected scopes could be claimed — they were likely just claimed by another admin.',
            );
        } else {
            alert(
                `${ok} claimed, ${failed} failed — someone likely claimed those first. The list has been refreshed.`,
            );
            closeCompose();
        }

        await loadScopes();
    } finally {
        sending.value = false;
    }
}

function confirmDelete(id) {
    deleteTargetId.value = id;
    showDeleteModal.value = true;
}

async function executeDelete() {
    try {
        await axios.delete(`/api/admin/alert-scopes/${deleteTargetId.value}`);
        scopes.value = scopes.value.filter(
            (s) => s.id !== deleteTargetId.value,
        );
        flash('Claim released.');
    } catch (e) {
        console.error(e);
        alert(e.response?.data?.message || 'Unable to release this claim.');
    } finally {
        showDeleteModal.value = false;
        deleteTargetId.value = null;
    }
}

function flash(msg) {
    flashMsg.value = msg;
    setTimeout(() => {
        flashMsg.value = '';
    }, 3500);
}

function formatDate(ts) {
    return new Date(ts).toLocaleDateString('en-ZA', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

onMounted(() => {
    loadCurrentUser();
    loadScopes();
    searchAdmins();
});
</script>

<template>
    <Head title="Alert Visibility" />

    <AppLayout>
        <div class="page-root">
            <!-- PAGE HEADER -->
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Access Control</div>
                    <h1 class="page-header__title">Alert Visibility</h1>
                </div>
                <div class="page-header__right">
                    <button class="btn-primary" @click="openCompose">
                        New Claim
                    </button>
                </div>
            </div>

            <!-- STAT CARDS -->
            <div class="stat-row">
                <div class="stat-card">
                    <div class="stat-card__label">Admins With Claims</div>
                    <div class="stat-card__value">
                        {{ claimingAdminCount }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Total Claims</div>
                    <div class="stat-card__value">{{ scopes.length }}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Channel Claims</div>
                    <div class="stat-card__value stat-card__value--blue">
                        {{ channelScopeCount }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Household Claims</div>
                    <div class="stat-card__value stat-card__value--purple">
                        {{ householdScopeCount }}
                    </div>
                </div>
            </div>

            <!-- FILTER BAR -->
            <div class="filter-bar">
                <div class="filter-bar__chips">
                    <button
                        v-for="f in ['all', 'channel', 'household']"
                        :key="f"
                        @click="activeFilter = f"
                        class="chip"
                        :class="{ 'chip--active': activeFilter === f }"
                    >
                        {{
                            {
                                all: 'All',
                                channel: 'Channels',
                                household: 'Households',
                            }[f]
                        }}
                    </button>
                </div>
                <span class="filter-bar__count"
                    >{{ filteredList.length }} claim{{
                        filteredList.length !== 1 ? 's' : ''
                    }}</span
                >
            </div>

            <!-- TABLE CARD -->
            <div class="table-card">
                <div v-if="loading" class="empty-state">
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
                    <span class="mt-2 text-sm text-slate-400">Loading…</span>
                </div>

                <div v-else-if="filteredList.length === 0" class="empty-state">
                    <p class="empty-state__title">No exclusive claims yet</p>
                    <p class="empty-state__sub">
                        Every admin currently hears every alert equally. Claim a
                        channel or household to route its alerts to one admin
                        only.
                    </p>
                </div>

                <table v-else class="data-table">
                    <thead>
                        <tr>
                            <th>Admin</th>
                            <th>Scope Type</th>
                            <th>Scope</th>
                            <th>Claimed</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in filteredList" :key="s.id">
                            <td class="td-announce__title">
                                {{ adminLabel(s) }}
                            </td>
                            <td>
                                <span
                                    class="type-badge"
                                    :class="
                                        s.scope_type === 'channel'
                                            ? 'type-badge--blue'
                                            : 'type-badge--purple'
                                    "
                                >
                                    {{
                                        s.scope_type === 'channel'
                                            ? 'Channel'
                                            : 'Household'
                                    }}
                                </span>
                            </td>
                            <td>
                                <span class="audience-badge">{{
                                    labelFor(s)
                                }}</span>
                            </td>
                            <td class="td-time">
                                {{ formatDate(s.created_at) }}
                            </td>
                            <td>
                                <button
                                    v-if="s.admin_id === currentAdminId"
                                    @click="confirmDelete(s.id)"
                                    class="icon-btn icon-btn--danger"
                                    title="Release"
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
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ═══════════════ NEW CLAIM MODAL ═══════════════ -->
        <transition name="modal">
            <div
                v-if="showCompose"
                class="modal-backdrop"
                @click.self="closeCompose"
            >
                <div class="modal-sheet">
                    <div class="modal-sheet__header">
                        <div>
                            <div class="modal-sheet__title">New Claim</div>
                            <div class="modal-sheet__sub">
                                Route specific channels or households' alerts
                                exclusively to one admin
                            </div>
                        </div>
                        <button class="close-btn" @click="closeCompose">
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
                        @submit.prevent="submitScope"
                        class="modal-sheet__body"
                    >
                        <!-- Admin picker -->
                        <div class="field">
                            <label class="field__label">Admin</label>
                            <div class="search-select-wrapper">
                                <div class="search-input-row">
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="search-icon"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        stroke-width="2"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                                        />
                                    </svg>
                                    <input
                                        v-model="adminSearch"
                                        type="text"
                                        class="search-input"
                                        placeholder="Search admins…"
                                    />
                                    <span
                                        v-if="adminSearch"
                                        class="search-clear"
                                        @click="adminSearch = ''"
                                        >×</span
                                    >
                                </div>
                                <div class="search-list">
                                    <div
                                        v-if="admins.length === 0"
                                        class="search-list__empty"
                                    >
                                        No admins match "{{ adminSearch }}"
                                    </div>
                                    <div
                                        v-for="a in admins"
                                        :key="a.id"
                                        class="search-list__item"
                                        :class="{
                                            'search-list__item--active':
                                                form.admin_id === a.id,
                                        }"
                                        @click="selectAdmin(a.id)"
                                    >
                                        <span
                                            class="multi-checkbox"
                                            :class="{
                                                'multi-checkbox--checked':
                                                    form.admin_id === a.id,
                                            }"
                                        >
                                            <svg
                                                v-if="form.admin_id === a.id"
                                                xmlns="http://www.w3.org/2000/svg"
                                                class="h-3 w-3"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="3"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    d="M5 13l4 4L19 7"
                                                />
                                            </svg>
                                        </span>
                                        <span class="search-list__name">
                                            {{ a.name }}
                                            <span
                                                v-if="a.email"
                                                class="search-list__email"
                                                >{{ a.email }}</span
                                            >
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Scope type -->
                        <div class="field">
                            <label class="field__label">Scope Type</label>
                            <div class="toggle-row">
                                <button
                                    type="button"
                                    class="toggle-btn"
                                    :class="{
                                        'toggle-btn--on':
                                            form.scope_type === 'channel',
                                    }"
                                    @click="form.scope_type = 'channel'"
                                >
                                    Channels
                                </button>
                                <button
                                    type="button"
                                    class="toggle-btn"
                                    :class="{
                                        'toggle-btn--on':
                                            form.scope_type === 'household',
                                    }"
                                    @click="form.scope_type = 'household'"
                                >
                                    Households
                                </button>
                            </div>
                        </div>

                        <!-- Channel picker -->
                        <transition name="slide-down">
                            <div
                                class="field"
                                v-if="form.scope_type === 'channel'"
                            >
                                <label class="field__label">
                                    Select Channels
                                    <span
                                        class="field__count"
                                        v-if="form.channel_ids.length"
                                        >{{
                                            form.channel_ids.length
                                        }}
                                        selected</span
                                    >
                                </label>
                                <div class="search-select-wrapper">
                                    <div class="search-input-row">
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            class="search-icon"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                            stroke-width="2"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                                            />
                                        </svg>
                                        <input
                                            v-model="channelSearch"
                                            type="text"
                                            class="search-input"
                                            placeholder="Search unclaimed channels…"
                                        />
                                        <span
                                            v-if="channelSearch"
                                            class="search-clear"
                                            @click="channelSearch = ''"
                                            >×</span
                                        >
                                    </div>
                                    <div class="search-list">
                                        <div
                                            v-if="filteredChannels.length === 0"
                                            class="search-list__empty"
                                        >
                                            No unclaimed channels found
                                        </div>
                                        <div
                                            v-for="c in filteredChannels"
                                            :key="c.id"
                                            class="search-list__item"
                                            :class="{
                                                'search-list__item--active':
                                                    form.channel_ids.includes(
                                                        c.id,
                                                    ),
                                            }"
                                            @click="toggleChannelPick(c.id)"
                                        >
                                            <span
                                                class="multi-checkbox"
                                                :class="{
                                                    'multi-checkbox--checked':
                                                        form.channel_ids.includes(
                                                            c.id,
                                                        ),
                                                }"
                                            >
                                                <svg
                                                    v-if="
                                                        form.channel_ids.includes(
                                                            c.id,
                                                        )
                                                    "
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    class="h-3 w-3"
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    stroke-width="3"
                                                >
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        d="M5 13l4 4L19 7"
                                                    />
                                                </svg>
                                            </span>
                                            <span class="search-list__name">{{
                                                c.name
                                            }}</span>
                                        </div>
                                    </div>
                                </div>
                                <p
                                    v-if="claimedChannelIds.size"
                                    class="field__hint"
                                >
                                    {{ claimedChannelIds.size }} channel{{
                                        claimedChannelIds.size !== 1 ? 's' : ''
                                    }}
                                    already claimed and hidden from this list.
                                </p>
                            </div>
                        </transition>

                        <!-- Household picker -->
                        <transition name="slide-down">
                            <div
                                class="field"
                                v-if="form.scope_type === 'household'"
                            >
                                <label class="field__label">
                                    Select Households
                                    <span
                                        class="field__count"
                                        v-if="form.household_ids.length"
                                        >{{
                                            form.household_ids.length
                                        }}
                                        selected</span
                                    >
                                </label>
                                <div class="search-select-wrapper">
                                    <div class="search-input-row">
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            class="search-icon"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                            stroke-width="2"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                                            />
                                        </svg>
                                        <input
                                            v-model="householdSearch"
                                            type="text"
                                            class="search-input"
                                            placeholder="Search unclaimed households…"
                                        />
                                        <span
                                            v-if="householdSearch"
                                            class="search-clear"
                                            @click="householdSearch = ''"
                                            >×</span
                                        >
                                    </div>
                                    <div class="search-list">
                                        <div
                                            v-if="
                                                filteredHouseholds.length === 0
                                            "
                                            class="search-list__empty"
                                        >
                                            No unclaimed households found
                                        </div>
                                        <div
                                            v-for="h in filteredHouseholds"
                                            :key="h.id"
                                            class="search-list__item"
                                            :class="{
                                                'search-list__item--active':
                                                    form.household_ids.includes(
                                                        h.id,
                                                    ),
                                            }"
                                            @click="toggleHouseholdPick(h.id)"
                                        >
                                            <span
                                                class="multi-checkbox"
                                                :class="{
                                                    'multi-checkbox--checked':
                                                        form.household_ids.includes(
                                                            h.id,
                                                        ),
                                                }"
                                            >
                                                <svg
                                                    v-if="
                                                        form.household_ids.includes(
                                                            h.id,
                                                        )
                                                    "
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    class="h-3 w-3"
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    stroke-width="3"
                                                >
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        d="M5 13l4 4L19 7"
                                                    />
                                                </svg>
                                            </span>
                                            <span class="search-list__name">{{
                                                h.name
                                            }}</span>
                                        </div>
                                    </div>
                                </div>
                                <p
                                    v-if="claimedHouseholdIds.size"
                                    class="field__hint"
                                >
                                    {{ claimedHouseholdIds.size }} household{{
                                        claimedHouseholdIds.size !== 1
                                            ? 's'
                                            : ''
                                    }}
                                    already claimed and hidden from this list.
                                </p>
                            </div>
                        </transition>

                        <div class="modal-actions">
                            <button
                                type="button"
                                class="btn-ghost"
                                @click="closeCompose"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                class="btn-primary"
                                :disabled="sending || !canSubmit"
                            >
                                <svg
                                    v-if="sending"
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
                                {{ sending ? 'Claiming…' : 'Claim' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </transition>

        <!-- DELETE MODAL -->
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
                    <h2 class="confirm-modal__title">Release Claim?</h2>
                    <p class="confirm-modal__body">
                        Alerts from this channel/household will return to the
                        shared pool - every admin will hear them again, not just
                        the one who held the claim.
                    </p>
                    <div class="confirm-modal__actions">
                        <button
                            @click="showDeleteModal = false"
                            class="btn-ghost"
                        >
                            Keep it
                        </button>
                        <button @click="executeDelete" class="btn-danger">
                            Release
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- Flash toast -->
        <transition name="toast">
            <div v-if="flashMsg" class="toast">
                {{ flashMsg }}
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
    --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
    --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.08);
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
.stat-card__value--blue {
    color: #2563eb;
}
.stat-card__value--purple {
    color: #7c3aed;
}

.filter-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.filter-bar__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.filter-bar__count {
    font-size: 12px;
    font-weight: 500;
    color: #94a3b8;
}
.chip {
    padding: 5px 14px;
    border-radius: 20px;
    border: 1px solid #e4e8ef;
    background: #ffffff;
    font-size: 12px;
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
    background: #ea580c;
    color: #fff;
    border-color: #ea580c;
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
    text-align: center;
    max-width: 360px;
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
.td-time {
    color: #94a3b8;
    white-space: nowrap;
    font-size: 12px;
}

.type-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}
.type-badge--blue {
    background: #eff6ff;
    color: #2563eb;
}
.type-badge--purple {
    background: #f5f3ff;
    color: #7c3aed;
}
.audience-badge {
    background: #f1f5f9;
    color: #475569;
    border-radius: 6px;
    padding: 3px 9px;
    font-size: 11px;
    font-weight: 600;
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
}
.icon-btn--danger {
    color: #94a3b8;
}
.icon-btn--danger:hover {
    background: #fef2f2;
    color: #dc2626;
}

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
.btn-danger:hover {
    background: #b91c1c;
    transform: translateY(-1px);
}

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
    justify-content: space-between;
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
.field__count {
    font-weight: 500;
    color: #94a3b8;
}
.field__hint {
    font-size: 11px;
    color: #94a3b8;
    margin: 2px 0 0;
}

.toggle-row {
    display: flex;
    gap: 8px;
}
.toggle-btn {
    flex: 1;
    padding: 9px 12px;
    background: #f8fafc;
    border: 1.5px solid #e4e8ef;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
    transition: all 0.15s;
    font-family: inherit;
    white-space: nowrap;
}
.toggle-btn:hover {
    border-color: #cbd5e1;
}
.toggle-btn--on {
    border-color: #ea580c;
    background: #fff7ed;
    color: #ea580c;
}

.search-select-wrapper {
    border: 1.5px solid #e4e8ef;
    border-radius: 10px;
    overflow: hidden;
    background: #fff;
    transition: border-color 0.15s;
}
.search-select-wrapper:focus-within {
    border-color: #ea580c;
}
.search-input-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-bottom: 1px solid #e4e8ef;
    background: #f8fafc;
}
.search-icon {
    width: 15px;
    height: 15px;
    color: #94a3b8;
    flex-shrink: 0;
}
.search-input {
    flex: 1;
    border: none;
    background: transparent;
    font-size: 13px;
    font-family: inherit;
    color: #1a2332;
    outline: none;
}
.search-input::placeholder {
    color: #94a3b8;
}
.search-clear {
    font-size: 16px;
    color: #94a3b8;
    cursor: pointer;
    line-height: 1;
    padding: 0 2px;
    transition: color 0.15s;
}
.search-clear:hover {
    color: #64748b;
}
.search-list {
    max-height: 180px;
    overflow-y: auto;
}
.search-list__empty {
    padding: 12px 16px;
    font-size: 12px;
    color: #94a3b8;
    text-align: center;
}
.search-list__item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 14px;
    cursor: pointer;
    transition: background 0.12s;
    border-bottom: 1px solid #f1f5f9;
}
.search-list__item:last-child {
    border-bottom: none;
}
.search-list__item:hover {
    background: #f8fafc;
}
.search-list__item--active {
    background: #fff7ed;
}
.search-list__name {
    flex: 1;
    font-size: 13px;
    font-weight: 600;
    color: #1a2332;
    display: flex;
    flex-direction: column;
    gap: 1px;
}
.search-list__email {
    font-size: 11px;
    font-weight: 400;
    color: #94a3b8;
}

.multi-checkbox {
    width: 16px;
    height: 16px;
    border-radius: 4px;
    border: 2px solid #e4e8ef;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s;
    background: #fff;
    color: #fff;
}
.multi-checkbox--checked {
    background: #ea580c;
    border-color: #ea580c;
}

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

.confirm-modal {
    background: #ffffff !important;
    border-radius: 20px;
    width: 100%;
    max-width: 380px;
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
}
.confirm-modal__actions .btn-ghost {
    flex: 1;
    justify-content: center;
}
.confirm-modal__actions .btn-danger {
    flex: 1.4;
    justify-content: center;
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

@media (max-width: 768px) {
    .stat-row {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }
    .toggle-row {
        flex-wrap: wrap;
    }
    .toggle-btn {
        flex: none;
        width: 100%;
    }
}
@media (max-width: 640px) {
    .page-root {
        padding: 16px;
    }
    .data-table {
        min-width: 560px;
    }
    .table-card {
        overflow-x: auto;
    }
}
</style>

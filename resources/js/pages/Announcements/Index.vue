<script>
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { router } from '@inertiajs/vue3';
import axios from 'axios';

const authHeaders = () => ({
    Authorization: `Bearer ${localStorage.getItem('token')}`,
});

export default {
    name: 'AnnouncementsPage',
    components: { AppLayout },

    data() {
        const auth = useAuthStore();
        const role = auth.user?.role || 'user';
        return {
            role,
            patrollerSearch: '',
            patrollerSearchTimer: null,
            patrollerSearchLoading: false,
            patrollers: [],
            householdSearchLoading: false,
            householdSearchTimer: null,
            showCompose: false,
            sending: false,
            loading: true,
            flashMsg: '',
            flashType: 'success',
            activeFilter: 'all',
            announcements: [],
            clients: [],
            households: [],
            deleteTargetId: null,
            showDeleteModal: false,
            showClientAnnouncements: false,
            clientAnnouncements: [],
            clientAnnouncementsLoading: false,
            selectedClient: null,
            clientAnnouncementsFilter: 'all',
            clientAnnouncementsDateFrom: '',
            clientAnnouncementsDateTo: '',
            clientAnnouncementsSearch: '',
            allClientAnnouncements: [],
            clientSearch: '',
            householdSearch: '',
            pagination: {
                current_page: 1,
                last_page: 1,
                total: 0,
                from: 0,
                to: 0,
                links: [],
            },
            form: {
                title: '',
                message: '',
                type: 'general',
                payment_subtype: '',
                target: 'all',
                target_client_ids: [],
                target_household_ids: [],
                target_patroller_ids: [],
                app_version: '',
                playstore_url: '',
                min_version_code: null,
                force_update: false,
            },
            types: [
                {
                    value: 'general',
                    label: 'General',
                    badge: 'bg-slate-100 text-slate-600',
                },
                {
                    value: 'urgent',
                    label: 'Urgent',
                    badge: 'bg-red-50 text-red-600',
                },
                {
                    value: 'update',
                    label: 'Update',
                    badge: 'bg-emerald-50 text-emerald-700',
                },
                {
                    value: 'policy',
                    label: 'Policy',
                    badge: 'bg-amber-50 text-amber-700',
                },
                {
                    value: 'payment',
                    label: 'Payment',
                    badge: 'bg-orange-50 text-orange-700',
                },
                {
                    value: 'update_app',
                    label: 'App Update',
                    badge: 'bg-blue-50 text-blue-700',
                },
            ],
            paymentSubtypes: [
                {
                    value: 'missed_payment',
                    label: 'Missed Payment',
                    description: 'Notify about a missed payment',
                    variant: 'danger',
                },
                {
                    value: 'payment_overdue',
                    label: 'Overdue',
                    description: 'Payment is past due date',
                    variant: 'danger',
                },
                {
                    value: 'payment_reminder',
                    label: 'Reminder',
                    description: 'Upcoming payment reminder',
                    variant: 'warn',
                },
                {
                    value: 'payment_received',
                    label: 'Received',
                    description: 'Confirm payment received',
                    variant: 'success',
                },
                {
                    value: 'account_up_to_date',
                    label: 'Up to Date',
                    description: 'Account is in good standing',
                    variant: 'success',
                },
                {
                    value: 'payment_failed',
                    label: 'Failed',
                    description: 'Payment processing failed',
                    variant: 'danger',
                },
            ],
        };
    },

    mounted() {
        if (this.role !== 'admin' && this.role !== 'client') {
            router.visit('/dashboard');
            return;
        }
        this.load();
        this.loadClients();
        this.loadHouseholds();
        this.loadPatrollers();
    },

    watch: {
        householdSearch(val) {
            clearTimeout(this.householdSearchTimer);
            this.householdSearchTimer = setTimeout(() => {
                this.loadHouseholds(val);
            }, 350);
        },
        patrollerSearch(val) {
            clearTimeout(this.patrollerSearchTimer);
            this.patrollerSearchTimer = setTimeout(() => {
                this.loadPatrollers(val);
            }, 350);
        },
    },

    computed: {
        currentType() {
            return (
                this.types.find((t) => t.value === this.form.type) ||
                this.types[0]
            );
        },

        isAdmin() {
            return this.role === 'admin';
        },

        availableTypes() {
            if (this.isAdmin) return this.types;
            return this.types.filter((t) => t.value !== 'update_app');
        },

        availableTargets() {
            if (this.isAdmin) {
                return [
                    { value: 'all', label: 'All Operators' },
                    { value: 'client', label: 'Specific Client' },
                    { value: 'household', label: 'Household' },
                    { value: 'field_unit', label: 'Field Unit' },
                ];
            }
            return [
                { value: 'household', label: 'Household' },
                { value: 'field_unit', label: 'Field Unit' },
            ];
        },

        filteredList() {
            if (this.activeFilter === 'all') return this.announcements;
            return this.announcements.filter(
                (a) => a.type === this.activeFilter,
            );
        },

        totalSent() {
            return this.announcements.length;
        },
        urgentCount() {
            return this.announcements.filter((a) => a.type === 'urgent').length;
        },
        todayCount() {
            const today = new Date().toDateString();
            return this.announcements.filter(
                (a) => new Date(a.created_at).toDateString() === today,
            ).length;
        },
        paymentCount() {
            return this.announcements.filter((a) => a.type === 'payment')
                .length;
        },

        filteredClients() {
            const q = this.clientSearch.toLowerCase().trim();
            if (!q) return this.clients;
            return this.clients.filter(
                (c) =>
                    this.clientDisplayName(c).toLowerCase().includes(q) ||
                    (c.user?.email || '').toLowerCase().includes(q),
            );
        },

        filteredHouseholds() {
            return this.households;
        },

        filteredPatrollers() {
            return this.patrollers;
        },

        allClientsSelected() {
            return (
                this.filteredClients.length > 0 &&
                this.filteredClients.every((c) =>
                    this.form.target_client_ids.includes(c.id),
                )
            );
        },
        someClientsSelected() {
            return (
                this.form.target_client_ids.length > 0 &&
                !this.allClientsSelected
            );
        },
        allHouseholdsSelected() {
            return (
                this.filteredHouseholds.length > 0 &&
                this.filteredHouseholds.every((h) =>
                    this.form.target_household_ids.includes(h.id),
                )
            );
        },
        someHouseholdsSelected() {
            return (
                this.form.target_household_ids.length > 0 &&
                !this.allHouseholdsSelected
            );
        },
        allPatrollersSelected() {
            return (
                this.filteredPatrollers.length > 0 &&
                this.filteredPatrollers.every((p) =>
                    this.form.target_patroller_ids.includes(p.id),
                )
            );
        },
        somePatrollersSelected() {
            return (
                this.form.target_patroller_ids.length > 0 &&
                !this.allPatrollersSelected
            );
        },

        isAppUpdateValid() {
            if (this.form.type !== 'update_app') return true;
            const base =
                this.form.title.trim() !== '' &&
                this.form.message.trim() !== '' &&
                this.form.app_version.trim() !== '' &&
                this.form.playstore_url.trim() !== '';
            if (this.form.force_update) {
                return (
                    base &&
                    !!this.form.min_version_code &&
                    this.form.min_version_code > 0
                );
            }
            return base;
        },

        appUpdateMissingFields() {
            const missing = [];
            if (!this.form.app_version.trim()) missing.push('Version Number');
            if (!this.form.playstore_url.trim()) missing.push('Play Store URL');
            if (!this.form.title.trim()) missing.push('Title');
            if (!this.form.message.trim()) missing.push('Message');
            if (this.form.force_update && !this.form.min_version_code)
                missing.push('Min Version Code');
            return missing;
        },

        clientsWithAnnouncements() {
            const map = {};
            this.allClientAnnouncements.forEach((a) => {
                if (!a.client_id) return;
                if (!map[a.client_id]) {
                    map[a.client_id] = {
                        client_id: a.client_id,
                        name:
                            a.client?.user?.organisation_name ||
                            a.client?.user?.name ||
                            `Client #${a.client_id}`,
                        email: a.client?.user?.email || '',
                        count: 0,
                        last_at: null,
                    };
                }
                map[a.client_id].count++;
                if (
                    !map[a.client_id].last_at ||
                    new Date(a.created_at) > new Date(map[a.client_id].last_at)
                ) {
                    map[a.client_id].last_at = a.created_at;
                }
            });
            return Object.values(map);
        },

        selectedClientAnnouncements() {
            if (!this.selectedClient) return [];
            return this.allClientAnnouncements.filter((a) => {
                if (a.client_id !== this.selectedClient.client_id) return false;
                if (
                    this.clientAnnouncementsFilter !== 'all' &&
                    a.type !== this.clientAnnouncementsFilter
                )
                    return false;
                if (
                    this.clientAnnouncementsDateFrom &&
                    new Date(a.created_at) <
                        new Date(this.clientAnnouncementsDateFrom)
                )
                    return false;
                if (
                    this.clientAnnouncementsDateTo &&
                    new Date(a.created_at) >
                        new Date(this.clientAnnouncementsDateTo + 'T23:59:59')
                )
                    return false;
                if (this.clientAnnouncementsSearch) {
                    const q = this.clientAnnouncementsSearch.toLowerCase();
                    if (
                        !a.title.toLowerCase().includes(q) &&
                        !a.message.toLowerCase().includes(q)
                    )
                        return false;
                }
                return true;
            });
        },
    },

    methods: {
        toggleClient(id) {
            const idx = this.form.target_client_ids.indexOf(id);
            if (idx === -1) this.form.target_client_ids.push(id);
            else this.form.target_client_ids.splice(idx, 1);
        },

        toggleAllClients() {
            if (this.allClientsSelected) {
                const visibleIds = this.filteredClients.map((c) => c.id);
                this.form.target_client_ids =
                    this.form.target_client_ids.filter(
                        (id) => !visibleIds.includes(id),
                    );
            } else {
                const visibleIds = this.filteredClients.map((c) => c.id);
                this.form.target_client_ids = [
                    ...new Set([...this.form.target_client_ids, ...visibleIds]),
                ];
            }
        },

        toggleHousehold(id) {
            const idx = this.form.target_household_ids.indexOf(id);
            if (idx === -1) this.form.target_household_ids.push(id);
            else this.form.target_household_ids.splice(idx, 1);
        },

        toggleAllHouseholds() {
            if (this.allHouseholdsSelected) {
                const visibleIds = this.filteredHouseholds.map((h) => h.id);
                this.form.target_household_ids =
                    this.form.target_household_ids.filter(
                        (id) => !visibleIds.includes(id),
                    );
            } else {
                const visibleIds = this.filteredHouseholds.map((h) => h.id);
                this.form.target_household_ids = [
                    ...new Set([
                        ...this.form.target_household_ids,
                        ...visibleIds,
                    ]),
                ];
            }
        },

        togglePatroller(id) {
            const idx = this.form.target_patroller_ids.indexOf(id);
            if (idx === -1) this.form.target_patroller_ids.push(id);
            else this.form.target_patroller_ids.splice(idx, 1);
        },

        toggleAllPatrollers() {
            if (this.allPatrollersSelected) {
                const visibleIds = this.filteredPatrollers.map((p) => p.id);
                this.form.target_patroller_ids =
                    this.form.target_patroller_ids.filter(
                        (id) => !visibleIds.includes(id),
                    );
            } else {
                const visibleIds = this.filteredPatrollers.map((p) => p.id);
                this.form.target_patroller_ids = [
                    ...new Set([
                        ...this.form.target_patroller_ids,
                        ...visibleIds,
                    ]),
                ];
            }
        },

        openCompose() {
            this.form.target = this.isAdmin ? 'all' : 'household';
            this.showCompose = true;
        },

        async openClientAnnouncements() {
            this.showClientAnnouncements = true;
            this.selectedClient = null;
            this.clientAnnouncementsFilter = 'all';
            this.clientAnnouncementsDateFrom = '';
            this.clientAnnouncementsDateTo = '';
            this.clientAnnouncementsSearch = '';
            if (this.allClientAnnouncements.length === 0) {
                this.clientAnnouncementsLoading = true;
                try {
                    const { data } = await axios.get(
                        `${import.meta.env.VITE_APP_URL}/api/announcements?by=clients`,
                        { headers: authHeaders() },
                    );
                    this.allClientAnnouncements = data.data || data;
                } catch (e) {
                    console.error(e);
                } finally {
                    this.clientAnnouncementsLoading = false;
                }
            }
        },

        selectClient(c) {
            this.selectedClient = c;
            this.clientAnnouncementsFilter = 'all';
            this.clientAnnouncementsDateFrom = '';
            this.clientAnnouncementsDateTo = '';
            this.clientAnnouncementsSearch = '';
        },

        clearClientSelection() {
            this.selectedClient = null;
        },

        async load(url) {
            this.loading = true;
            try {
                const endpoint =
                    url || `${import.meta.env.VITE_APP_URL}/api/announcements`;
                const { data } = await axios.get(endpoint, {
                    headers: authHeaders(),
                });
                this.announcements = data.data || data;
                if (data.links) {
                    this.pagination = {
                        current_page: data.current_page,
                        last_page: data.last_page,
                        total: data.total,
                        from: data.from,
                        to: data.to,
                        links: data.links,
                    };
                }
            } catch (e) {
                console.error(e);
            } finally {
                this.loading = false;
            }
        },

        async loadClients() {
            try {
                const { data } = await axios.get(
                    `${import.meta.env.VITE_APP_URL}/api/clients/list`,
                    { headers: authHeaders() },
                );
                this.clients = Array.isArray(data) ? data : data.data || [];
            } catch (e) {
                console.error('clients:', e);
            }
        },

        async loadHouseholds(search = '') {
            this.householdSearchLoading = true;
            try {
                const params = {};
                if (search) params.search = search;
                if (!this.isAdmin) params.scoped = 1; // tells Laravel to scope to client
                const { data } = await axios.get(
                    `${import.meta.env.VITE_APP_URL}/api/household/list`,
                    { headers: authHeaders(), params },
                );
                this.households = Array.isArray(data) ? data : data.data || [];
            } catch (e) {
                console.error('[Households]', e?.response?.status, e?.message);
            } finally {
                this.householdSearchLoading = false;
            }
        },

        async loadPatrollers(search = '') {
            this.patrollerSearchLoading = true;
            try {
                const params = {};
                if (search) params.search = search;
                if (!this.isAdmin) params.scoped = 1;
                const { data } = await axios.get(
                    `${import.meta.env.VITE_APP_URL}/api/patrollers/list`,
                    { headers: authHeaders(), params },
                );
                this.patrollers = Array.isArray(data) ? data : data.data || [];
            } catch (e) {
                console.error('[Patrollers]', e);
            } finally {
                this.patrollerSearchLoading = false;
            }
        },

        async send() {
            if (!this.form.title || !this.form.message) return;
            if (!this.isAppUpdateValid) return;
            this.sending = true;
            try {
                await axios.post(
                    `${import.meta.env.VITE_APP_URL}/api/announcements/send`,
                    this.form,
                    { headers: authHeaders() },
                );
                this.flash('Announcement sent successfully!');
                this.closeCompose();
                this.load();
            } catch (e) {
                alert(e.response?.data?.message || 'Failed to send.');
            } finally {
                this.sending = false;
            }
        },

        confirmDelete(id) {
            this.deleteTargetId = id;
            this.showDeleteModal = true;
        },

        async executeDelete() {
            try {
                await axios.delete(
                    `${import.meta.env.VITE_APP_URL}/api/announcements/${this.deleteTargetId}`,
                    { headers: authHeaders() },
                );
                this.announcements = this.announcements.filter(
                    (a) => a.id !== this.deleteTargetId,
                );
                this.flash('Announcement deleted.');
            } catch (e) {
                console.error(e);
            } finally {
                this.showDeleteModal = false;
                this.deleteTargetId = null;
            }
        },

        closeCompose() {
            this.showCompose = false;
            this.clientSearch = '';
            this.householdSearch = '';
            this.patrollerSearch = '';
            this.form = {
                title: '',
                message: '',
                type: 'general',
                payment_subtype: '',
                target: 'all',
                target_client_ids: [],
                target_household_ids: [],
                target_patroller_ids: [],
                app_version: '',
                playstore_url: '',
                min_version_code: null,
                force_update: false,
            };
        },

        flash(msg) {
            this.flashMsg = msg;
            setTimeout(() => {
                this.flashMsg = '';
            }, 3500);
        },

        clientDisplayName(c) {
            return (
                c.user?.organisation_name || c.user?.name || `Client #${c.id}`
            );
        },

        householdDisplayName(h) {
            return h.user?.name || h.name || `Personnel #${h.id}`;
        },

        typeInfo(type) {
            return this.types.find((t) => t.value === type) || this.types[0];
        },

        paymentSubtypeInfo(subtype) {
            return this.paymentSubtypes.find((p) => p.value === subtype);
        },

        targetLabel(a) {
            if (a.target === 'client') {
                const ids =
                    a.target_client_ids ||
                    (a.target_client_id ? [a.target_client_id] : []);
                if (!ids.length) return 'Clients';
                if (ids.length === 1) {
                    const c = this.clients.find((c) => c.id == ids[0]);
                    return c ? this.clientDisplayName(c) : `Client #${ids[0]}`;
                }
                return `${ids.length} Clients`;
            }
            if (a.target === 'household') {
                const ids =
                    a.target_household_ids ||
                    (a.target_household_id ? [a.target_household_id] : []);
                if (!ids.length) return 'Households';
                if (ids.length === 1) {
                    const h = this.households.find((h) => h.id == ids[0]);
                    return h
                        ? this.householdDisplayName(h)
                        : `Household #${ids[0]}`;
                }
                return `${ids.length} Households`;
            }
            if (a.target === 'field_unit') {
                const ids = a.target_patroller_ids || [];
                if (!ids.length) return 'Field Units';
                if (ids.length === 1) return `Field Unit #${ids[0]}`;
                return `${ids.length} Field Units`;
            }
            if (a.target === 'email') return a.target_email || 'Email';
            return 'All Operators';
        },

        timeAgo(ts) {
            if (!ts) return '—';
            const d = new Date(ts);
            const diff = Math.floor((Date.now() - d) / 1000);
            if (diff < 60) return 'Just now';
            if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
            if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
            return d.toLocaleDateString('en-ZA', {
                day: 'numeric',
                month: 'short',
                year: 'numeric',
            });
        },
    },
};
</script>

<template>
    <Head title="Announcements" />

    <AppLayout>
        <div class="page-root">
            <!-- PAGE HEADER -->
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Communication</div>
                    <h1 class="page-header__title">Announcements</h1>
                </div>
                <div class="page-header__right">
                    <button
                        v-if="isAdmin"
                        class="btn-secondary"
                        @click="openClientAnnouncements"
                    >
                        Client Announcements
                    </button>
                    <button class="btn-primary" @click="openCompose">
                        New Announcement
                    </button>
                </div>
            </div>

            <!-- STAT CARDS -->
            <div class="stat-row">
                <div class="stat-card">
                    <div class="stat-card__label">Total Sent</div>
                    <div class="stat-card__value">{{ totalSent }}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Urgent</div>
                    <div class="stat-card__value stat-card__value--red">
                        {{ urgentCount }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Payments</div>
                    <div class="stat-card__value stat-card__value--orange">
                        {{ paymentCount }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Sent Today</div>
                    <div class="stat-card__value stat-card__value--green">
                        {{ todayCount }}
                    </div>
                </div>
            </div>

            <!-- FILTER BAR -->
            <div class="filter-bar">
                <div class="filter-bar__chips">
                    <button
                        v-for="f in [
                            'all',
                            'general',
                            'urgent',
                            'update',
                            'policy',
                            'payment',
                            'update_app',
                        ]"
                        :key="f"
                        @click="activeFilter = f"
                        class="chip"
                        :class="{ 'chip--active': activeFilter === f }"
                    >
                        {{
                            {
                                all: 'All',
                                general: 'General',
                                urgent: 'Urgent',
                                update: 'Update',
                                policy: 'Policy',
                                payment: 'Payment',
                                update_app: 'App Update',
                            }[f]
                        }}
                    </button>
                </div>
                <span class="filter-bar__count"
                    >{{ filteredList.length }} result{{
                        filteredList.length !== 1 ? 's' : ''
                    }}</span
                >
            </div>

            <!-- TABLE CARD -->
            <div class="table-card">
                <!-- Loading -->
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
                    <span class="mt-2 text-sm text-slate-400"
                        >Loading announcements…</span
                    >
                </div>

                <!-- Empty -->
                <div v-else-if="filteredList.length === 0" class="empty-state">
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
                                d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"
                            />
                        </svg>
                    </div>
                    <p class="empty-state__title">No announcements yet</p>
                    <p class="empty-state__sub">
                        Hit "New Announcement" to broadcast your first message
                    </p>
                </div>

                <!-- Table -->
                <table v-else class="data-table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Announcement</th>
                            <th>Audience</th>
                            <th>Sender</th>
                            <th>Sent</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in filteredList" :key="a.id">
                            <td>
                                <div
                                    style="
                                        display: flex;
                                        flex-direction: column;
                                        gap: 4px;
                                    "
                                >
                                    <span
                                        class="type-badge"
                                        :class="typeInfo(a.type).badge"
                                    >
                                        {{ typeInfo(a.type).label }}
                                    </span>
                                    <span
                                        v-if="
                                            a.type === 'payment' &&
                                            a.payment_subtype
                                        "
                                        class="payment-sub-badge"
                                    >
                                        {{
                                            paymentSubtypeInfo(
                                                a.payment_subtype,
                                            )?.label || a.payment_subtype
                                        }}
                                    </span>
                                </div>
                            </td>
                            <td class="td-announce">
                                <div class="td-announce__title">
                                    {{ a.title }}
                                </div>
                                <div class="td-announce__sub">
                                    {{ a.message }}
                                </div>
                            </td>
                            <td>
                                <span class="audience-badge">{{
                                    targetLabel(a)
                                }}</span>
                            </td>
                            <td class="td-muted">
                                {{ a.sender?.name || 'Admin' }}
                            </td>
                            <td class="td-time">
                                {{ timeAgo(a.sent_at || a.created_at) }}
                            </td>
                            <td>
                                <button
                                    @click="confirmDelete(a.id)"
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
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div
                    class="pagination-bar"
                    v-if="!loading && filteredList.length > 0"
                >
                    <span class="pagination-bar__info">
                        Showing {{ pagination.from || 0 }}–{{
                            pagination.to || 0
                        }}
                        of {{ pagination.total || filteredList.length }}
                    </span>
                    <div class="pagination-bar__pages">
                        <template
                            v-for="(link, i) in pagination.links"
                            :key="i"
                        >
                            <button
                                v-if="link.url"
                                @click="load(link.url)"
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

        <!-- ═══════════════ COMPOSE MODAL ═══════════════ -->
        <transition name="modal">
            <div
                v-if="showCompose"
                class="modal-backdrop"
                @click.self="closeCompose"
            >
                <div class="modal-sheet">
                    <!-- Header -->
                    <div class="modal-sheet__header">
                        <div class="modal-sheet__header-left">
                            <div>
                                <div class="modal-sheet__title">
                                    New Announcement
                                </div>
                                <div class="modal-sheet__sub">
                                    Delivered instantly to connected operators
                                </div>
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

                    <!-- Body -->
                    <form @submit.prevent="send" class="modal-sheet__body">
                        <!-- Type dropdown -->
                        <div class="field">
                            <label class="field__label"
                                >Announcement Type</label
                            >
                            <div class="select-wrapper">
                                <select
                                    v-model="form.type"
                                    class="field__select"
                                >
                                    <option
                                        v-for="t in availableTypes"
                                        :key="t.value"
                                        :value="t.value"
                                    >
                                        {{ t.label }}
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

                        <!-- Payment subtype panel -->
                        <transition name="slide-down">
                            <div
                                v-if="form.type === 'payment'"
                                class="payment-panel"
                            >
                                <label
                                    class="field__label"
                                    style="margin-bottom: 8px"
                                    >Payment Status</label
                                >
                                <div class="payment-subtypes">
                                    <button
                                        v-for="sub in paymentSubtypes"
                                        :key="sub.value"
                                        type="button"
                                        class="subtype-btn"
                                        :class="[
                                            'subtype-btn--' + sub.variant,
                                            {
                                                'subtype-btn--active':
                                                    form.payment_subtype ===
                                                    sub.value,
                                            },
                                        ]"
                                        @click="
                                            form.payment_subtype = sub.value
                                        "
                                    >
                                        {{ sub.label }}
                                    </button>
                                </div>
                            </div>
                        </transition>

                        <!-- App Update special panel -->
                        <transition name="slide-down">
                            <div
                                v-if="form.type === 'update_app'"
                                class="app-update-panel"
                            >
                                <div class="app-update-panel__header">
                                    <div>
                                        <div class="app-update-panel__title">
                                            App Update Prompt
                                        </div>
                                        <div class="app-update-panel__sub">
                                            Users will see a full-screen prompt
                                            to update or dismiss
                                        </div>
                                    </div>
                                </div>
                                <div class="field" style="margin-bottom: 14px">
                                    <label class="field__label">
                                        New Version Number
                                        <span class="field__hint"
                                            >e.g. 2.4.1</span
                                        >
                                    </label>
                                    <input
                                        v-model="form.app_version"
                                        type="text"
                                        class="field__input"
                                        :class="{
                                            'field__input--error':
                                                form.type === 'update_app' &&
                                                !form.app_version.trim(),
                                        }"
                                        placeholder="e.g. 2.4.1"
                                    />
                                    <span
                                        v-if="
                                            form.type === 'update_app' &&
                                            !form.app_version.trim()
                                        "
                                        class="field__error"
                                        >Version number is required for App
                                        Updates</span
                                    >
                                </div>
                                <div class="field" style="margin-bottom: 0">
                                    <label class="field__label"
                                        >Google Play Store URL</label
                                    >
                                    <input
                                        v-model="form.playstore_url"
                                        type="url"
                                        class="field__input"
                                        :class="{
                                            'field__input--error':
                                                form.type === 'update_app' &&
                                                !form.playstore_url.trim(),
                                        }"
                                        placeholder="https://play.google.com/store/apps/details?id=..."
                                    />
                                    <span
                                        v-if="
                                            form.type === 'update_app' &&
                                            !form.playstore_url.trim()
                                        "
                                        class="field__error"
                                        >Play Store URL is required for App
                                        Updates</span
                                    >
                                </div>

                                <!-- Min Version Code -->
                                <div class="field" style="margin-bottom: 14px">
                                    <label class="field__label">
                                        Min Version Code
                                        <span class="field__hint"
                                            >Android versionCode integer e.g.
                                            9</span
                                        >
                                    </label>
                                    <input
                                        v-model.number="form.min_version_code"
                                        type="number"
                                        min="1"
                                        class="field__input"
                                        placeholder="e.g. 9"
                                    />
                                </div>

                                <!-- Force Update toggle -->
                                <div class="field" style="margin-bottom: 0">
                                    <label class="field__label"
                                        >Force Update</label
                                    >
                                    <div class="toggle-row">
                                        <button
                                            type="button"
                                            class="toggle-btn"
                                            :class="{
                                                'toggle-btn--on':
                                                    form.force_update,
                                            }"
                                            @click="
                                                form.force_update =
                                                    !form.force_update
                                            "
                                        >
                                            {{
                                                form.force_update
                                                    ? '🔒 Forced - users cannot dismiss'
                                                    : 'Soft - dismissible'
                                            }}
                                        </button>
                                    </div>
                                    <span
                                        class="field__hint"
                                        style="
                                            margin-top: 6px;
                                            display: block;
                                            color: #94a3b8;
                                            font-size: 12px;
                                        "
                                    >
                                        When forced, users on versions below the
                                        min version code will see a blocking
                                        screen with no dismiss option.
                                    </span>
                                </div>
                                <!-- On-device preview -->
                                <div class="update-screen-preview">
                                    <div class="usp__label">
                                        On-Device Preview
                                    </div>
                                    <div class="usp__phone">
                                        <div class="usp__screen">
                                            <div class="usp__app-icon">
                                                <svg
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    class="h-8 w-8 text-white"
                                                    fill="none"
                                                    viewBox="0 0 24 24"
                                                    stroke="currentColor"
                                                    stroke-width="1.5"
                                                >
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"
                                                    />
                                                </svg>
                                            </div>
                                            <div class="usp__heading">
                                                Update Available
                                            </div>
                                            <div
                                                class="usp__version"
                                                v-if="form.app_version"
                                            >
                                                Version {{ form.app_version }}
                                            </div>
                                            <div class="usp__message">
                                                {{
                                                    form.message ||
                                                    'A new version of the app is available with improvements and bug fixes.'
                                                }}
                                            </div>
                                            <div class="usp__actions">
                                                <button
                                                    type="button"
                                                    class="usp__dismiss"
                                                >
                                                    Remind me later
                                                </button>
                                                <button
                                                    type="button"
                                                    class="usp__update"
                                                >
                                                    <svg
                                                        xmlns="http://www.w3.org/2000/svg"
                                                        class="h-3.5 w-3.5"
                                                        viewBox="0 0 24 24"
                                                        fill="currentColor"
                                                    >
                                                        <path
                                                            d="M3.18 23.76C2.2 24.3 1 23.6 1 22.48V1.52C1 .4 2.2-.3 3.18.24l18.16 10.5a1.5 1.5 0 010 2.52L3.18 23.76z"
                                                        />
                                                    </svg>
                                                    Update Now
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Validation warning banner -->
                                <div
                                    v-if="!isAppUpdateValid"
                                    class="app-update-warning"
                                >
                                    <span
                                        >Fill in all required fields before
                                        sending:
                                        <strong>{{
                                            appUpdateMissingFields.join(', ')
                                        }}</strong></span
                                    >
                                </div>
                            </div>
                        </transition>

                        <!-- Title -->
                        <div class="field">
                            <label class="field__label">
                                Title
                                <span class="field__count"
                                    >{{ form.title.length }}/100</span
                                >
                            </label>
                            <input
                                v-model="form.title"
                                type="text"
                                class="field__input"
                                placeholder="e.g. System maintenance tonight at 22:00"
                                maxlength="100"
                                required
                            />
                        </div>

                        <!-- Message -->
                        <div class="field">
                            <label class="field__label">
                                Message
                                <span class="field__count"
                                    >{{ form.message.length }}/1000</span
                                >
                            </label>
                            <textarea
                                v-model="form.message"
                                class="field__input field__textarea"
                                placeholder="Write your announcement…"
                                maxlength="1000"
                                rows="4"
                                required
                            ></textarea>
                        </div>

                        <!-- Audience — always visible -->
                        <div class="field">
                            <label class="field__label">Send To</label>
                            <div class="toggle-row">
                                <button
                                    type="button"
                                    v-for="tg in availableTargets"
                                    :key="tg.value"
                                    class="toggle-btn"
                                    :class="{
                                        'toggle-btn--on':
                                            form.target === tg.value,
                                    }"
                                    @click="form.target = tg.value"
                                >
                                    {{ tg.label }}
                                </button>
                            </div>
                        </div>

                        <!-- Client picker - multi select -->
                        <transition name="slide-down">
                            <div class="field" v-if="form.target === 'client'">
                                <label class="field__label">
                                    Select Clients
                                    <span
                                        class="field__count"
                                        v-if="form.target_client_ids.length"
                                        >{{
                                            form.target_client_ids.length
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
                                            v-model="clientSearch"
                                            type="text"
                                            class="search-input"
                                            placeholder="Search by name or email…"
                                        />
                                        <span
                                            v-if="clientSearch"
                                            class="search-clear"
                                            @click="clientSearch = ''"
                                            >×</span
                                        >
                                    </div>
                                    <div
                                        class="select-all-row"
                                        @click="toggleAllClients"
                                    >
                                        <span
                                            class="multi-checkbox"
                                            :class="{
                                                'multi-checkbox--checked':
                                                    allClientsSelected,
                                                'multi-checkbox--indeterminate':
                                                    someClientsSelected,
                                            }"
                                        >
                                            <svg
                                                v-if="allClientsSelected"
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
                                            <svg
                                                v-else-if="someClientsSelected"
                                                xmlns="http://www.w3.org/2000/svg"
                                                class="h-3 w-3"
                                                viewBox="0 0 24 24"
                                                fill="currentColor"
                                            >
                                                <rect
                                                    x="4"
                                                    y="11"
                                                    width="16"
                                                    height="2"
                                                    rx="1"
                                                />
                                            </svg>
                                        </span>
                                        <span class="select-all-label">{{
                                            allClientsSelected
                                                ? 'Deselect All'
                                                : 'Select All'
                                        }}</span>
                                    </div>
                                    <div class="search-list">
                                        <div
                                            v-if="filteredClients.length === 0"
                                            class="search-list__empty"
                                        >
                                            No clients match "{{
                                                clientSearch
                                            }}"
                                        </div>
                                        <div
                                            v-for="c in filteredClients"
                                            :key="c.id"
                                            class="search-list__item"
                                            :class="{
                                                'search-list__item--active':
                                                    form.target_client_ids.includes(
                                                        c.id,
                                                    ),
                                            }"
                                            @click="toggleClient(c.id)"
                                        >
                                            <span
                                                class="multi-checkbox"
                                                :class="{
                                                    'multi-checkbox--checked':
                                                        form.target_client_ids.includes(
                                                            c.id,
                                                        ),
                                                }"
                                            >
                                                <svg
                                                    v-if="
                                                        form.target_client_ids.includes(
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
                                            <span class="search-list__name">
                                                {{ clientDisplayName(c) }}
                                                <span
                                                    v-if="c.user?.email"
                                                    class="search-list__email"
                                                    >{{ c.user.email }}</span
                                                >
                                            </span>
                                        </div>
                                    </div>
                                    <span
                                        v-if="clients.length === 0"
                                        class="field__hint"
                                        style="
                                            color: #94a3b8;
                                            font-size: 12px;
                                            padding: 8px 12px;
                                            display: block;
                                        "
                                        >No clients loaded</span
                                    >
                                </div>
                            </div>
                        </transition>

                        <!-- Household picker - multi select -->
                        <transition name="slide-down">
                            <div
                                class="field"
                                v-if="form.target === 'household'"
                            >
                                <label class="field__label">
                                    Select Personnel
                                    <span
                                        class="field__count"
                                        v-if="form.target_household_ids.length"
                                        >{{
                                            form.target_household_ids.length
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
                                            placeholder="Search by name or email…"
                                        />
                                        <span
                                            v-if="householdSearch"
                                            class="search-clear"
                                            @click="householdSearch = ''"
                                            >×</span
                                        >
                                    </div>
                                    <div
                                        class="select-all-row"
                                        @click="toggleAllHouseholds"
                                    >
                                        <span
                                            class="multi-checkbox"
                                            :class="{
                                                'multi-checkbox--checked':
                                                    allHouseholdsSelected,
                                                'multi-checkbox--indeterminate':
                                                    someHouseholdsSelected,
                                            }"
                                        >
                                            <svg
                                                v-if="allHouseholdsSelected"
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
                                            <svg
                                                v-else-if="
                                                    someHouseholdsSelected
                                                "
                                                xmlns="http://www.w3.org/2000/svg"
                                                class="h-3 w-3"
                                                viewBox="0 0 24 24"
                                                fill="currentColor"
                                            >
                                                <rect
                                                    x="4"
                                                    y="11"
                                                    width="16"
                                                    height="2"
                                                    rx="1"
                                                />
                                            </svg>
                                        </span>
                                        <span class="select-all-label">{{
                                            allHouseholdsSelected
                                                ? 'Deselect All'
                                                : 'Select All'
                                        }}</span>
                                    </div>
                                    <div class="search-list">
                                        <div
                                            v-if="
                                                filteredHouseholds.length ===
                                                    0 && householdSearch
                                            "
                                            class="search-list__empty"
                                        >
                                            No results for "{{
                                                householdSearch
                                            }}"
                                        </div>
                                        <div
                                            v-else-if="
                                                filteredHouseholds.length === 0
                                            "
                                            class="search-list__empty"
                                        >
                                            No employees found — check
                                            <code
                                                style="
                                                    font-size: 11px;
                                                    background: #f1f5f9;
                                                    padding: 1px 5px;
                                                    border-radius: 4px;
                                                "
                                                >/api/employees</code
                                            >
                                        </div>
                                        <div
                                            v-for="h in filteredHouseholds"
                                            :key="h.id"
                                            class="search-list__item"
                                            :class="{
                                                'search-list__item--active':
                                                    form.target_household_ids.includes(
                                                        h.id,
                                                    ),
                                            }"
                                            @click="toggleHousehold(h.id)"
                                        >
                                            <span
                                                class="multi-checkbox"
                                                :class="{
                                                    'multi-checkbox--checked':
                                                        form.target_household_ids.includes(
                                                            h.id,
                                                        ),
                                                }"
                                            >
                                                <svg
                                                    v-if="
                                                        form.target_household_ids.includes(
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
                                            <span class="search-list__name">
                                                {{ householdDisplayName(h) }}
                                                <span
                                                    class="search-list__email"
                                                >
                                                    {{ h.user?.email || '' }}
                                                    <template
                                                        v-if="
                                                            h.user?.email &&
                                                            h.client?.user
                                                                ?.organisation_name
                                                        "
                                                    >
                                                        ·
                                                    </template>
                                                    {{
                                                        h.client?.user
                                                            ?.organisation_name ||
                                                        h.client?.user?.name ||
                                                        ''
                                                    }}
                                                </span>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </transition>

                        <transition name="slide-down">
                            <div
                                class="field"
                                v-if="form.target === 'field_unit'"
                            >
                                <label class="field__label">
                                    Select Patrollers
                                    <span
                                        class="field__count"
                                        v-if="form.target_patroller_ids.length"
                                    >
                                        {{ form.target_patroller_ids.length }}
                                        selected
                                    </span>
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
                                            v-model="patrollerSearch"
                                            type="text"
                                            class="search-input"
                                            placeholder="Search by name or email…"
                                        />
                                        <span
                                            v-if="patrollerSearch"
                                            class="search-clear"
                                            @click="patrollerSearch = ''"
                                            >×</span
                                        >
                                    </div>

                                    <div
                                        class="select-all-row"
                                        @click="toggleAllPatrollers"
                                    >
                                        <span
                                            class="multi-checkbox"
                                            :class="{
                                                'multi-checkbox--checked':
                                                    allPatrollersSelected,
                                                'multi-checkbox--indeterminate':
                                                    somePatrollersSelected,
                                            }"
                                        >
                                            <svg
                                                v-if="allPatrollersSelected"
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
                                            <svg
                                                v-else-if="
                                                    somePatrollersSelected
                                                "
                                                xmlns="http://www.w3.org/2000/svg"
                                                class="h-3 w-3"
                                                viewBox="0 0 24 24"
                                                fill="currentColor"
                                            >
                                                <rect
                                                    x="4"
                                                    y="11"
                                                    width="16"
                                                    height="2"
                                                    rx="1"
                                                />
                                            </svg>
                                        </span>
                                        <span class="select-all-label">{{
                                            allPatrollersSelected
                                                ? 'Deselect All'
                                                : 'Select All'
                                        }}</span>
                                    </div>

                                    <div class="search-list">
                                        <div
                                            v-if="
                                                filteredPatrollers.length === 0
                                            "
                                            class="search-list__empty"
                                        >
                                            No patrollers found
                                        </div>
                                        <div
                                            v-for="p in filteredPatrollers"
                                            :key="p.id"
                                            class="search-list__item"
                                            :class="{
                                                'search-list__item--active':
                                                    form.target_patroller_ids.includes(
                                                        p.id,
                                                    ),
                                            }"
                                            @click="togglePatroller(p.id)"
                                        >
                                            <span
                                                class="multi-checkbox"
                                                :class="{
                                                    'multi-checkbox--checked':
                                                        form.target_patroller_ids.includes(
                                                            p.id,
                                                        ),
                                                }"
                                            >
                                                <svg
                                                    v-if="
                                                        form.target_patroller_ids.includes(
                                                            p.id,
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
                                            <span class="search-list__name">
                                                {{
                                                    p.user?.name ||
                                                    `Patroller #${p.id}`
                                                }}
                                                <span
                                                    class="search-list__email"
                                                    >{{
                                                        p.user?.email || ''
                                                    }}</span
                                                >
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </transition>

                        <!-- Actions -->
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
                                :disabled="sending || !isAppUpdateValid"
                                :title="
                                    !isAppUpdateValid
                                        ? 'Fill in all App Update fields before sending'
                                        : ''
                                "
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
                                {{ sending ? 'Sending…' : 'Send Announcement' }}
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
                    <h2 class="confirm-modal__title">Delete Announcement?</h2>
                    <p class="confirm-modal__body">
                        This announcement will be permanently removed. Operators
                        who haven't read it yet will lose access.
                    </p>
                    <div class="confirm-modal__actions">
                        <button
                            @click="showDeleteModal = false"
                            class="btn-ghost"
                        >
                            Keep it
                        </button>
                        <button @click="executeDelete" class="btn-danger">
                            Delete
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- ═══════════════ CLIENT ANNOUNCEMENTS MODAL ═══════════════ -->
        <transition name="modal">
            <div
                v-if="showClientAnnouncements"
                class="modal-backdrop"
                @click.self="showClientAnnouncements = false"
            >
                <div class="ca-modal">
                    <!-- Header -->
                    <div class="ca-modal__header">
                        <div class="ca-modal__header-left">
                            <button
                                v-if="selectedClient"
                                class="ca-back-btn"
                                @click="clearClientSelection"
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-4 w-4"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    stroke-width="2.5"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M15 19l-7-7 7-7"
                                    />
                                </svg>
                                All Clients
                            </button>
                            <div>
                                <div class="ca-modal__title">
                                    {{
                                        selectedClient
                                            ? selectedClient.name
                                            : 'Client Announcements'
                                    }}
                                </div>
                                <div class="ca-modal__sub">
                                    {{
                                        selectedClient
                                            ? `${selectedClientAnnouncements.length} announcement${selectedClientAnnouncements.length !== 1 ? 's' : ''}`
                                            : `${clientsWithAnnouncements.length} client${clientsWithAnnouncements.length !== 1 ? 's' : ''} with announcements`
                                    }}
                                </div>
                            </div>
                        </div>
                        <button
                            class="close-btn"
                            @click="showClientAnnouncements = false"
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

                    <!-- Filters bar (shown when a client is selected) -->
                    <div v-if="selectedClient" class="ca-filters">
                        <input
                            v-model="clientAnnouncementsSearch"
                            type="text"
                            class="ca-search"
                            placeholder="Search announcements…"
                        />
                        <div class="ca-filter-chips">
                            <button
                                v-for="f in [
                                    'all',
                                    'general',
                                    'urgent',
                                    'update',
                                    'policy',
                                    'payment',
                                ]"
                                :key="f"
                                @click="clientAnnouncementsFilter = f"
                                class="chip"
                                :class="{
                                    'chip--active':
                                        clientAnnouncementsFilter === f,
                                }"
                            >
                                {{
                                    {
                                        all: 'All',
                                        general: 'General',
                                        urgent: 'Urgent',
                                        update: 'Update',
                                        policy: 'Policy',
                                        payment: 'Payment',
                                    }[f]
                                }}
                            </button>
                        </div>
                        <div class="ca-date-range">
                            <input
                                v-model="clientAnnouncementsDateFrom"
                                type="date"
                                class="ca-date-input"
                                title="From date"
                            />
                            <span class="ca-date-sep">—</span>
                            <input
                                v-model="clientAnnouncementsDateTo"
                                type="date"
                                class="ca-date-input"
                                title="To date"
                            />
                        </div>
                    </div>

                    <!-- Body -->
                    <div class="ca-modal__body">
                        <!-- Loading -->
                        <div
                            v-if="clientAnnouncementsLoading"
                            class="empty-state"
                        >
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
                                >Loading…</span
                            >
                        </div>

                        <!-- CLIENT LIST VIEW -->
                        <template v-else-if="!selectedClient">
                            <div
                                v-if="clientsWithAnnouncements.length === 0"
                                class="empty-state"
                            >
                                <p class="empty-state__title">
                                    No client announcements yet
                                </p>
                                <p class="empty-state__sub">
                                    Announcements made by clients will appear
                                    here
                                </p>
                            </div>
                            <div v-else class="ca-client-list">
                                <div
                                    v-for="c in clientsWithAnnouncements"
                                    :key="c.client_id"
                                    class="ca-client-row"
                                    @click="selectClient(c)"
                                >
                                    <div class="ca-client-row__avatar">
                                        {{ (c.name || '?')[0].toUpperCase() }}
                                    </div>
                                    <div class="ca-client-row__info">
                                        <div class="ca-client-row__name">
                                            {{ c.name }}
                                        </div>
                                        <div class="ca-client-row__email">
                                            {{ c.email }}
                                        </div>
                                    </div>
                                    <div class="ca-client-row__meta">
                                        <span class="ca-client-row__count"
                                            >{{ c.count }} announcement{{
                                                c.count !== 1 ? 's' : ''
                                            }}</span
                                        >
                                        <span class="ca-client-row__last"
                                            >Last:
                                            {{ timeAgo(c.last_at) }}</span
                                        >
                                    </div>
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="ca-client-row__chevron h-4 w-4"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        stroke-width="2"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M9 5l7 7-7 7"
                                        />
                                    </svg>
                                </div>
                            </div>
                        </template>

                        <!-- ANNOUNCEMENT DETAIL VIEW -->
                        <template v-else>
                            <div
                                v-if="selectedClientAnnouncements.length === 0"
                                class="empty-state"
                            >
                                <p class="empty-state__title">
                                    No announcements match your filters
                                </p>
                            </div>
                            <div v-else class="ca-announcement-list">
                                <div
                                    v-for="a in selectedClientAnnouncements"
                                    :key="a.id"
                                    class="ca-announcement-row"
                                >
                                    <div class="ca-announcement-row__top">
                                        <span
                                            class="type-badge"
                                            :class="typeInfo(a.type).badge"
                                            >{{ typeInfo(a.type).label }}</span
                                        >
                                        <span
                                            v-if="a.payment_subtype"
                                            class="payment-sub-badge"
                                            >{{
                                                paymentSubtypeInfo(
                                                    a.payment_subtype,
                                                )?.label || a.payment_subtype
                                            }}</span
                                        >
                                        <span
                                            class="ca-announcement-row__time"
                                            >{{
                                                timeAgo(
                                                    a.sent_at || a.created_at,
                                                )
                                            }}</span
                                        >
                                    </div>
                                    <div class="ca-announcement-row__title">
                                        {{ a.title }}
                                    </div>
                                    <div class="ca-announcement-row__message">
                                        {{ a.message }}
                                    </div>
                                    <div class="ca-announcement-row__meta">
                                        <span class="audience-badge">{{
                                            targetLabel(a)
                                        }}</span>
                                        <span class="ca-announcement-row__date">
                                            {{
                                                new Date(
                                                    a.created_at,
                                                ).toLocaleDateString('en-ZA', {
                                                    day: 'numeric',
                                                    month: 'short',
                                                    year: 'numeric',
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                })
                                            }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </template>
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

.btn-secondary {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #ffffff;
    color: #1a2332;
    border: 1.5px solid #e4e8ef;
    border-radius: 12px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.18s;
    white-space: nowrap;
    font-family: 'DM Sans', system-ui, sans-serif;
}
.btn-secondary:hover {
    border-color: #ea580c;
    color: #ea580c;
    background: #fff7ed;
}

/* CLIENT ANNOUNCEMENTS MODAL */
.ca-modal {
    background: #ffffff;
    border-radius: 20px;
    width: 100%;
    max-width: 760px;
    max-height: 88vh;
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
.ca-modal__header-left {
    display: flex;
    align-items: center;
    gap: 12px;
}
.ca-back-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 10px;
    border-radius: 8px;
    border: 1.5px solid #e4e8ef;
    background: #f8fafc;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
    font-family: inherit;
    transition: all 0.15s;
    white-space: nowrap;
}
.ca-back-btn:hover {
    border-color: #ea580c;
    color: #ea580c;
    background: #fff7ed;
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
.ca-filters {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 24px;
    border-bottom: 1px solid #e4e8ef;
    background: #f8fafc;
    flex-wrap: wrap;
    flex-shrink: 0;
}
.ca-search {
    flex: 1;
    min-width: 160px;
    background: #fff;
    border: 1.5px solid #e4e8ef;
    border-radius: 8px;
    padding: 7px 12px;
    font-size: 13px;
    font-family: inherit;
    color: #1a2332;
    outline: none;
    transition: border-color 0.15s;
}
.ca-search:focus {
    border-color: #ea580c;
}
.ca-search::placeholder {
    color: #94a3b8;
}
.ca-filter-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
}
.ca-date-range {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-left: auto;
}
.ca-date-input {
    background: #fff;
    border: 1.5px solid #e4e8ef;
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 12px;
    font-family: inherit;
    color: #1a2332;
    outline: none;
    cursor: pointer;
    transition: border-color 0.15s;
}
.ca-date-input:focus {
    border-color: #ea580c;
}
.ca-date-sep {
    font-size: 12px;
    color: #94a3b8;
}
.ca-modal__body {
    flex: 1;
    overflow-y: auto;
}
.ca-client-list {
    display: flex;
    flex-direction: column;
}
.ca-client-row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 24px;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
    transition: background 0.12s;
}
.ca-client-row:last-child {
    border-bottom: none;
}
.ca-client-row:hover {
    background: #fafbfc;
}
.ca-client-row__avatar {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    background: linear-gradient(135deg, #ea580c, #c2410c);
    color: #fff;
    font-size: 16px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.ca-client-row__info {
    flex: 1;
    min-width: 0;
}
.ca-client-row__name {
    font-size: 14px;
    font-weight: 700;
    color: #1a2332;
}
.ca-client-row__email {
    font-size: 12px;
    color: #94a3b8;
    margin-top: 1px;
}
.ca-client-row__meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 2px;
}
.ca-client-row__count {
    font-size: 12px;
    font-weight: 700;
    color: #ea580c;
}
.ca-client-row__last {
    font-size: 11px;
    color: #94a3b8;
}
.ca-client-row__chevron {
    color: #cbd5e1;
    flex-shrink: 0;
}
.ca-announcement-list {
    display: flex;
    flex-direction: column;
}
.ca-announcement-row {
    padding: 18px 24px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.ca-announcement-row:last-child {
    border-bottom: none;
}
.ca-announcement-row__top {
    display: flex;
    align-items: center;
    gap: 8px;
}
.ca-announcement-row__time {
    margin-left: auto;
    font-size: 11px;
    color: #94a3b8;
    white-space: nowrap;
}
.ca-announcement-row__title {
    font-size: 14px;
    font-weight: 700;
    color: #1a2332;
}
.ca-announcement-row__message {
    font-size: 13px;
    color: #64748b;
    line-height: 1.55;
}
.ca-announcement-row__meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 2px;
}
.ca-announcement-row__date {
    font-size: 11px;
    color: #94a3b8;
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
    position: relative;
    overflow: hidden;
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

/* FILTER BAR */
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
    white-space: nowrap;
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
.payment-sub-badge {
    display: inline-block;
    font-size: 10px;
    font-weight: 600;
    color: #ea580c;
    background: #fff7ed;
    border-radius: 6px;
    padding: 2px 7px;
    white-space: nowrap;
}

.td-announce {
    max-width: 320px;
}
.td-announce__title {
    font-weight: 600;
    color: #1a2332;
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.td-announce__sub {
    font-size: 12px;
    color: #64748b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
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
.td-muted {
    color: #64748b;
}
.td-time {
    color: #94a3b8;
    white-space: nowrap;
    font-size: 12px;
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
.field__count {
    font-weight: 500;
    color: #94a3b8;
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
    min-height: 96px;
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

/* TOGGLE ROW */
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

/* SEARCH SELECT */
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

/* Select all row */
.select-all-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 14px;
    background: #f8fafc;
    border-bottom: 1px solid #e4e8ef;
    cursor: pointer;
    transition: background 0.12s;
}
.select-all-row:hover {
    background: #f1f5f9;
}
.select-all-label {
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
}

/* Multi checkbox */
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
.multi-checkbox--indeterminate {
    background: #ea580c;
    border-color: #ea580c;
}
.search-list__id {
    font-size: 11px;
    color: #94a3b8;
    font-weight: 500;
}

/* PAYMENT PANEL */
.payment-panel {
    background: #fafafa;
    border: 1.5px solid #e4e8ef;
    border-radius: 10px;
    padding: 14px 16px;
}
.payment-subtypes {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
}
.subtype-btn {
    padding: 6px 14px;
    border-radius: 20px;
    border: 1.5px solid #e4e8ef;
    background: #fff;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
    font-family: inherit;
    transition: all 0.15s;
    white-space: nowrap;
}
.subtype-btn:hover {
    border-color: #cbd5e1;
    background: #f8fafc;
    color: #1a2332;
}

/* danger variant */
.subtype-btn--danger.subtype-btn--active {
    background: #fef2f2;
    border-color: #fca5a5;
    color: #dc2626;
}
.subtype-btn--danger:hover {
    border-color: #fca5a5;
    color: #dc2626;
}

/* warn variant */
.subtype-btn--warn.subtype-btn--active {
    background: #fffbeb;
    border-color: #fcd34d;
    color: #b45309;
}
.subtype-btn--warn:hover {
    border-color: #fcd34d;
    color: #b45309;
}

/* success variant */
.subtype-btn--success.subtype-btn--active {
    background: #f0fdf4;
    border-color: #86efac;
    color: #16a34a;
}
.subtype-btn--success:hover {
    border-color: #86efac;
    color: #16a34a;
}

/* APP UPDATE PANEL */
.app-update-panel {
    background: linear-gradient(135deg, #eff6ff 0%, #f0f9ff 100%);
    border: 1.5px solid #bfdbfe;
    border-radius: 12px;
    padding: 18px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.app-update-panel__header {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}
.app-update-panel__title {
    font-size: 13px;
    font-weight: 700;
    color: #1e40af;
}
.app-update-panel__sub {
    font-size: 11px;
    color: #3b82f6;
    margin-top: 2px;
}

/* App update warning */
.app-update-warning {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 10px 14px;
    background: #fef2f2;
    border: 1px solid #fca5a5;
    border-radius: 8px;
    font-size: 12px;
    color: #b91c1c;
    line-height: 1.5;
}
.app-update-warning strong {
    font-weight: 700;
}

/* ON-DEVICE PREVIEW */
.update-screen-preview {
    margin-top: 4px;
}
.usp__label {
    font-size: 10px;
    font-weight: 700;
    color: #60a5fa;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 10px;
}
.usp__phone {
    background: #0f172a;
    border-radius: 18px;
    padding: 20px 16px;
    max-width: 280px;
    margin: 0 auto;
}
.usp__screen {
    background: #1e293b;
    border-radius: 12px;
    padding: 24px 18px 18px;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
}
.usp__app-icon {
    width: 56px;
    height: 56px;
    background: linear-gradient(135deg, #ea580c, #c2410c);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 16px rgba(234, 88, 12, 0.4);
    margin-bottom: 4px;
}
.usp__heading {
    font-size: 15px;
    font-weight: 800;
    color: #f8fafc;
    letter-spacing: -0.3px;
}
.usp__version {
    font-size: 11px;
    color: #fb923c;
    font-weight: 600;
}
.usp__message {
    font-size: 11px;
    color: #94a3b8;
    line-height: 1.6;
    text-align: center;
}
.usp__actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: 100%;
    margin-top: 4px;
}
.usp__dismiss {
    width: 100%;
    padding: 10px;
    background: rgba(255, 255, 255, 0.07);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    font-size: 12px;
    font-weight: 600;
    color: #94a3b8;
    cursor: pointer;
    font-family: inherit;
    transition: background 0.15s;
}
.usp__dismiss:hover {
    background: rgba(255, 255, 255, 0.12);
}
.usp__update {
    width: 100%;
    padding: 10px;
    background: #ea580c;
    border: none;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 700;
    color: #fff;
    cursor: pointer;
    font-family: inherit;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    box-shadow: 0 4px 14px rgba(234, 88, 12, 0.4);
    transition: all 0.15s;
}
.usp__update:hover {
    background: #c2410c;
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
    .payment-subtypes {
        grid-template-columns: 1fr;
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
    .stat-card {
        padding: 14px;
    }
    .stat-card__value {
        font-size: 22px;
    }
    .stat-card__icon {
        display: none;
    }
    .data-table {
        min-width: 600px;
    }
    .table-card {
        overflow-x: auto;
    }
}
</style>

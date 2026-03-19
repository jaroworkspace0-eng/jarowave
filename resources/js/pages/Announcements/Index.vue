<script>
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';

const authHeaders = () => ({
    Authorization: `Bearer ${localStorage.getItem('token')}`,
});

export default {
    name: 'AnnouncementsPage',
    components: { AppLayout },

    data() {
        return {
            showCompose: false,
            sending: false,
            loading: true,
            flashMsg: '',
            flashType: 'success',
            activeFilter: 'all',
            announcements: [],
            clients: [],
            deleteTargetId: null,
            showDeleteModal: false,
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
                target: 'all',
                target_client_id: '',
            },

            types: [
                {
                    value: 'general',
                    label: 'General',
                    icon: '📢',
                    bg: '#EEF2FF',
                    color: '#4F46E5',
                },
                {
                    value: 'urgent',
                    label: 'Urgent',
                    icon: '🚨',
                    bg: '#FEF2F2',
                    color: '#DC2626',
                },
                {
                    value: 'update',
                    label: 'Update',
                    icon: '🔄',
                    bg: '#F0FDF4',
                    color: '#16A34A',
                },
                {
                    value: 'policy',
                    label: 'Policy',
                    icon: '📋',
                    bg: '#FFFBEB',
                    color: '#D97706',
                },
            ],
        };
    },

    computed: {
        currentType() {
            return (
                this.types.find((t) => t.value === this.form.type) ||
                this.types[0]
            );
        },
        typeIcon() {
            return this.currentType.icon;
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
    },

    mounted() {
        this.load();
        this.loadClients();
    },

    methods: {
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
                this.clients = data;
            } catch (e) {
                console.error('clients:', e);
            }
        },

        async send() {
            if (!this.form.title || !this.form.message) return;
            this.sending = true;
            try {
                await axios.post(
                    `${import.meta.env.VITE_APP_URL}/api/announcements/send`,
                    this.form,
                    { headers: authHeaders() },
                );
                this.flash('Announcement sent successfully!', 'success');
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
                this.flash('Announcement deleted.', 'success');
            } catch (e) {
                console.error(e);
            } finally {
                this.showDeleteModal = false;
                this.deleteTargetId = null;
            }
        },

        closeCompose() {
            this.showCompose = false;
            this.form = {
                title: '',
                message: '',
                type: 'general',
                target: 'all',
                target_client_id: '',
            };
        },

        flash(msg, type = 'success') {
            this.flashMsg = msg;
            this.flashType = type;
            setTimeout(() => {
                this.flashMsg = '';
            }, 3500);
        },

        typeInfo(type) {
            return this.types.find((t) => t.value === type) || this.types[0];
        },

        targetLabel(a) {
            if (a.target === 'client') {
                const c = this.clients.find((c) => c.id == a.target_client_id);
                return c ? c.name : `Client #${a.target_client_id}`;
            }
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
        <!-- ══════════════════════════════════════════════════════════════════
             OUTER CONTAINER — identical to Employees page
        ══════════════════════════════════════════════════════════════════ -->
        <div
            class="relative flex h-full w-full flex-col rounded-xl bg-white bg-clip-border text-gray-700 shadow-md"
        >
            <!-- ── TOP HEADER — matches Employees page exactly ──────────── -->
            <div
                class="relative mx-4 mt-4 overflow-hidden rounded-none bg-white bg-clip-border text-gray-700"
            >
                <div class="mb-8 flex items-center justify-between gap-8">
                    <div>
                        <p
                            class="mt-1 block font-sans text-base leading-relaxed font-normal text-gray-700 antialiased"
                        >
                            Announcements
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col gap-2 sm:flex-row">
                        <button
                            @click="showCompose = true"
                            class="rounded-lg border border-gray-900 px-4 py-2 text-center align-middle font-sans text-xs font-bold text-gray-900 uppercase transition-all select-none hover:opacity-75 focus:ring focus:ring-gray-300 active:opacity-[0.85] disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none"
                        >
                            New Announcement
                        </button>
                    </div>
                </div>
            </div>

            <!-- ── STAT CARDS ────────────────────────────────────────────── -->
            <div class="stats-strip mx-4 mb-6">
                <div class="scard" style="--acc: #4f46e5; --bg: #eef2ff">
                    <span class="scard__icon">📡</span>
                    <div>
                        <div class="scard__val">{{ totalSent }}</div>
                        <div class="scard__lbl">Total Sent</div>
                    </div>
                </div>
                <div class="scard" style="--acc: #dc2626; --bg: #fef2f2">
                    <span class="scard__icon">🚨</span>
                    <div>
                        <div class="scard__val">{{ urgentCount }}</div>
                        <div class="scard__lbl">Urgent</div>
                    </div>
                </div>
                <div class="scard" style="--acc: #16a34a; --bg: #f0fdf4">
                    <span class="scard__icon">📅</span>
                    <div>
                        <div class="scard__val">{{ todayCount }}</div>
                        <div class="scard__lbl">Today</div>
                    </div>
                </div>
            </div>

            <!-- ── FLASH ─────────────────────────────────────────────────── -->
            <div
                v-if="flashMsg"
                class="mx-4 mb-4 rounded bg-green-100 p-2 text-sm font-medium text-green-700"
            >
                ✓ {{ flashMsg }}
            </div>

            <!-- ── FILTER ROW ─────────────────────────────────────────────── -->
            <div
                class="mx-4 mb-3 flex flex-wrap items-center justify-between gap-3"
            >
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="f in [
                            'all',
                            'general',
                            'urgent',
                            'update',
                            'policy',
                        ]"
                        :key="f"
                        @click="activeFilter = f"
                        class="filter-chip"
                        :class="{ 'filter-chip--on': activeFilter === f }"
                    >
                        {{
                            {
                                all: '📋 All',
                                general: '📢 General',
                                urgent: '🚨 Urgent',
                                update: '🔄 Update',
                                policy: '📋 Policy',
                            }[f]
                        }}
                    </button>
                </div>
                <span class="text-xs font-medium text-gray-400"
                    >{{ filteredList.length }} announcement{{
                        filteredList.length !== 1 ? 's' : ''
                    }}</span
                >
            </div>

            <!-- ── TABLE — same structure as Employees ───────────────────── -->
            <div class="overflow-scroll p-0 px-0">
                <div class="pt-0 pr-4 pb-4 pl-4">
                    <!-- Loading -->
                    <div
                        v-if="loading"
                        class="flex items-center justify-center gap-3 py-16 text-gray-400"
                    >
                        <svg
                            class="h-5 w-5 animate-spin"
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
                        <span class="text-sm">Loading announcements...</span>
                    </div>

                    <!-- Empty -->
                    <div
                        v-else-if="filteredList.length === 0"
                        class="flex flex-col items-center justify-center gap-3 py-20 text-center"
                    >
                        <span class="text-5xl">📭</span>
                        <p class="font-sans text-base font-bold text-gray-900">
                            No announcements yet
                        </p>
                        <p class="text-sm text-gray-500">
                            Hit "New Announcement" to broadcast your first
                            message
                        </p>
                    </div>

                    <!-- Table -->
                    <table
                        v-else
                        class="mt-0 w-full min-w-max table-auto text-left"
                    >
                        <thead>
                            <tr class="bg-gray-50">
                                <th
                                    class="border-blue-gray-100 border-y p-4 font-sans text-sm font-normal opacity-70"
                                >
                                    Type
                                </th>
                                <th
                                    class="border-blue-gray-100 border-y p-4 font-sans text-sm font-normal opacity-70"
                                >
                                    Announcement
                                </th>
                                <th
                                    class="border-blue-gray-100 border-y p-4 font-sans text-sm font-normal opacity-70"
                                >
                                    Audience
                                </th>
                                <th
                                    class="border-blue-gray-100 border-y p-4 font-sans text-sm font-normal opacity-70"
                                >
                                    Sender
                                </th>
                                <th
                                    class="border-blue-gray-100 border-y p-4 font-sans text-sm font-normal opacity-70"
                                >
                                    Sent
                                </th>
                                <th
                                    class="border-blue-gray-100 border-y p-2 font-sans text-sm font-normal opacity-70"
                                >
                                    Action
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="a in filteredList"
                                :key="a.id"
                                class="hover:bg-gray-50/50"
                            >
                                <td class="border-blue-gray-50 border-b p-4">
                                    <span
                                        class="type-pill"
                                        :style="{
                                            background: typeInfo(a.type).bg,
                                            color: typeInfo(a.type).color,
                                        }"
                                    >
                                        {{ typeInfo(a.type).icon }}
                                        {{ typeInfo(a.type).label }}
                                    </span>
                                </td>
                                <td
                                    class="border-blue-gray-50 border-b p-4"
                                    style="max-width: 340px"
                                >
                                    <div
                                        class="text-blue-gray-900 mb-0.5 font-sans text-sm font-bold"
                                    >
                                        {{ a.title }}
                                    </div>
                                    <div
                                        class="truncate font-sans text-sm opacity-70"
                                    >
                                        {{ a.message }}
                                    </div>
                                </td>
                                <td class="border-blue-gray-50 border-b p-4">
                                    <span class="audience-pill">{{
                                        targetLabel(a)
                                    }}</span>
                                </td>
                                <td
                                    class="border-blue-gray-50 border-b p-4 font-sans text-sm text-gray-500"
                                >
                                    {{ a.sender?.name || 'Admin' }}
                                </td>
                                <td
                                    class="border-blue-gray-50 border-b p-4 font-sans text-sm whitespace-nowrap text-gray-400"
                                >
                                    {{ timeAgo(a.sent_at || a.created_at) }}
                                </td>
                                <td class="border-blue-gray-50 border-b p-0">
                                    <div class="flex items-center gap-2 p-2">
                                        <button
                                            @click="confirmDelete(a.id)"
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

            <!-- ── PAGINATION — same as Employees ───────────────────────── -->
            <div
                class="border-blue-gray-50 flex items-center justify-between border-t p-4"
            >
                <div class="text-sm text-gray-600">
                    Showing {{ pagination.from || 0 }} to
                    {{ pagination.to || 0 }} of
                    {{ pagination.total || filteredList.length }} entries
                </div>
                <div class="flex flex-nowrap space-x-2">
                    <template v-for="(link, i) in pagination.links" :key="i">
                        <button
                            v-if="link.url"
                            @click="load(link.url)"
                            v-html="link.label"
                            class="inline-block min-w-[40px] rounded border px-3 py-1 text-center transition-all duration-200"
                            :class="
                                link.active
                                    ? 'border-blue-500 bg-blue-500 text-white'
                                    : 'border-gray-300 bg-white text-blue-500 hover:bg-gray-50'
                            "
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
        <!-- end outer container -->

        <!-- ══════════════════════════════════════════════════════════════════
             COMPOSE MODAL
        ══════════════════════════════════════════════════════════════════ -->
        <transition name="modal">
            <div
                v-if="showCompose"
                class="modal-backdrop"
                @click.self="closeCompose"
            >
                <div class="compose-modal">
                    <div class="compose-modal__header">
                        <div class="compose-modal__header-icon">✦</div>
                        <div>
                            <div class="compose-modal__title">
                                New Announcement
                            </div>
                            <div class="compose-modal__sub">
                                Delivered instantly to all connected operators
                            </div>
                        </div>
                        <button class="modal-close-btn" @click="closeCompose">
                            ✕
                        </button>
                    </div>

                    <form @submit.prevent="send" class="compose-modal__body">
                        <!-- Type -->
                        <div class="cfield">
                            <label class="cfield__label">TYPE</label>
                            <div class="type-grid">
                                <button
                                    type="button"
                                    v-for="t in types"
                                    :key="t.value"
                                    class="type-btn"
                                    :class="{
                                        'type-btn--on': form.type === t.value,
                                    }"
                                    :style="
                                        form.type === t.value
                                            ? {
                                                  borderColor: t.color,
                                                  background: t.bg,
                                              }
                                            : {}
                                    "
                                    @click="form.type = t.value"
                                >
                                    <span class="type-btn__icon">{{
                                        t.icon
                                    }}</span>
                                    <span
                                        class="type-btn__lbl"
                                        :style="
                                            form.type === t.value
                                                ? { color: t.color }
                                                : {}
                                        "
                                        >{{ t.label }}</span
                                    >
                                </button>
                            </div>
                        </div>

                        <!-- Title -->
                        <div class="cfield">
                            <label class="cfield__label"
                                >TITLE
                                <span class="cfield__count"
                                    >{{ form.title.length }}/100</span
                                ></label
                            >
                            <input
                                v-model="form.title"
                                type="text"
                                class="cinput"
                                placeholder="e.g. System Update Tonight"
                                maxlength="100"
                                required
                            />
                        </div>

                        <!-- Message -->
                        <div class="cfield">
                            <label class="cfield__label"
                                >MESSAGE
                                <span class="cfield__count"
                                    >{{ form.message.length }}/1000</span
                                ></label
                            >
                            <textarea
                                v-model="form.message"
                                class="cinput ctextarea"
                                placeholder="Write your announcement..."
                                maxlength="1000"
                                rows="4"
                                required
                            ></textarea>
                        </div>

                        <!-- Audience -->
                        <div class="cfield">
                            <label class="cfield__label">SEND TO</label>
                            <div class="audience-row">
                                <button
                                    type="button"
                                    v-for="tg in [
                                        {
                                            value: 'all',
                                            label: '👥 All Operators',
                                        },
                                        {
                                            value: 'client',
                                            label: '🏢 By Client',
                                        },
                                    ]"
                                    :key="tg.value"
                                    class="audience-btn"
                                    :class="{
                                        'audience-btn--on':
                                            form.target === tg.value,
                                    }"
                                    @click="form.target = tg.value"
                                >
                                    {{ tg.label }}
                                </button>
                            </div>
                        </div>

                        <!-- Client picker -->
                        <div class="cfield" v-if="form.target === 'client'">
                            <label class="cfield__label">SELECT CLIENT</label>
                            <select
                                v-model="form.target_client_id"
                                class="cinput"
                                required
                            >
                                <option value="" disabled>
                                    Choose a client...
                                </option>
                                <option
                                    v-for="c in clients"
                                    :key="c.id"
                                    :value="c.id"
                                >
                                    {{ c.name }}
                                </option>
                            </select>
                        </div>

                        <!-- Phone preview -->
                        <div class="cfield" v-if="form.title || form.message">
                            <label class="cfield__label"
                                >📱 DEVICE PREVIEW</label
                            >
                            <div class="phone-preview">
                                <div class="phone-preview__pill"></div>
                                <div class="phone-preview__notif">
                                    <div
                                        class="phone-preview__dot"
                                        :style="{
                                            background: currentType.color,
                                        }"
                                    ></div>
                                    <div style="flex: 1">
                                        <div class="phone-preview__row">
                                            <span class="phone-preview__app"
                                                >Echo Link</span
                                            >
                                            <span class="phone-preview__time"
                                                >now</span
                                            >
                                        </div>
                                        <div class="phone-preview__title">
                                            {{
                                                form.title ||
                                                'Announcement Title'
                                            }}
                                        </div>
                                        <div class="phone-preview__body">
                                            {{
                                                form.message ||
                                                'Your message here...'
                                            }}
                                        </div>
                                    </div>
                                    <span class="phone-preview__type-icon">{{
                                        currentType.icon
                                    }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="compose-actions">
                            <button
                                type="button"
                                class="btn-cancel"
                                @click="closeCompose"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                class="btn-send"
                                :disabled="sending"
                            >
                                <svg
                                    v-if="sending"
                                    class="spin-icon"
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
                                <span v-else>📡</span>
                                {{
                                    sending ? 'Sending...' : 'Send Announcement'
                                }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </transition>

        <!-- ══════════════════════════════════════════════════════════════════
             DELETE CONFIRMATION MODAL — custom designed
        ══════════════════════════════════════════════════════════════════ -->
        <transition name="modal">
            <div
                v-if="showDeleteModal"
                class="modal-backdrop"
                @click.self="showDeleteModal = false"
            >
                <div class="delete-modal">
                    <div class="delete-modal__icon-wrap">
                        <div class="delete-modal__icon">🗑️</div>
                        <div class="delete-modal__ring"></div>
                    </div>
                    <h2 class="delete-modal__title">Delete Announcement?</h2>
                    <p class="delete-modal__body">
                        This announcement will be permanently removed and cannot
                        be recovered. Operators who haven't read it will lose
                        access to it.
                    </p>
                    <div class="delete-modal__actions">
                        <button
                            @click="showDeleteModal = false"
                            class="delete-modal__cancel"
                        >
                            Keep it
                        </button>
                        <button
                            @click="executeDelete"
                            class="delete-modal__confirm"
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
                            Yes, Delete It
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- Flash -->
        <transition name="flash">
            <div v-if="flashMsg" class="flash-toast">✓ {{ flashMsg }}</div>
        </transition>
    </AppLayout>
</template>

<style scoped>
/* ── Stats strip ───────────────────────────────────────────────────────── */
.stats-strip {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}
.scard {
    background: var(--bg);
    border: 1.5px solid color-mix(in srgb, var(--acc) 20%, transparent);
    border-radius: 16px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: transform 0.18s;
}
.scard:hover {
    transform: translateY(-2px);
}
.scard__icon {
    font-size: 26px;
}
.scard__val {
    font-size: 26px;
    font-weight: 800;
    color: var(--acc);
    line-height: 1;
    margin-bottom: 3px;
}
.scard__lbl {
    font-size: 10px;
    font-weight: 700;
    color: #94a3b8;
    letter-spacing: 1px;
    text-transform: uppercase;
}

/* ── Filter chips ──────────────────────────────────────────────────────── */
.filter-chip {
    padding: 5px 13px;
    border-radius: 20px;
    border: 1.5px solid #e2e8f0;
    background: #f8fafc;
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    cursor: pointer;
    transition: all 0.15s;
}
.filter-chip:hover {
    border-color: #cbd5e1;
}
.filter-chip--on {
    background: #0f172a;
    color: #fff;
    border-color: #0f172a;
}

/* ── Table decorations ─────────────────────────────────────────────────── */
.type-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}
.audience-pill {
    background: #f1f5f9;
    color: #475569;
    border-radius: 8px;
    padding: 3px 10px;
    font-size: 11px;
    font-weight: 600;
    display: inline-block;
    white-space: nowrap;
}

/* ══════════════════════════════════════════════════════════════════════════
   SHARED MODAL BASE
══════════════════════════════════════════════════════════════════════════ */
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    backdrop-filter: blur(6px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 24px;
}

/* ══════════════════════════════════════════════════════════════════════════
   COMPOSE MODAL
══════════════════════════════════════════════════════════════════════════ */
.compose-modal {
    background: #fff;
    border-radius: 26px;
    width: 100%;
    max-width: 640px;
    max-height: 92vh;
    overflow-y: auto;
    box-shadow:
        0 40px 100px rgba(0, 0, 0, 0.22),
        0 0 0 1px rgba(0, 0, 0, 0.04);
}
.compose-modal__header {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 26px 28px 0;
    position: sticky;
    top: 0;
    background: #fff;
    z-index: 2;
    padding-bottom: 20px;
    border-bottom: 1px solid #f1f5f9;
}
.compose-modal__header-icon {
    width: 42px;
    height: 42px;
    background: #eef2ff;
    color: #4f46e5;
    border-radius: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
    font-weight: 700;
    flex-shrink: 0;
    margin-top: 2px;
}
.compose-modal__title {
    font-size: 16px;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 3px;
}
.compose-modal__sub {
    font-size: 12px;
    color: #94a3b8;
}
.modal-close-btn {
    margin-left: auto;
    background: #f1f5f9;
    border: none;
    border-radius: 10px;
    width: 36px;
    height: 36px;
    cursor: pointer;
    font-size: 14px;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: background 0.15s;
}
.modal-close-btn:hover {
    background: #e2e8f0;
}
.compose-modal__body {
    padding: 24px 28px 28px;
}

/* Fields */
.cfield {
    margin-bottom: 20px;
}
.cfield__label {
    display: flex;
    justify-content: space-between;
    font-size: 10px;
    font-weight: 800;
    color: #94a3b8;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    margin-bottom: 8px;
}
.cfield__count {
    font-weight: 500;
    color: #cbd5e1;
}
.cinput {
    width: 100%;
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px 16px;
    font-size: 14px;
    color: #0f172a;
    outline: none;
    box-sizing: border-box;
    transition: border-color 0.15s;
}
.cinput:focus {
    border-color: #4f46e5;
    background: #fff;
}
.ctextarea {
    resize: vertical;
    min-height: 100px;
    line-height: 1.6;
}

/* Type grid */
.type-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
}
.type-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    border-radius: 14px;
    padding: 14px 8px;
    cursor: pointer;
    transition: all 0.15s;
}
.type-btn:hover {
    border-color: #cbd5e1;
}
.type-btn__icon {
    font-size: 22px;
}
.type-btn__lbl {
    font-size: 10px;
    font-weight: 800;
    color: #64748b;
}

/* Audience */
.audience-row {
    display: flex;
    gap: 10px;
}
.audience-btn {
    flex: 1;
    padding: 11px;
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    color: #475569;
    transition: all 0.15s;
}
.audience-btn:hover {
    border-color: #cbd5e1;
}
.audience-btn--on {
    border-color: #4f46e5;
    background: #eef2ff;
    color: #4f46e5;
}

/* Phone preview */
.phone-preview {
    background: #1e293b;
    border-radius: 22px;
    padding: 18px 16px 22px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
}
.phone-preview__pill {
    width: 60px;
    height: 5px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 3px;
}
.phone-preview__notif {
    background: rgba(255, 255, 255, 0.93);
    border-radius: 14px;
    padding: 14px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    width: 100%;
    box-sizing: border-box;
}
.phone-preview__dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-top: 5px;
    flex-shrink: 0;
}
.phone-preview__row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 3px;
}
.phone-preview__app {
    font-size: 10px;
    font-weight: 800;
    color: #64748b;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}
.phone-preview__time {
    font-size: 10px;
    color: #94a3b8;
}
.phone-preview__title {
    font-size: 13px;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 3px;
}
.phone-preview__body {
    font-size: 11px;
    color: #475569;
    line-height: 1.5;
}
.phone-preview__type-icon {
    font-size: 20px;
    flex-shrink: 0;
    margin-top: 2px;
}

/* Compose actions */
.compose-actions {
    display: flex;
    gap: 10px;
    margin-top: 8px;
}
.btn-cancel {
    flex: 1;
    padding: 14px;
    background: #f1f5f9;
    border: none;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 700;
    color: #64748b;
    cursor: pointer;
    transition: background 0.15s;
}
.btn-cancel:hover {
    background: #e2e8f0;
}
.btn-send {
    flex: 2;
    padding: 14px;
    background: #0f172a;
    border: none;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 700;
    color: #f8fafc;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.2);
}
.btn-send:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.3);
}
.btn-send:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
.spin-icon {
    width: 16px;
    height: 16px;
    animation: spin 0.7s linear infinite;
}

/* ══════════════════════════════════════════════════════════════════════════
   DELETE MODAL
══════════════════════════════════════════════════════════════════════════ */
.delete-modal {
    background: #fff;
    border-radius: 24px;
    width: 100%;
    max-width: 400px;
    padding: 36px 32px 28px;
    box-shadow: 0 40px 100px rgba(0, 0, 0, 0.25);
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}
.delete-modal__icon-wrap {
    position: relative;
    width: 72px;
    height: 72px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 20px;
}
.delete-modal__icon {
    font-size: 34px;
    z-index: 1;
    position: relative;
}
.delete-modal__ring {
    position: absolute;
    inset: 0;
    border-radius: 50%;
    background: #fef2f2;
    border: 2px solid #fecaca;
    animation: ring-pulse 2s ease-in-out infinite;
}
.delete-modal__title {
    font-size: 18px;
    font-weight: 800;
    color: #0f172a;
    margin: 0 0 10px;
}
.delete-modal__body {
    font-size: 13px;
    color: #64748b;
    line-height: 1.6;
    margin-bottom: 28px;
}
.delete-modal__actions {
    display: flex;
    gap: 10px;
    width: 100%;
}
.delete-modal__cancel {
    flex: 1;
    padding: 13px;
    background: #f1f5f9;
    border: none;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 700;
    color: #475569;
    cursor: pointer;
    transition: background 0.15s;
}
.delete-modal__cancel:hover {
    background: #e2e8f0;
}
.delete-modal__confirm {
    flex: 1.5;
    padding: 13px;
    background: #dc2626;
    border: none;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 700;
    color: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    transition: all 0.15s;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.3);
}
.delete-modal__confirm:hover {
    background: #b91c1c;
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(220, 38, 38, 0.4);
}

/* ── Flash toast ───────────────────────────────────────────────────────── */
.flash-toast {
    position: fixed;
    bottom: 32px;
    right: 32px;
    background: #0f172a;
    color: #f1f5f9;
    padding: 14px 22px;
    border-radius: 14px;
    font-size: 13px;
    font-weight: 600;
    z-index: 99999;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.25);
    border-left: 4px solid #4ade80;
}

/* ── Transitions ───────────────────────────────────────────────────────── */
.modal-enter-active,
.modal-leave-active {
    transition: all 0.25s ease;
}
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
.modal-enter-from .compose-modal,
.modal-leave-to .compose-modal,
.modal-enter-from .delete-modal,
.modal-leave-to .delete-modal {
    transform: scale(0.96) translateY(16px);
}

.flash-enter-active,
.flash-leave-active {
    transition: all 0.3s ease;
}
.flash-enter-from,
.flash-leave-to {
    opacity: 0;
    transform: translateY(8px);
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
@keyframes ring-pulse {
    0%,
    100% {
        transform: scale(1);
        opacity: 1;
    }
    50% {
        transform: scale(1.12);
        opacity: 0.6;
    }
}

/* ── Responsive ────────────────────────────────────────────────────────── */
@media (max-width: 640px) {
    .stats-strip {
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
    }
    .type-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>

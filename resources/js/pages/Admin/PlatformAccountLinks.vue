<script>
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { router } from '@inertiajs/vue3';
import axios from 'axios';

const authHeaders = () => ({
    Authorization: `Bearer ${localStorage.getItem('token')}`,
});

export default {
    name: 'PlatformAccountLinksPage',
    components: { AppLayout },

    data() {
        const auth = useAuthStore();
        return {
            role: auth.user?.role || 'user',
            loading: true,
            links: [],
            statusFilter: 'pending',
            scopeFilter: 'all',
            processingId: null,
            rejectTargetId: null,
            rejectTargetName: '',
            showRejectModal: false,
            flashMsg: '',
        };
    },

    mounted() {
        if (this.role !== 'admin') {
            router.visit('/dashboard');
            return;
        }
        this.load();
    },

    computed: {
        filteredList() {
            let list = this.links;
            if (this.statusFilter === 'escalated') {
                list = list.filter(
                    (l) => l.escalated && l.status === 'pending',
                );
            } else if (this.statusFilter !== 'all') {
                list = list.filter((l) => l.status === this.statusFilter);
            }
            if (this.scopeFilter === 'estate') {
                list = list.filter((l) => l.channel?.type === 'estate');
            } else if (this.scopeFilter === 'standalone') {
                list = list.filter((l) => l.channel?.type === 'standalone');
            }
            return list;
        },
        pendingCount() {
            return this.links.filter((l) => l.status === 'pending').length;
        },
        escalatedCount() {
            return this.links.filter(
                (l) => l.escalated && l.status === 'pending',
            ).length;
        },
        activeCount() {
            return this.links.filter((l) => l.status === 'active').length;
        },
        rejectedCount() {
            return this.links.filter((l) => l.status === 'rejected').length;
        },
    },

    methods: {
        async load() {
            this.loading = true;
            try {
                const { data } = await axios.get(
                    `${import.meta.env.VITE_APP_URL}/api/admin/account-links`,
                    { headers: authHeaders() },
                );
                this.links = Array.isArray(data) ? data : data.data || [];
            } catch (e) {
                console.error(
                    '[AccountLinks]',
                    e?.response?.status,
                    e?.message,
                );
            } finally {
                this.loading = false;
            }
        },

        async approve(link) {
            this.processingId = link.id;
            try {
                await axios.patch(
                    `${import.meta.env.VITE_APP_URL}/api/admin/account-links/${link.id}/approve`,
                    {},
                    { headers: authHeaders() },
                );
                this.flash(
                    `${link.linked_account?.name || 'Account'} linked and address synced.`,
                );
                this.load();
            } catch (e) {
                alert(e.response?.data?.message || 'Failed to approve.');
            } finally {
                this.processingId = null;
            }
        },

        confirmReject(link) {
            this.rejectTargetId = link.id;
            this.rejectTargetName = link.linked_account?.name || 'this account';
            this.showRejectModal = true;
        },

        async executeReject() {
            const id = this.rejectTargetId;
            this.processingId = id;
            try {
                await axios.patch(
                    `${import.meta.env.VITE_APP_URL}/api/admin/account-links/${id}/reject`,
                    {},
                    { headers: authHeaders() },
                );
                this.flash('Request rejected.');
                this.load();
            } catch (e) {
                alert(e.response?.data?.message || 'Failed to reject.');
            } finally {
                this.processingId = null;
                this.showRejectModal = false;
                this.rejectTargetId = null;
            }
        },

        statusBadge(link) {
            if (link.status === 'active')
                return {
                    label: 'Approved',
                    cls: 'bg-emerald-50 text-emerald-700',
                };
            if (link.status === 'rejected')
                return { label: 'Rejected', cls: 'bg-red-50 text-red-600' };
            if (link.escalated)
                return {
                    label: 'Escalated',
                    cls: 'bg-orange-50 text-orange-700',
                };
            return { label: 'Pending', cls: 'bg-amber-50 text-amber-700' };
        },

        initials(name) {
            return (name || '?')
                .split(' ')
                .slice(0, 2)
                .map((w) => w[0]?.toUpperCase() ?? '')
                .join('');
        },

        formatDate(ts) {
            if (!ts) return '—';
            return new Date(ts).toLocaleString('en-ZA', {
                day: 'numeric',
                month: 'short',
                hour: '2-digit',
                minute: '2-digit',
            });
        },

        flash(msg) {
            this.flashMsg = msg;
            setTimeout(() => (this.flashMsg = ''), 3500);
        },
    },
};
</script>

<template>
    <Head title="Account Links" />

    <AppLayout>
        <div class="page-root">
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Households</div>
                    <h1 class="page-header__title">
                        Account Link Requests - Platform
                    </h1>
                </div>
            </div>

            <div class="stat-row">
                <div class="stat-card">
                    <div class="stat-card__label">Pending</div>
                    <div class="stat-card__value">{{ pendingCount }}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Escalated</div>
                    <div class="stat-card__value stat-card__value--orange">
                        {{ escalatedCount }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Approved</div>
                    <div class="stat-card__value stat-card__value--green">
                        {{ activeCount }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Rejected</div>
                    <div class="stat-card__value stat-card__value--red">
                        {{ rejectedCount }}
                    </div>
                </div>
            </div>

            <div class="filter-bar">
                <div class="filter-bar__chips">
                    <button
                        v-for="f in [
                            'pending',
                            'escalated',
                            'active',
                            'rejected',
                            'all',
                        ]"
                        :key="f"
                        @click="statusFilter = f"
                        class="chip"
                        :class="{ 'chip--active': statusFilter === f }"
                    >
                        {{
                            {
                                pending: 'Pending',
                                escalated: 'Escalated',
                                active: 'Approved',
                                rejected: 'Rejected',
                                all: 'All',
                            }[f]
                        }}
                    </button>
                    <span class="chip-sep"></span>
                    <button
                        v-for="s in ['all', 'estate', 'standalone']"
                        :key="s"
                        @click="scopeFilter = s"
                        class="chip chip--scope"
                        :class="{ 'chip--active': scopeFilter === s }"
                    >
                        {{
                            {
                                all: 'All Channels',
                                estate: 'Estate',
                                standalone: 'Standalone',
                            }[s]
                        }}
                    </button>
                </div>
                <span class="filter-bar__count"
                    >{{ filteredList.length }} result{{
                        filteredList.length !== 1 ? 's' : ''
                    }}</span
                >
            </div>

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
                    <span class="mt-2 text-sm text-slate-400"
                        >Loading requests…</span
                    >
                </div>

                <div v-else-if="filteredList.length === 0" class="empty-state">
                    <p class="empty-state__title">No matching requests</p>
                    <p class="empty-state__sub">
                        Link requests across all channels will appear here.
                    </p>
                </div>

                <table v-else class="data-table">
                    <thead>
                        <tr>
                            <th>Channel</th>
                            <th>Primary Account</th>
                            <th>Linked Account</th>
                            <th>Status</th>
                            <th>Requested</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="link in filteredList" :key="link.id">
                            <td>
                                <div class="td-announce__title">
                                    {{ link.channel?.name || '—' }}
                                </div>
                                <div
                                    class="td-announce__sub"
                                    style="text-transform: capitalize"
                                >
                                    {{ link.channel?.type || 'unknown' }}
                                </div>
                            </td>
                            <td>
                                <div class="person-cell">
                                    <div class="avatar">
                                        {{
                                            initials(link.primary_account?.name)
                                        }}
                                    </div>
                                    <div>
                                        <div class="td-announce__title">
                                            {{
                                                link.primary_account?.name ||
                                                '—'
                                            }}
                                        </div>
                                        <div class="td-announce__sub">
                                            {{
                                                link.primary_account?.phone ||
                                                ''
                                            }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="person-cell">
                                    <div class="avatar">
                                        {{
                                            initials(link.linked_account?.name)
                                        }}
                                    </div>
                                    <div>
                                        <div class="td-announce__title">
                                            {{
                                                link.linked_account?.name || '—'
                                            }}
                                        </div>
                                        <div class="td-announce__sub">
                                            {{
                                                link.linked_account?.phone || ''
                                            }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span
                                    class="type-badge"
                                    :class="statusBadge(link).cls"
                                    >{{ statusBadge(link).label }}</span
                                >
                            </td>
                            <td class="td-time">
                                {{ formatDate(link.created_at) }}
                            </td>
                            <td>
                                <div
                                    v-if="link.status === 'pending'"
                                    style="
                                        display: flex;
                                        gap: 8px;
                                        justify-content: flex-end;
                                    "
                                >
                                    <button
                                        class="btn-primary"
                                        style="padding: 7px 14px"
                                        :disabled="processingId === link.id"
                                        @click="approve(link)"
                                    >
                                        Approve
                                    </button>
                                    <button
                                        class="btn-ghost"
                                        style="padding: 7px 14px"
                                        :disabled="processingId === link.id"
                                        @click="confirmReject(link)"
                                    >
                                        Reject
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <transition name="modal">
            <div
                v-if="showRejectModal"
                class="modal-backdrop"
                @click.self="showRejectModal = false"
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
                                d="M6 18L18 6M6 6l12 12"
                            />
                        </svg>
                    </div>
                    <h2 class="confirm-modal__title">Reject Link Request?</h2>
                    <p class="confirm-modal__body">
                        {{ rejectTargetName }} will not be linked to this
                        household.
                    </p>
                    <div class="confirm-modal__actions">
                        <button
                            @click="showRejectModal = false"
                            class="btn-ghost"
                        >
                            Cancel
                        </button>
                        <button @click="executeReject" class="btn-danger">
                            Reject
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <transition name="toast">
            <div v-if="flashMsg" class="toast">{{ flashMsg }}</div>
        </transition>
    </AppLayout>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap');

.page-root,
.modal-backdrop,
.toast {
    --c-primary: #ea580c;
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
    background: #fff;
    border: 1px solid #e4e8ef;
    border-radius: 16px;
    padding: 20px 22px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
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
    align-items: center;
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
    background: #fff;
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
.chip-sep {
    width: 1px;
    height: 18px;
    background: #e4e8ef;
    margin: 0 4px;
}

.table-card {
    background: #fff;
    border: 1px solid #e4e8ef;
    border-radius: 16px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
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

.person-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}
.avatar {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    background: #fff7ed;
    border: 1px solid #fed7aa;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 800;
    color: #ea580c;
    flex-shrink: 0;
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
.bg-emerald-50 {
    background: #ecfdf5;
}
.text-emerald-700 {
    color: #047857;
}
.bg-red-50 {
    background: #fef2f2;
}
.text-red-600 {
    color: #dc2626;
}
.bg-orange-50 {
    background: #fff7ed;
}
.text-orange-700 {
    color: #c2410c;
}
.bg-amber-50 {
    background: #fffbeb;
}
.text-amber-700 {
    color: #b45309;
}

.td-announce__title {
    font-weight: 600;
    color: #1a2332;
}
.td-announce__sub {
    font-size: 12px;
    color: #94a3b8;
}
.td-time {
    color: #94a3b8;
    white-space: nowrap;
    font-size: 12px;
}

.btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #ea580c !important;
    color: #fff !important;
    border: none;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.18s;
    font-family: 'DM Sans', system-ui, sans-serif;
}
.btn-primary:hover:not(:disabled) {
    background: #c2410c !important;
}
.btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.btn-ghost {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #f1f5f9;
    color: #64748b;
    border: none;
    border-radius: 10px;
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
}
.btn-danger:hover {
    background: #b91c1c;
}

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(10, 18, 30, 0.55) !important;
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 24px;
}
.confirm-modal {
    background: #fff !important;
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
    padding: 10px 18px;
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
    border-left: 3px solid #ea580c;
}

.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.22s ease;
}
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
    .table-card {
        overflow-x: auto;
    }
    .data-table {
        min-width: 700px;
    }
}
</style>

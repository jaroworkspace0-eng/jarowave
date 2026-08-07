<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import {
    CheckCircle,
    Clock,
    Lock,
    RefreshCw,
    Send,
    Ticket as TicketIcon,
    User,
    XCircle,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

const auth = useAuthStore();

const breadcrumbs: BreadcrumbItem[] = [];

onMounted(() => {
    if (auth.user?.role !== 'estate_billing') {
        router.visit('/dashboard');
    }
});

// ── Types ─────────────────────────────────────────────────────────────────
interface TicketUser {
    id: number;
    name: string;
    email?: string;
}

interface TicketReply {
    id: number;
    message: string;
    is_internal_note: boolean;
    created_at: string;
    user: TicketUser & { role?: string };
}

interface TicketRow {
    id: number;
    ticket_number: string;
    category: string;
    subject: string;
    description: string;
    status: 'open' | 'in_progress' | 'resolved' | 'closed';
    priority: 'low' | 'medium' | 'high' | 'urgent';
    created_at: string;
    user: TicketUser;
}

interface TicketDetail extends TicketRow {
    replies: TicketReply[];
    assignee: TicketUser | null;
}

const pagination = ref<{
    current_page: number;
    last_page: number;
    total: number;
} | null>(null);

// ── State ─────────────────────────────────────────────────────────────────
const tickets = ref<TicketRow[]>([]);

const isLoading = ref(true);
const flash = ref<{ msg: string; type: 'success' | 'error' } | null>(null);
const statusFilter = ref<
    'all' | 'open' | 'in_progress' | 'resolved' | 'closed'
>('all');

const selected = ref<TicketDetail | null>(null);
const showDetailModal = ref(false);
const isLoadingDetail = ref(false);

const replyMessage = ref('');
const replyIsInternal = ref(false);
const isSubmittingReply = ref(false);
const isUpdatingStatus = ref(false);

// ── Helpers ───────────────────────────────────────────────────────────────
const getHeaders = () => ({
    headers: { Authorization: `Bearer ${localStorage.getItem('token')}` },
});

const base = `${import.meta.env.VITE_APP_URL}/api/estate/tickets`;

const showFlash = (msg: string, type: 'success' | 'error' = 'success') => {
    flash.value = { msg, type };
    setTimeout(() => (flash.value = null), 6000);
};

const formatDate = (d: string) =>
    new Date(d).toLocaleDateString('en-ZA', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

const categoryLabels: Record<string, string> = {
    maintenance: 'Maintenance Request',
    security_concern: 'Security Concern',
    general_query: 'General Query',
    complaint: 'Complaint',
    other: 'Other',
};

const statusConfig: Record<string, { label: string; cls: string; icon: any }> =
    {
        open: {
            label: 'Open',
            cls: 'bg-orange-50 text-orange-700',
            icon: Clock,
        },
        in_progress: {
            label: 'In Progress',
            cls: 'bg-blue-50 text-blue-700',
            icon: RefreshCw,
        },
        resolved: {
            label: 'Resolved',
            cls: 'bg-emerald-50 text-emerald-700',
            icon: CheckCircle,
        },
        closed: {
            label: 'Closed',
            cls: 'bg-slate-100 text-slate-500',
            icon: XCircle,
        },
    };

const priorityConfig: Record<string, string> = {
    low: 'bg-slate-100 text-slate-500',
    medium: 'bg-blue-50 text-blue-700',
    high: 'bg-orange-50 text-orange-700',
    urgent: 'bg-red-50 text-red-600',
};

const statusOptions = [
    { value: 'all', label: 'All' },
    { value: 'open', label: 'Open' },
    { value: 'in_progress', label: 'In Progress' },
    { value: 'resolved', label: 'Resolved' },
    { value: 'closed', label: 'Closed' },
] as const;

// ── Data ──────────────────────────────────────────────────────────────────
const fetchTickets = async () => {
    isLoading.value = true;
    try {
        const params =
            statusFilter.value !== 'all' ? { status: statusFilter.value } : {};
        const res = await axios.get(base, { ...getHeaders(), params });
        // tickets.value = res.data.tickets;
        tickets.value = res.data.tickets.data;

        pagination.value = {
            current_page: res.data.tickets.current_page,
            last_page: res.data.tickets.last_page,
            total: res.data.tickets.total,
        };
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to load tickets.',
            'error',
        );
    } finally {
        isLoading.value = false;
    }
};

onMounted(fetchTickets);

const setStatusFilter = (s: (typeof statusOptions)[number]['value']) => {
    statusFilter.value = s as any;
    fetchTickets();
};

const openTicket = async (id: number) => {
    showDetailModal.value = true;
    isLoadingDetail.value = true;
    selected.value = null;
    replyMessage.value = '';
    replyIsInternal.value = false;

    try {
        const res = await axios.get(`${base}/${id}`, getHeaders());
        selected.value = res.data.ticket;
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to load ticket.',
            'error',
        );
        showDetailModal.value = false;
    } finally {
        isLoadingDetail.value = false;
    }
};

const closeModal = () => {
    showDetailModal.value = false;
    selected.value = null;
    replyMessage.value = '';
    replyIsInternal.value = false;
};

const submitReply = async () => {
    if (!selected.value || !replyMessage.value.trim()) return;
    isSubmittingReply.value = true;

    try {
        await axios.post(
            `${base}/${selected.value.id}/reply`,
            {
                message: replyMessage.value,
                is_internal_note: replyIsInternal.value,
            },
            getHeaders(),
        );
        replyMessage.value = '';
        replyIsInternal.value = false;
        await openTicket(selected.value.id);
        await fetchTickets();
        showFlash('Reply sent.');
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to send reply.',
            'error',
        );
    } finally {
        isSubmittingReply.value = false;
    }
};

const updateStatus = async (status: string) => {
    if (!selected.value) return;
    isUpdatingStatus.value = true;

    try {
        const res = await axios.patch(
            `${base}/${selected.value.id}/status`,
            { status },
            getHeaders(),
        );
        selected.value = { ...selected.value, ...res.data.ticket };
        await fetchTickets();
        showFlash('Status updated.');
    } catch (err: any) {
        showFlash(
            err.response?.data?.message ?? 'Failed to update status.',
            'error',
        );
    } finally {
        isUpdatingStatus.value = false;
    }
};

// ── Computed ──────────────────────────────────────────────────────────────
const statusCounts = computed(() => {
    const counts: Record<string, number> = {
        all: tickets.value.length,
        open: 0,
        in_progress: 0,
        resolved: 0,
        closed: 0,
    };
    tickets.value.forEach((t) => counts[t.status]++);
    return counts;
});
</script>

<template>
    <Head title="Estate Tickets" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="page-root">
            <!-- PAGE HEADER -->
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Support</div>
                    <h1 class="page-header__title">Estate Tickets</h1>
                    <p class="page-header__sub">
                        Household maintenance &amp; general queries
                    </p>
                </div>
                <div class="page-header__right">
                    <button class="btn-secondary" @click="fetchTickets">
                        <RefreshCw :size="14" stroke-width="2" />
                        Refresh
                    </button>
                </div>
            </div>

            <!-- STAT CARDS -->
            <div class="stat-row stat-row--five">
                <div class="stat-card">
                    <div class="stat-card__label">Total</div>
                    <div class="stat-card__value">{{ statusCounts.all }}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Open</div>
                    <div class="stat-card__value stat-card__value--orange">
                        {{ statusCounts.open }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">In Progress</div>
                    <div class="stat-card__value stat-card__value--blue">
                        {{ statusCounts.in_progress }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Resolved</div>
                    <div class="stat-card__value stat-card__value--green">
                        {{ statusCounts.resolved }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Closed</div>
                    <div class="stat-card__value">
                        {{ statusCounts.closed }}
                    </div>
                </div>
            </div>

            <!-- FILTER BAR -->
            <div class="filter-card">
                <div class="filter-groups">
                    <div class="filter-group">
                        <span class="filter-group__label">Status</span>
                        <div class="filter-bar__chips">
                            <button
                                v-for="f in statusOptions"
                                :key="f.value"
                                class="chip"
                                :class="{
                                    'chip--active': statusFilter === f.value,
                                }"
                                @click="setStatusFilter(f.value)"
                            >
                                {{ f.label }}
                                <span class="chip__count">{{
                                    statusCounts[f.value]
                                }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TABLE CARD -->
            <div class="table-card">
                <div v-if="isLoading" class="empty-state">
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
                        >Loading tickets…</span
                    >
                </div>

                <div v-else-if="!tickets.length" class="empty-state">
                    <div class="empty-state__icon">
                        <TicketIcon :size="26" stroke-width="1.4" />
                    </div>
                    <p class="empty-state__title">No tickets found</p>
                    <p class="empty-state__sub">
                        Nothing matches this filter right now
                    </p>
                </div>

                <table v-else class="data-table">
                    <thead>
                        <tr>
                            <th>Ticket</th>
                            <th>Resident</th>
                            <th>Category</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="t in tickets"
                            :key="t.id"
                            class="clickable-row"
                            @click="openTicket(t.id)"
                        >
                            <td>
                                <div class="ticket-cell">
                                    <span class="ticket-cell__number">{{
                                        t.ticket_number
                                    }}</span>
                                    <span class="ticket-cell__subject">{{
                                        t.subject
                                    }}</span>
                                </div>
                            </td>
                            <td>
                                <div class="reporter-cell">
                                    <div class="reporter-cell__avatar">
                                        {{
                                            (t.user.name || 'U')
                                                .charAt(0)
                                                .toUpperCase()
                                        }}
                                    </div>
                                    <div>
                                        <div class="reporter-cell__name">
                                            {{ t.user.name }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="td-time">
                                {{ categoryLabels[t.category] ?? t.category }}
                            </td>
                            <td>
                                <span
                                    class="type-badge"
                                    :class="priorityConfig[t.priority]"
                                    >{{ t.priority }}</span
                                >
                            </td>
                            <td>
                                <span
                                    class="type-badge type-badge--icon"
                                    :class="statusConfig[t.status]?.cls"
                                >
                                    <component
                                        :is="statusConfig[t.status]?.icon"
                                        :size="12"
                                        stroke-width="2.5"
                                    />
                                    {{ statusConfig[t.status]?.label }}
                                </span>
                            </td>
                            <td class="td-time">
                                {{ formatDate(t.created_at) }}
                            </td>
                            <td>
                                <button
                                    class="row-action-btn"
                                    @click.stop="openTicket(t.id)"
                                >
                                    View
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ═══════════════ DETAIL MODAL ═══════════════ -->
        <Teleport to="body">
            <transition name="modal">
                <div
                    v-if="showDetailModal"
                    class="modal-backdrop"
                    @click.self="closeModal"
                >
                    <div class="modal-sheet">
                        <div v-if="isLoadingDetail" class="modal-sheet__header">
                            <div class="modal-sheet__header-left">
                                <div class="modal-sheet__title">Loading…</div>
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
                        <div
                            v-if="isLoadingDetail"
                            class="flex items-center justify-center py-16"
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
                        </div>

                        <template v-else-if="selected">
                            <div class="modal-sheet__header">
                                <div class="modal-sheet__header-left">
                                    <div>
                                        <div class="modal-sheet__sub">
                                            {{ selected.ticket_number }}
                                        </div>
                                        <div class="modal-sheet__title">
                                            {{ selected.subject }}
                                        </div>
                                    </div>
                                </div>
                                <select
                                    class="status-select"
                                    :value="selected.status"
                                    :disabled="isUpdatingStatus"
                                    @change="
                                        updateStatus(
                                            ($event.target as HTMLSelectElement)
                                                .value,
                                        )
                                    "
                                >
                                    <option value="open">Open</option>
                                    <option value="in_progress">
                                        In Progress
                                    </option>
                                    <option value="resolved">Resolved</option>
                                    <option value="closed">Closed</option>
                                </select>
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

                            <div class="modal-sheet__body">
                                <div class="toggle-row">
                                    <span
                                        class="type-badge bg-slate-100 text-slate-600"
                                        >{{
                                            categoryLabels[selected.category] ??
                                            selected.category
                                        }}</span
                                    >
                                    <span
                                        class="type-badge"
                                        :class="
                                            priorityConfig[selected.priority]
                                        "
                                        >{{ selected.priority }}</span
                                    >
                                </div>

                                <div class="review-info-panel">
                                    <div class="field__label">Resident</div>
                                    <div class="review-info-panel__name">
                                        {{ selected.user.name }}
                                    </div>
                                    <div class="review-info-panel__sub">
                                        {{ selected.user.email }}
                                    </div>
                                </div>

                                <div class="thread">
                                    <div class="thread-msg thread-msg--origin">
                                        <div class="thread-msg__head">
                                            <User :size="13" stroke-width="2" />
                                            {{ selected.user.name }}
                                            <span class="thread-msg__time">{{
                                                formatDate(selected.created_at)
                                            }}</span>
                                        </div>
                                        <p>{{ selected.description }}</p>
                                    </div>

                                    <div
                                        v-for="r in selected.replies"
                                        :key="r.id"
                                        class="thread-msg"
                                        :class="
                                            r.is_internal_note
                                                ? 'thread-msg--internal'
                                                : 'thread-msg--reply'
                                        "
                                    >
                                        <div class="thread-msg__head">
                                            <Lock
                                                v-if="r.is_internal_note"
                                                :size="12"
                                                stroke-width="2"
                                            />
                                            <User
                                                v-else
                                                :size="13"
                                                stroke-width="2"
                                            />
                                            {{ r.user.name }}
                                            <span
                                                v-if="r.is_internal_note"
                                                class="internal-tag"
                                                >Internal Note</span
                                            >
                                            <span class="thread-msg__time">{{
                                                formatDate(r.created_at)
                                            }}</span>
                                        </div>
                                        <p>{{ r.message }}</p>
                                    </div>
                                </div>

                                <div class="field">
                                    <label class="field__label">Reply</label>
                                    <textarea
                                        v-model="replyMessage"
                                        rows="3"
                                        placeholder="Write a reply…"
                                        class="field__input field__textarea"
                                    ></textarea>
                                </div>

                                <div class="reply-box-actions">
                                    <label class="internal-check">
                                        <input
                                            type="checkbox"
                                            v-model="replyIsInternal"
                                        />
                                        Internal note (hidden from household)
                                    </label>
                                    <button
                                        class="btn-primary"
                                        :disabled="
                                            isSubmittingReply ||
                                            !replyMessage.trim()
                                        "
                                        @click="submitReply"
                                    >
                                        <svg
                                            v-if="isSubmittingReply"
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
                                        <template v-else>
                                            <Send :size="14" stroke-width="2" />
                                            Send
                                        </template>
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </transition>
        </Teleport>

        <!-- ═══════════════ FLASH TOAST ═══════════════ -->
        <Teleport to="body">
            <transition name="modal">
                <div
                    v-if="flash"
                    class="toast"
                    :class="
                        flash.type === 'success'
                            ? 'toast--success'
                            : 'toast--error'
                    "
                >
                    {{ flash.msg }}
                </div>
            </transition>
        </Teleport>
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
    flex-wrap: wrap;
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
.page-header__sub {
    font-size: 13px;
    color: #64748b;
    margin: 4px 0 0;
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
.btn-secondary:hover:not(:disabled) {
    border-color: #ea580c;
    color: #ea580c;
    background: #fff7ed;
}

/* STAT ROW */
.stat-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
}
.stat-row--five {
    grid-template-columns: repeat(5, 1fr);
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
.stat-card__value--orange {
    color: #ea580c;
}
.stat-card__value--blue {
    color: #2563eb;
}
.stat-card__value--green {
    color: #059669;
}

/* FILTER CARD */
.filter-card {
    background: #ffffff;
    border: 1px solid #e4e8ef;
    border-radius: 16px;
    box-shadow: var(--shadow-sm);
    padding: 16px 20px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.filter-groups {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
}
.filter-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.filter-group__label {
    font-size: 11px;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.6px;
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
    padding: 5px 14px;
    border-radius: 20px;
    border: 1px solid #e4e8ef;
    background: #ffffff;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
    transition: all 0.15s;
    font-family: inherit;
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
.chip__count {
    font-size: 10px;
    opacity: 0.8;
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
.data-table td {
    padding: 13px 16px;
    vertical-align: middle;
}
.clickable-row {
    cursor: pointer;
}
.clickable-row:hover {
    background: #fafbfc;
}

.ticket-cell {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.ticket-cell__number {
    font-family: ui-monospace, SFMono-Regular, monospace;
    font-size: 11px;
    font-weight: 700;
    color: #94a3b8;
}
.ticket-cell__subject {
    font-size: 13px;
    font-weight: 700;
    color: #1a2332;
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
    text-transform: capitalize;
}
.type-badge--icon {
    padding: 4px 10px 4px 8px;
}

.reporter-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}
.reporter-cell__avatar {
    width: 32px;
    height: 32px;
    border-radius: 10px;
    background: linear-gradient(135deg, #ea580c, #c2410c);
    color: #fff;
    font-size: 12px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.reporter-cell__name {
    font-size: 13px;
    font-weight: 700;
    color: #1a2332;
}
.td-time {
    color: #64748b;
    white-space: nowrap;
    font-size: 12px;
}

.row-action-btn {
    padding: 6px 12px;
    border-radius: 8px;
    border: 1.5px solid #e4e8ef;
    background: #ffffff;
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    cursor: pointer;
    transition: all 0.15s;
    font-family: inherit;
    white-space: nowrap;
}
.row-action-btn:hover {
    border-color: #ea580c;
    color: #ea580c;
    background: #fff7ed;
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
    max-width: 620px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.18);
    border: 1px solid #e4e8ef;
}
.modal-sheet__header {
    display: flex;
    align-items: center;
    gap: 12px;
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
    font-size: 11px;
    font-weight: 700;
    color: #94a3b8;
    font-family: ui-monospace, SFMono-Regular, monospace;
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

.status-select {
    padding: 8px 12px;
    border: 1.5px solid #e4e8ef;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    color: #1a2332;
    background: #f8fafc;
    cursor: pointer;
    font-family: inherit;
    flex-shrink: 0;
}

.toggle-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.review-info-panel {
    background: #f8fafc;
    border: 1.5px solid #e4e8ef;
    border-radius: 10px;
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.review-info-panel__name {
    font-size: 14px;
    font-weight: 700;
    color: #1a2332;
}
.review-info-panel__sub {
    font-size: 12px;
    color: #94a3b8;
}

/* THREAD */
.thread {
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-height: 320px;
    overflow-y: auto;
    padding-right: 4px;
}
.thread-msg {
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 13px;
    border: 1.5px solid #e4e8ef;
}
.thread-msg p {
    margin: 0;
    color: #475569;
    line-height: 1.55;
}
.thread-msg--origin {
    background: #f8fafc;
}
.thread-msg--reply {
    background: #eff6ff;
    border-color: #bfdbfe;
}
.thread-msg--internal {
    background: #fff7ed;
    border-color: #fed7aa;
}
.thread-msg__head {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    margin-bottom: 6px;
}
.internal-tag {
    background: #ea580c;
    color: #fff;
    padding: 1px 7px;
    border-radius: 100px;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.thread-msg__time {
    margin-left: auto;
    font-weight: 500;
    color: #94a3b8;
}

/* FIELDS */
.field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.field__label {
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    letter-spacing: 0.3px;
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
.field__textarea {
    resize: vertical;
    min-height: 70px;
    line-height: 1.6;
}

.reply-box-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.internal-check {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #64748b;
    cursor: pointer;
}

/* TOAST */
.toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 10000;
    padding: 12px 20px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 700;
    box-shadow: var(--shadow-lg);
    color: #fff;
}
.toast--success {
    background: #059669;
}
.toast--error {
    background: #dc2626;
}

/* TRANSITIONS */
.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.22s ease;
}
.modal-enter-active .modal-sheet,
.modal-leave-active .modal-sheet {
    transition:
        transform 0.22s ease,
        opacity 0.22s ease;
}
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
.modal-enter-from .modal-sheet,
.modal-leave-to .modal-sheet {
    transform: scale(0.97) translateY(12px);
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
@media (max-width: 1024px) {
    .stat-row--five {
        grid-template-columns: repeat(3, 1fr);
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
    .stat-card {
        padding: 14px;
    }
    .stat-card__value {
        font-size: 22px;
    }
    .data-table {
        min-width: 720px;
    }
    .table-card {
        overflow-x: auto;
    }
}
</style>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
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
        open: { label: 'Open', cls: 'open', icon: Clock },
        in_progress: {
            label: 'In Progress',
            cls: 'in_progress',
            icon: RefreshCw,
        },
        resolved: { label: 'Resolved', cls: 'resolved', icon: CheckCircle },
        closed: { label: 'Closed', cls: 'closed', icon: XCircle },
    };

// ── Data ──────────────────────────────────────────────────────────────────
const fetchTickets = async () => {
    isLoading.value = true;
    try {
        const params =
            statusFilter.value !== 'all' ? { status: statusFilter.value } : {};
        const res = await axios.get(base, { ...getHeaders(), params });
        tickets.value = res.data.tickets;
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
    <AppLayout>
        <div class="pt-root">
            <div class="pt-header">
                <div class="pt-header-left">
                    <div class="pt-icon">
                        <TicketIcon :size="20" stroke-width="2" />
                    </div>
                    <div>
                        <h1 class="pt-title">Estate Tickets</h1>
                        <p class="pt-sub">
                            Household maintenance &amp; general queries
                        </p>
                    </div>
                </div>
                <button class="btn-icon" @click="fetchTickets" title="Refresh">
                    <RefreshCw :size="16" stroke-width="2" />
                </button>
            </div>

            <div v-if="flash" :class="['flash', flash.type]">
                {{ flash.type === 'success' ? '✓' : '⚠' }} {{ flash.msg }}
            </div>

            <div class="pt-filters">
                <button
                    v-for="s in [
                        'all',
                        'open',
                        'in_progress',
                        'resolved',
                        'closed',
                    ]"
                    :key="s"
                    :class="['pt-filter', { active: statusFilter === s }]"
                    @click="
                        statusFilter = s as any;
                        fetchTickets();
                    "
                >
                    {{ s === 'all' ? 'All' : statusConfig[s]?.label }}
                    <span class="pt-filter-count">{{ statusCounts[s] }}</span>
                </button>
            </div>

            <div v-if="isLoading" class="loading">
                <div class="spinner"></div>
                <p>Loading tickets…</p>
            </div>

            <div v-else-if="!tickets.length" class="empty-card">
                <TicketIcon :size="32" stroke-width="1.5" color="#bbb" />
                <div class="empty-title">No tickets found</div>
                <div class="empty-desc">
                    Nothing matches this filter right now.
                </div>
            </div>

            <div v-else class="pt-list">
                <div
                    v-for="t in tickets"
                    :key="t.id"
                    class="pt-card"
                    @click="openTicket(t.id)"
                >
                    <div class="pt-card-left">
                        <div class="pt-card-top">
                            <span class="pt-number">{{ t.ticket_number }}</span>
                            <span :class="['pri-badge', t.priority]">{{
                                t.priority
                            }}</span>
                        </div>
                        <div class="pt-subject">{{ t.subject }}</div>
                        <div class="pt-meta">
                            {{ categoryLabels[t.category] ?? t.category }} ·
                            {{ t.user.name }} · {{ formatDate(t.created_at) }}
                        </div>
                    </div>
                    <span
                        :class="['status-badge', statusConfig[t.status]?.cls]"
                    >
                        <component
                            :is="statusConfig[t.status]?.icon"
                            :size="12"
                            stroke-width="2.5"
                        />
                        {{ statusConfig[t.status]?.label }}
                    </span>
                </div>
            </div>

            <!-- ── DETAIL MODAL ────────────────────────────────────────── -->
            <div
                v-if="showDetailModal"
                class="modal-overlay"
                @click.self="showDetailModal = false"
            >
                <div class="modal modal-lg">
                    <div v-if="isLoadingDetail" class="loading">
                        <div class="spinner"></div>
                    </div>

                    <template v-else-if="selected">
                        <div class="modal-title-row">
                            <div>
                                <p class="pt-number">
                                    {{ selected.ticket_number }}
                                </p>
                                <div class="modal-title">
                                    {{ selected.subject }}
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
                                <option value="in_progress">In Progress</option>
                                <option value="resolved">Resolved</option>
                                <option value="closed">Closed</option>
                            </select>
                        </div>

                        <div class="pt-meta" style="margin-bottom: 16px">
                            {{
                                categoryLabels[selected.category] ??
                                selected.category
                            }}
                            · {{ selected.user.name }} ({{
                                selected.user.email
                            }}) ·
                            <span :class="['pri-badge', selected.priority]">{{
                                selected.priority
                            }}</span>
                        </div>

                        <div class="thread">
                            <div class="thread-msg origin">
                                <div class="thread-msg-head">
                                    <User :size="13" stroke-width="2" />
                                    {{ selected.user.name }}
                                    <span class="thread-time">{{
                                        formatDate(selected.created_at)
                                    }}</span>
                                </div>
                                <p>{{ selected.description }}</p>
                            </div>

                            <div
                                v-for="r in selected.replies"
                                :key="r.id"
                                :class="[
                                    'thread-msg',
                                    r.is_internal_note ? 'internal' : 'reply',
                                ]"
                            >
                                <div class="thread-msg-head">
                                    <Lock
                                        v-if="r.is_internal_note"
                                        :size="12"
                                        stroke-width="2"
                                    />
                                    <User v-else :size="13" stroke-width="2" />
                                    {{ r.user.name }}
                                    <span
                                        v-if="r.is_internal_note"
                                        class="internal-tag"
                                        >Internal Note</span
                                    >
                                    <span class="thread-time">{{
                                        formatDate(r.created_at)
                                    }}</span>
                                </div>
                                <p>{{ r.message }}</p>
                            </div>
                        </div>

                        <div class="reply-box">
                            <textarea
                                v-model="replyMessage"
                                rows="3"
                                placeholder="Write a reply…"
                                class="mi"
                            ></textarea>
                            <div class="reply-box-actions">
                                <label class="internal-check">
                                    <input
                                        type="checkbox"
                                        v-model="replyIsInternal"
                                    />
                                    Internal note (hidden from household)
                                </label>
                                <button
                                    class="btn-process"
                                    :disabled="
                                        isSubmittingReply ||
                                        !replyMessage.trim()
                                    "
                                    @click="submitReply"
                                >
                                    <span
                                        v-if="isSubmittingReply"
                                        class="btn-spinner"
                                    ></span>
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
        </div>
    </AppLayout>
</template>

<style scoped>
.pt-root {
    max-width: 1000px;
    margin: 0 auto;
    padding: 36px 24px 64px;
    font-family: 'Segoe UI', sans-serif;
    color: #111;
    width: 100%;
}
.pt-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}
.pt-header-left {
    display: flex;
    align-items: center;
    gap: 14px;
}
.pt-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: linear-gradient(135deg, #f97316, #ea580c);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.pt-title {
    font-size: 22px;
    font-weight: 800;
    letter-spacing: -0.5px;
    margin: 0 0 2px;
}
.pt-sub {
    font-size: 13px;
    color: #888;
    margin: 0;
}
.btn-icon {
    padding: 8px;
    border: 1.5px solid #e5e5e5;
    border-radius: 10px;
    background: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    color: #555;
    transition: all 0.2s;
}
.btn-icon:hover {
    border-color: #f97316;
    color: #f97316;
}
.flash {
    padding: 11px 16px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 18px;
}
.flash.success {
    background: #dcfce7;
    border: 1.5px solid #86efac;
    color: #16a34a;
}
.flash.error {
    background: #fef2f2;
    border: 1.5px solid #fecaca;
    color: #dc2626;
}
.loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    padding: 80px 0;
    color: #999;
}
.pt-filters {
    display: flex;
    gap: 8px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}
.pt-filter {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: #fff;
    border: 1.5px solid #ebebeb;
    border-radius: 100px;
    font-size: 13px;
    font-weight: 600;
    color: #888;
    cursor: pointer;
    font-family: 'Segoe UI', sans-serif;
    transition: all 0.15s;
}
.pt-filter:hover {
    border-color: #f97316;
    color: #f97316;
}
.pt-filter.active {
    background: #f97316;
    border-color: #f97316;
    color: #fff;
}
.pt-filter-count {
    font-size: 11px;
    opacity: 0.8;
}
.pt-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.pt-card {
    background: #fff;
    border: 1.5px solid #ebebeb;
    border-radius: 14px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    cursor: pointer;
    transition: all 0.15s;
}
.pt-card:hover {
    border-color: #f97316;
    box-shadow: 0 2px 8px rgba(249, 115, 22, 0.08);
}
.pt-card-top {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
}
.pt-number {
    font-size: 11px;
    font-weight: 700;
    color: #aaa;
    font-family: 'Courier New', monospace;
}
.pt-subject {
    font-size: 14px;
    font-weight: 700;
    color: #111;
}
.pt-meta {
    font-size: 12px;
    color: #888;
    margin-top: 2px;
}
.pri-badge {
    padding: 2px 8px;
    border-radius: 100px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.pri-badge.low {
    background: rgba(100, 100, 100, 0.1);
    color: #888;
}
.pri-badge.medium {
    background: rgba(37, 99, 235, 0.1);
    color: #2563eb;
}
.pri-badge.high {
    background: rgba(249, 115, 22, 0.1);
    color: #f97316;
}
.pri-badge.urgent {
    background: rgba(220, 38, 38, 0.1);
    color: #dc2626;
}
.status-badge {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 100px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
    flex-shrink: 0;
}
.status-badge.open {
    background: rgba(249, 115, 22, 0.1);
    color: #f97316;
}
.status-badge.in_progress {
    background: rgba(37, 99, 235, 0.1);
    color: #2563eb;
}
.status-badge.resolved {
    background: rgba(22, 163, 74, 0.1);
    color: #16a34a;
}
.status-badge.closed {
    background: rgba(100, 100, 100, 0.1);
    color: #888;
}
.empty-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 64px 24px;
    text-align: center;
    background: #fff;
    border: 1.5px solid #ebebeb;
    border-radius: 16px;
}
.empty-title {
    font-size: 14px;
    font-weight: 700;
    color: #111;
}
.empty-desc {
    font-size: 13px;
    color: #999;
}
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.45);
    z-index: 500;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal {
    background: #fff;
    border-radius: 20px;
    padding: 32px;
    width: 100%;
    max-width: 520px;
    box-shadow: 0 24px 64px rgba(0, 0, 0, 0.18);
    max-height: 90vh;
    overflow-y: auto;
}
.modal-lg {
    max-width: 640px;
}
.modal-title-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 4px;
}
.modal-title {
    font-size: 18px;
    font-weight: 800;
    color: #111;
}
.status-select {
    padding: 7px 12px;
    border: 1.5px solid #e5e5e5;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    color: #111;
    background: #fff;
    cursor: pointer;
    font-family: 'Segoe UI', sans-serif;
}
.thread {
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-height: 320px;
    overflow-y: auto;
    margin-bottom: 16px;
    padding-right: 4px;
}
.thread-msg {
    border-radius: 12px;
    padding: 12px 14px;
    font-size: 13px;
}
.thread-msg p {
    margin: 0;
    color: #333;
    line-height: 1.5;
}
.thread-msg.origin {
    background: #f9f9f9;
    border: 1.5px solid #ebebeb;
}
.thread-msg.reply {
    background: rgba(37, 99, 235, 0.05);
    border: 1.5px solid rgba(37, 99, 235, 0.15);
}
.thread-msg.internal {
    background: #fff7ed;
    border: 1.5px solid #fed7aa;
}
.thread-msg-head {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 700;
    color: #555;
    margin-bottom: 6px;
}
.internal-tag {
    background: #f97316;
    color: #fff;
    padding: 1px 7px;
    border-radius: 100px;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.thread-time {
    margin-left: auto;
    font-weight: 500;
    color: #aaa;
}
.reply-box {
    border-top: 1.5px solid #ebebeb;
    padding-top: 16px;
}
.mi {
    width: 100%;
    padding: 10px 14px;
    border: 1.5px solid #e5e5e5;
    border-radius: 10px;
    font-size: 13px;
    font-family: 'Segoe UI', sans-serif;
    color: #111;
    outline: none;
    box-sizing: border-box;
    resize: vertical;
}
.mi:focus {
    border-color: #f97316;
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
}
.reply-box-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 10px;
    gap: 12px;
    flex-wrap: wrap;
}
.internal-check {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #888;
    cursor: pointer;
}
.btn-process {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 9px 18px;
    background: #f97316;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    font-family: 'Segoe UI', sans-serif;
}
.btn-process:hover:not(:disabled) {
    background: #ea580c;
}
.btn-process:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.btn-spinner {
    width: 14px;
    height: 14px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
    display: inline-block;
}
.spinner {
    width: 28px;
    height: 28px;
    border: 3px solid #f0f0f0;
    border-top-color: #f97316;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
</style>

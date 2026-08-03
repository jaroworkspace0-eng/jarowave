<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import {
    CheckCircle2,
    RefreshCw,
    Search,
    ShieldAlert,
    Trash2,
    X,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

const auth = useAuthStore();

onMounted(() => {
    if (auth.user?.role !== 'admin') {
        router.visit('/dashboard');
        return;
    }
    load();
});

const requests = ref<any[]>([]);
const loading = ref(true);
const filter = ref<'all' | 'pending' | 'processing' | 'deleted' | 'cancelled'>(
    'all',
);
const search = ref('');
const selectedRequest = ref<any>(null);
const showPanel = ref(false);
const isProcessing = ref(false);
const flashMsg = ref('');
const flashType = ref<'success' | 'error'>('success');
const confirmAction = ref<{ type: 'delete' | 'cancel'; request: any } | null>(
    null,
);

const pagination = ref({
    current_page: 1,
    last_page: 1,
    total: 0,
    from: 0,
    to: 0,
    links: [] as any[],
});

const authHeaders = () => ({
    Authorization: `Bearer ${localStorage.getItem('token')}`,
});

async function load(url?: string) {
    loading.value = true;
    try {
        const endpoint =
            url ||
            `${import.meta.env.VITE_APP_URL}/api/account/deletion-requests`;
        const { data } = await axios.get(endpoint, { headers: authHeaders() });
        requests.value = data.data;
        pagination.value = {
            current_page: data.current_page,
            last_page: data.last_page,
            total: data.total,
            from: data.from,
            to: data.to,
            links: data.links,
        };
    } catch (e) {
        console.error(e);
    } finally {
        loading.value = false;
    }
}

const filterOptions = [
    { value: 'all', label: 'All' },
    { value: 'pending', label: 'Pending' },
    { value: 'processing', label: 'Processing' },
    { value: 'deleted', label: 'Deleted' },
    { value: 'cancelled', label: 'Cancelled' },
] as const;

const filteredRequests = computed(() => {
    return requests.value
        .filter((r) => filter.value === 'all' || r.status === filter.value)
        .filter((r) => {
            if (!search.value) return true;
            const s = search.value.toLowerCase();
            return (
                r.name?.toLowerCase().includes(s) ||
                r.email?.toLowerCase().includes(s) ||
                r.phone?.toLowerCase().includes(s)
            );
        });
});

const stats = computed(() => ({
    total: requests.value.length,
    pending: requests.value.filter((r) => r.status === 'pending').length,
    deleted: requests.value.filter((r) => r.status === 'deleted').length,
    cancelled: requests.value.filter((r) => r.status === 'cancelled').length,
}));

async function proceedDelete() {
    if (!confirmAction.value) return;
    isProcessing.value = true;
    try {
        await axios.delete(
            `${import.meta.env.VITE_APP_URL}/api/account/deletion-requests/${confirmAction.value.request.id}`,
            { headers: authHeaders() },
        );
        flash('Account deleted successfully', 'success');
        if (selectedRequest.value?.id === confirmAction.value.request.id)
            showPanel.value = false;
        await load();
    } catch (e) {
        flash('Failed to delete account', 'error');
    } finally {
        isProcessing.value = false;
        confirmAction.value = null;
    }
}

async function proceedCancel() {
    if (!confirmAction.value) return;
    isProcessing.value = true;
    try {
        await axios.patch(
            `${import.meta.env.VITE_APP_URL}/api/account/deletion-requests/${confirmAction.value.request.id}/cancel`,
            {},
            { headers: authHeaders() },
        );
        flash('Deletion request cancelled — account reactivated', 'success');
        await load();
        if (selectedRequest.value?.id === confirmAction.value.request.id) {
            selectedRequest.value = requests.value.find(
                (r) => r.id === confirmAction.value!.request.id,
            );
        }
    } catch (e) {
        flash('Failed to cancel request', 'error');
    } finally {
        isProcessing.value = false;
        confirmAction.value = null;
    }
}

function flash(msg: string, type: 'success' | 'error' = 'success') {
    flashMsg.value = msg;
    flashType.value = type;
    setTimeout(() => (flashMsg.value = ''), 3500);
}

function formatDate(ts: string) {
    if (!ts) return '—';
    return new Date(ts).toLocaleString('en-ZA', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function daysUntil(ts: string) {
    if (!ts) return null;
    const diff = Math.ceil(
        (new Date(ts).getTime() - Date.now()) / (1000 * 60 * 60 * 24),
    );
    return diff;
}

const statusConfig: Record<string, { label: string; cls: string }> = {
    pending: { label: 'Pending', cls: 'bg-orange-50 text-orange-700' },
    processing: { label: 'Processing', cls: 'bg-blue-50 text-blue-700' },
    deleted: { label: 'Deleted', cls: 'bg-red-50 text-red-600' },
    cancelled: { label: 'Cancelled', cls: 'bg-emerald-50 text-emerald-700' },
};
</script>

<template>
    <Head title="Deletion Requests" />

    <AppLayout>
        <div class="page-root">
            <!-- PAGE HEADER -->
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Compliance</div>
                    <h1 class="page-header__title">
                        Account Deletion Requests
                    </h1>
                    <p class="page-header__sub">
                        Manage user data deletion requests - POPIA compliant
                    </p>
                </div>
                <div class="page-header__right">
                    <button class="btn-secondary" @click="load()">
                        <RefreshCw :size="14" stroke-width="2" />
                        Refresh
                    </button>
                </div>
            </div>

            <!-- STAT CARDS -->
            <div class="stat-row">
                <div class="stat-card">
                    <div class="stat-card__label">Total</div>
                    <div class="stat-card__value">{{ stats.total }}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Pending</div>
                    <div class="stat-card__value stat-card__value--orange">
                        {{ stats.pending }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Deleted</div>
                    <div class="stat-card__value stat-card__value--red">
                        {{ stats.deleted }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Cancelled</div>
                    <div class="stat-card__value stat-card__value--green">
                        {{ stats.cancelled }}
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
                                v-for="f in filterOptions"
                                :key="f.value"
                                class="chip"
                                :class="{ 'chip--active': filter === f.value }"
                                @click="filter = f.value"
                            >
                                {{ f.label }}
                            </button>
                        </div>
                    </div>
                    <div class="search-group">
                        <Search
                            :size="14"
                            stroke-width="2"
                            class="search-group__icon"
                        />
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Search by name, email, phone…"
                            class="search-group__input"
                        />
                    </div>
                </div>
            </div>

            <!-- MAIN CONTENT -->
            <div class="dr-body">
                <div class="table-card dr-table-col">
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

                    <div
                        v-else-if="filteredRequests.length === 0"
                        class="empty-state"
                    >
                        <div class="empty-state__icon">
                            <Trash2 :size="26" stroke-width="1.4" />
                        </div>
                        <p class="empty-state__title">No requests found</p>
                        <p class="empty-state__sub">
                            No deletion requests match your current filter
                        </p>
                    </div>

                    <table v-else class="data-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Contact</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Scheduled</th>
                                <th>Processed by</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="req in filteredRequests"
                                :key="req.id"
                                class="clickable-row"
                                :class="{
                                    'clickable-row--active':
                                        selectedRequest?.id === req.id,
                                }"
                                @click="
                                    selectedRequest = req;
                                    showPanel = true;
                                "
                            >
                                <td>
                                    <div class="ticket-cell">
                                        <span class="ticket-cell__subject">{{
                                            req.name
                                        }}</span>
                                        <span class="ticket-cell__number">{{
                                            formatDate(req.requested_at)
                                        }}</span>
                                    </div>
                                </td>
                                <td class="td-time">
                                    <div>{{ req.email }}</div>
                                    <div class="td-subtext">
                                        {{ req.phone ?? '—' }}
                                    </div>
                                </td>
                                <td class="td-time dr-reason">
                                    {{ req.reason?.replace(/_/g, ' ') ?? '—' }}
                                </td>
                                <td>
                                    <span
                                        class="type-badge"
                                        :class="statusConfig[req.status]?.cls"
                                        >{{
                                            statusConfig[req.status]?.label
                                        }}</span
                                    >
                                </td>
                                <td class="td-time">
                                    <div>
                                        {{
                                            formatDate(
                                                req.scheduled_deletion_at,
                                            )
                                        }}
                                    </div>
                                    <div
                                        v-if="
                                            req.status === 'pending' &&
                                            daysUntil(
                                                req.scheduled_deletion_at,
                                            ) !== null
                                        "
                                        class="td-subtext"
                                        :class="{
                                            'dr-days--urgent':
                                                daysUntil(
                                                    req.scheduled_deletion_at,
                                                )! <= 5,
                                        }"
                                    >
                                        {{
                                            daysUntil(req.scheduled_deletion_at)
                                        }}
                                        days left
                                    </div>
                                </td>
                                <td class="td-time">
                                    <span
                                        v-if="
                                            req.processed_by_type === 'system'
                                        "
                                        >System</span
                                    >
                                    <span
                                        v-else-if="
                                            req.processed_by_type === 'admin'
                                        "
                                        >{{
                                            req.processor?.name ?? 'Admin'
                                        }}</span
                                    >
                                    <span v-else>—</span>
                                </td>
                                <td @click.stop>
                                    <div class="dr-row-actions">
                                        <button
                                            v-if="req.status === 'pending'"
                                            class="icon-btn icon-btn--green"
                                            title="Cancel request & reactivate account"
                                            @click="
                                                confirmAction = {
                                                    type: 'cancel',
                                                    request: req,
                                                }
                                            "
                                        >
                                            <CheckCircle2
                                                :size="15"
                                                stroke-width="2"
                                            />
                                        </button>
                                        <button
                                            v-if="req.status === 'pending'"
                                            class="icon-btn icon-btn--red"
                                            title="Delete account now"
                                            @click="
                                                confirmAction = {
                                                    type: 'delete',
                                                    request: req,
                                                }
                                            "
                                        >
                                            <Trash2
                                                :size="15"
                                                stroke-width="2"
                                            />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- PAGINATION -->
                    <div
                        v-if="!loading && filteredRequests.length"
                        class="dr-pagination"
                    >
                        <span class="dr-pagination__label"
                            >Showing {{ pagination.from }} to
                            {{ pagination.to }} of {{ pagination.total }}</span
                        >
                        <div class="dr-pagination__links">
                            <template
                                v-for="(link, i) in pagination.links"
                                :key="i"
                            >
                                <button
                                    v-if="link.url"
                                    class="chip"
                                    :class="{ 'chip--active': link.active }"
                                    v-html="link.label"
                                    @click="load(link.url)"
                                />
                                <span
                                    v-else
                                    class="chip dr-pagination__disabled"
                                    v-html="link.label"
                                />
                            </template>
                        </div>
                    </div>
                </div>

                <!-- DETAIL PANEL -->
                <transition name="panel">
                    <div
                        v-if="showPanel && selectedRequest"
                        class="table-card dr-detail-col"
                    >
                        <div class="dr-detail__header">
                            <h2 class="dr-detail__title">
                                Request #{{ selectedRequest.id }}
                            </h2>
                            <button
                                class="close-btn"
                                @click="showPanel = false"
                            >
                                <X :size="16" stroke-width="2" />
                            </button>
                        </div>

                        <div class="dr-detail__body">
                            <div class="dr-detail__status-row">
                                <span
                                    class="type-badge"
                                    :class="
                                        statusConfig[selectedRequest.status]
                                            ?.cls
                                    "
                                    >{{
                                        statusConfig[selectedRequest.status]
                                            ?.label
                                    }}</span
                                >
                                <span class="td-subtext"
                                    >Submitted
                                    {{
                                        formatDate(selectedRequest.requested_at)
                                    }}</span
                                >
                            </div>

                            <div class="review-info-panel">
                                <div class="field__label">Requestor</div>
                                <div class="review-info-panel__name">
                                    {{ selectedRequest.name }}
                                </div>
                                <div class="review-info-panel__sub">
                                    {{ selectedRequest.email }}
                                </div>
                                <div class="review-info-panel__sub">
                                    {{ selectedRequest.phone ?? '—' }}
                                </div>
                                <div
                                    v-if="selectedRequest.user_id"
                                    class="dr-linked-id"
                                >
                                    Linked account ID: #{{
                                        selectedRequest.user_id
                                    }}
                                </div>
                                <div v-else class="dr-no-account">
                                    <ShieldAlert :size="12" stroke-width="2" />
                                    No matching user account found
                                </div>
                            </div>

                            <div class="review-info-panel">
                                <div class="field__label">Reason</div>
                                <p class="dr-reason-text">
                                    {{
                                        selectedRequest.reason?.replace(
                                            /_/g,
                                            ' ',
                                        ) ?? 'Not specified'
                                    }}
                                </p>
                                <p
                                    v-if="selectedRequest.notes"
                                    class="dr-quote"
                                >
                                    "{{ selectedRequest.notes }}"
                                </p>
                            </div>

                            <div class="review-info-panel">
                                <div class="field__label">
                                    Deletion Schedule
                                </div>
                                <div class="dr-schedule-row">
                                    <span class="td-subtext">Requested</span>
                                    <span>{{
                                        formatDate(selectedRequest.requested_at)
                                    }}</span>
                                </div>
                                <div class="dr-schedule-row">
                                    <span class="td-subtext"
                                        >Scheduled deletion</span
                                    >
                                    <span
                                        :class="{
                                            'dr-days--urgent':
                                                daysUntil(
                                                    selectedRequest.scheduled_deletion_at,
                                                )! <= 5 &&
                                                selectedRequest.status ===
                                                    'pending',
                                        }"
                                        >{{
                                            formatDate(
                                                selectedRequest.scheduled_deletion_at,
                                            )
                                        }}</span
                                    >
                                </div>
                                <div
                                    v-if="selectedRequest.status === 'pending'"
                                    class="dr-schedule-row"
                                >
                                    <span class="td-subtext"
                                        >Days remaining</span
                                    >
                                    <strong
                                        :class="{
                                            'dr-days--urgent':
                                                daysUntil(
                                                    selectedRequest.scheduled_deletion_at,
                                                )! <= 5,
                                        }"
                                        >{{
                                            daysUntil(
                                                selectedRequest.scheduled_deletion_at,
                                            )
                                        }}
                                        days</strong
                                    >
                                </div>
                                <div
                                    v-if="selectedRequest.processed_at"
                                    class="dr-schedule-row"
                                >
                                    <span class="td-subtext">Processed at</span>
                                    <span>{{
                                        formatDate(selectedRequest.processed_at)
                                    }}</span>
                                </div>
                            </div>

                            <div class="review-info-panel">
                                <div class="field__label">Processed By</div>
                                <p
                                    v-if="
                                        selectedRequest.processed_by_type ===
                                        'system'
                                    "
                                    class="td-time"
                                >
                                    Automated system (cronjob)
                                </p>
                                <p
                                    v-else-if="
                                        selectedRequest.processed_by_type ===
                                        'admin'
                                    "
                                    class="td-time"
                                >
                                    {{
                                        selectedRequest.processor?.name ??
                                        'Admin'
                                    }}
                                </p>
                                <p v-else class="td-subtext">
                                    Not yet processed
                                </p>
                            </div>

                            <div
                                v-if="selectedRequest.admin_notes"
                                class="dr-admin-notes"
                            >
                                <div class="field__label">Admin Notes</div>
                                <p>{{ selectedRequest.admin_notes }}</p>
                            </div>

                            <div
                                v-if="selectedRequest.status === 'pending'"
                                class="dr-detail__actions"
                            >
                                <button
                                    class="btn-primary btn-primary--green"
                                    @click="
                                        confirmAction = {
                                            type: 'cancel',
                                            request: selectedRequest,
                                        }
                                    "
                                >
                                    <CheckCircle2 :size="14" stroke-width="2" />
                                    Cancel &amp; Reactivate
                                </button>
                                <button
                                    class="btn-outline-danger"
                                    @click="
                                        confirmAction = {
                                            type: 'delete',
                                            request: selectedRequest,
                                        }
                                    "
                                >
                                    Delete Now
                                </button>
                            </div>
                        </div>
                    </div>
                </transition>
            </div>
        </div>

        <!-- CANCEL CONFIRMATION MODAL -->
        <Teleport to="body">
            <div
                v-if="confirmAction?.type === 'cancel'"
                class="modal-backdrop"
                @click.self="confirmAction = null"
            >
                <div class="confirm-modal">
                    <div class="confirm-modal__head">
                        <div
                            class="confirm-modal__icon confirm-modal__icon--green"
                        >
                            <CheckCircle2 :size="18" stroke-width="2" />
                        </div>
                        <div>
                            <h3 class="confirm-modal__title">
                                Cancel Deletion Request
                            </h3>
                            <p class="confirm-modal__sub">
                                {{ confirmAction.request.name }} ·
                                {{ confirmAction.request.email }}
                            </p>
                        </div>
                    </div>
                    <div
                        class="confirm-modal__notice confirm-modal__notice--green"
                    >
                        The deletion request will be cancelled and the account
                        will be reactivated. The user will be able to log in
                        again.
                    </div>
                    <div class="confirm-modal__actions">
                        <button
                            class="btn-secondary"
                            @click="confirmAction = null"
                        >
                            Keep Request
                        </button>
                        <button
                            class="btn-primary btn-primary--green"
                            :disabled="isProcessing"
                            @click="proceedCancel"
                        >
                            {{
                                isProcessing
                                    ? 'Processing…'
                                    : 'Yes, Cancel Request'
                            }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- DELETE CONFIRMATION MODAL -->
        <Teleport to="body">
            <div
                v-if="confirmAction?.type === 'delete'"
                class="modal-backdrop"
                @click.self="confirmAction = null"
            >
                <div class="confirm-modal">
                    <div class="confirm-modal__head">
                        <div
                            class="confirm-modal__icon confirm-modal__icon--red"
                        >
                            <Trash2 :size="18" stroke-width="2" />
                        </div>
                        <div>
                            <h3 class="confirm-modal__title">
                                Delete Account Now
                            </h3>
                            <p class="confirm-modal__sub">
                                {{ confirmAction.request.name }} ·
                                {{ confirmAction.request.email }}
                            </p>
                        </div>
                    </div>
                    <div
                        class="confirm-modal__notice confirm-modal__notice--red"
                    >
                        <p class="confirm-modal__notice-title">
                            This will immediately:
                        </p>
                        <ul>
                            <li>Permanently delete all personal data</li>
                            <li>Revoke all active sessions and tokens</li>
                            <li>Remove the employee record</li>
                            <li>This cannot be undone</li>
                        </ul>
                    </div>
                    <div class="confirm-modal__actions">
                        <button
                            class="btn-secondary"
                            @click="confirmAction = null"
                        >
                            Cancel
                        </button>
                        <button
                            class="btn-danger"
                            :disabled="isProcessing"
                            @click="proceedDelete"
                        >
                            {{ isProcessing ? 'Deleting…' : 'Yes, Delete Now' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- FLASH TOAST -->
        <Teleport to="body">
            <transition name="modal">
                <div
                    v-if="flashMsg"
                    class="toast"
                    :class="
                        flashType === 'success'
                            ? 'toast--success'
                            : 'toast--error'
                    "
                >
                    {{ flashMsg }}
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
.stat-card__value--orange {
    color: #ea580c;
}
.stat-card__value--green {
    color: #059669;
}
.stat-card__value--red {
    color: #dc2626;
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
    align-items: center;
    justify-content: space-between;
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

.search-group {
    position: relative;
    display: flex;
    align-items: center;
    min-width: 260px;
}
.search-group__icon {
    position: absolute;
    left: 12px;
    color: #94a3b8;
    pointer-events: none;
}
.search-group__input {
    width: 100%;
    box-sizing: border-box;
    background: #f8fafc;
    border: 1.5px solid #e4e8ef;
    border-radius: 8px;
    padding: 9px 12px 9px 34px;
    font-size: 13px;
    font-family: inherit;
    color: #1a2332;
    outline: none;
    transition:
        border-color 0.15s,
        background 0.15s;
}
.search-group__input:focus {
    border-color: #ea580c;
    background: #fff;
}

/* BODY LAYOUT */
.dr-body {
    display: flex;
    align-items: flex-start;
    gap: 20px;
}
.dr-table-col {
    flex: 1;
    min-width: 0;
}
.dr-detail-col {
    width: 400px;
    flex-shrink: 0;
    max-height: calc(100vh - 260px);
    overflow-y: auto;
}

/* TABLE CARD (shared) */
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
.clickable-row--active {
    background: #fff7ed;
}

.ticket-cell {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.ticket-cell__number {
    font-size: 11px;
    font-weight: 600;
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

.td-time {
    color: #64748b;
    white-space: nowrap;
    font-size: 12px;
}
.td-subtext {
    color: #94a3b8;
    font-size: 11px;
    margin-top: 2px;
}
.dr-reason {
    max-width: 160px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    text-transform: capitalize;
}
.dr-days--urgent {
    color: #dc2626;
    font-weight: 700;
}

.dr-row-actions {
    display: flex;
    align-items: center;
    gap: 6px;
}
.icon-btn {
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    transition: all 0.15s;
}
.icon-btn--green {
    color: #059669;
    background: transparent;
}
.icon-btn--green:hover {
    background: #ecfdf5;
}
.icon-btn--red {
    color: #dc2626;
    background: transparent;
}
.icon-btn--red:hover {
    background: #fef2f2;
}

/* PAGINATION */
.dr-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    border-top: 1px solid #e4e8ef;
    flex-wrap: wrap;
    gap: 10px;
}
.dr-pagination__label {
    font-size: 12px;
    color: #64748b;
}
.dr-pagination__links {
    display: flex;
    gap: 6px;
}
.dr-pagination__disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* DETAIL PANEL */
.dr-detail__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 20px;
    border-bottom: 1px solid #e4e8ef;
}
.dr-detail__title {
    font-size: 14px;
    font-weight: 700;
    color: #1a2332;
}
.close-btn {
    flex-shrink: 0;
    width: 30px;
    height: 30px;
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
.dr-detail__body {
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.dr-detail__status-row {
    display: flex;
    align-items: center;
    gap: 10px;
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
.field__label {
    font-size: 11px;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    margin-bottom: 4px;
}
.review-info-panel__name {
    font-size: 14px;
    font-weight: 700;
    color: #1a2332;
}
.review-info-panel__sub {
    font-size: 12px;
    color: #64748b;
}
.dr-linked-id {
    margin-top: 6px;
    font-size: 11px;
    color: #94a3b8;
}
.dr-no-account {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-top: 6px;
    font-size: 11px;
    color: #d97706;
}
.dr-reason-text {
    font-size: 13px;
    color: #1a2332;
    text-transform: capitalize;
    margin: 0;
}
.dr-quote {
    margin: 8px 0 0;
    padding-top: 8px;
    border-top: 1px solid #e4e8ef;
    font-size: 13px;
    color: #64748b;
    font-style: italic;
}
.dr-schedule-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 13px;
    color: #1a2332;
    padding: 3px 0;
}
.dr-admin-notes {
    background: #fff7ed;
    border: 1.5px solid #fed7aa;
    border-radius: 10px;
    padding: 14px 16px;
}
.dr-admin-notes p {
    margin: 0;
    font-size: 13px;
    color: #9a3412;
}
.dr-detail__actions {
    display: flex;
    gap: 10px;
    padding-top: 4px;
}
.dr-detail__actions .btn-primary--green {
    flex: 1;
    justify-content: center;
}

/* BUTTONS */
.btn-primary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    background: #ea580c;
    color: #ffffff;
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
    background: #c2410c;
    transform: translateY(-1px);
}
.btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}
.btn-primary--green {
    background: #059669;
    box-shadow: 0 2px 8px rgba(5, 150, 105, 0.3);
}
.btn-primary--green:hover:not(:disabled) {
    background: #047857;
}

.btn-outline-danger {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    background: #ffffff;
    color: #dc2626;
    border: 1.5px solid #fecaca;
    border-radius: 12px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.18s;
    white-space: nowrap;
    font-family: 'DM Sans', system-ui, sans-serif;
}
.btn-outline-danger:hover {
    background: #fef2f2;
}

.btn-danger {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #dc2626;
    color: #ffffff;
    border: none;
    border-radius: 12px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.18s;
    white-space: nowrap;
    font-family: 'DM Sans', system-ui, sans-serif;
}
.btn-danger:hover:not(:disabled) {
    background: #b91c1c;
}
.btn-danger:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* MODAL */
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(10, 18, 30, 0.55);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 24px;
}
.confirm-modal {
    background: #ffffff;
    border-radius: 20px;
    width: 100%;
    max-width: 440px;
    padding: 24px;
    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.18);
    border: 1px solid #e4e8ef;
    font-family: 'DM Sans', system-ui, sans-serif;
}
.confirm-modal__head {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 16px;
}
.confirm-modal__icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.confirm-modal__icon--green {
    background: #ecfdf5;
    color: #059669;
}
.confirm-modal__icon--red {
    background: #fef2f2;
    color: #dc2626;
}
.confirm-modal__title {
    font-size: 14px;
    font-weight: 700;
    color: #1a2332;
    margin: 0;
}
.confirm-modal__sub {
    font-size: 12px;
    color: #64748b;
    margin: 3px 0 0;
}
.confirm-modal__notice {
    border-radius: 10px;
    padding: 14px 16px;
    font-size: 13px;
    margin-bottom: 18px;
}
.confirm-modal__notice--green {
    background: #ecfdf5;
    border: 1px solid #a7f3d0;
    color: #047857;
}
.confirm-modal__notice--red {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #b91c1c;
}
.confirm-modal__notice-title {
    font-weight: 700;
    margin: 0 0 4px;
}
.confirm-modal__notice ul {
    margin: 0;
    padding-left: 18px;
}
.confirm-modal__notice li {
    margin-top: 2px;
}
.confirm-modal__actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
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
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
.panel-enter-active,
.panel-leave-active {
    transition: all 0.22s ease;
}
.panel-enter-from,
.panel-leave-to {
    opacity: 0;
    transform: translateX(16px);
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
@media (max-width: 1100px) {
    .dr-body {
        flex-direction: column;
    }
    .dr-detail-col {
        width: 100%;
        max-height: none;
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
        min-width: 760px;
    }
    .table-card {
        overflow-x: auto;
    }
    .search-group {
        min-width: 0;
        width: 100%;
    }
}
</style>

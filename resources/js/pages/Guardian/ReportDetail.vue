<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';

const props = defineProps<{ id: string | number }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Guardian Reports', href: '/guardian-reports' },
    { title: 'Report Detail', href: '#' },
];

// ── State ─────────────────────────────────────────────────────
const report = ref<any | null>(null);
const loading = ref(false);
const saving = ref(false);
const reviewNotes = ref('');
const showEscalateModal = ref(false);
const escalateNotes = ref('');
const escalating = ref(false);
const successMessage = ref('');
const errorMessage = ref('');

const token = computed(() => localStorage.getItem('token') ?? '');

// ── API ───────────────────────────────────────────────────────
async function loadReport() {
    loading.value = true;
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/guardian-reports/${props.id}`,
            { headers: { Authorization: `Bearer ${token.value}` } },
        );
        report.value = data;
        reviewNotes.value = data.review_notes ?? '';
    } catch {
        errorMessage.value = 'Failed to load report.';
    } finally {
        loading.value = false;
    }
}

async function markReviewed() {
    if (!report.value) return;
    saving.value = true;
    errorMessage.value = '';
    try {
        const { data } = await axios.put(
            `${import.meta.env.VITE_APP_URL}/api/guardian-reports/${report.value.id}/review`,
            { review_notes: reviewNotes.value },
            { headers: { Authorization: `Bearer ${token.value}` } },
        );
        report.value = data;
        successMessage.value = 'Report marked as reviewed.';
        setTimeout(() => (successMessage.value = ''), 3000);
    } catch {
        errorMessage.value = 'Failed to save review.';
    } finally {
        saving.value = false;
    }
}

async function submitEscalate() {
    if (!report.value) return;
    escalating.value = true;
    errorMessage.value = '';
    try {
        const { data } = await axios.put(
            `${import.meta.env.VITE_APP_URL}/api/guardian-reports/${report.value.id}/escalate`,
            { review_notes: escalateNotes.value },
            { headers: { Authorization: `Bearer ${token.value}` } },
        );
        report.value = data;
        showEscalateModal.value = false;
        escalateNotes.value = '';
        successMessage.value = 'Report escalated to incident.';
        setTimeout(() => (successMessage.value = ''), 3000);
    } catch {
        errorMessage.value = 'Failed to escalate report.';
    } finally {
        escalating.value = false;
    }
}

// ── Helpers ───────────────────────────────────────────────────
function formatDate(ts: string) {
    if (!ts) return '—';
    return new Date(ts).toLocaleString('en-ZA', {
        dateStyle: 'long',
        timeStyle: 'short',
    });
}

function timeAgo(ts: string) {
    const diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
    if (diff < 60) return 'Just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
}

const severityClass = (s: string) =>
    ({
        high: 'bg-red-50 text-red-700 border border-red-200',
        medium: 'bg-yellow-50 text-yellow-700 border border-yellow-200',
        low: 'bg-green-50 text-green-700 border border-green-200',
    })[s] ?? 'bg-gray-100 text-gray-600';

const statusClass = (s: string) =>
    ({
        pending: 'bg-orange-50 text-orange-700 border border-orange-200',
        reviewed: 'bg-blue-50 text-blue-700 border border-blue-200',
        escalated: 'bg-red-50 text-red-700 border border-red-200',
        flagged: 'bg-purple-50 text-purple-700 border border-purple-200',
    })[s] ?? 'bg-gray-100 text-gray-600';

const alertTypeClass = (t: string) =>
    t === 'dv'
        ? 'bg-pink-50 text-pink-700 border border-pink-200'
        : 'bg-indigo-50 text-indigo-700 border border-indigo-200';

const canReview = computed(
    () => report.value && report.value.review_status === 'pending',
);

const canEscalate = computed(
    () =>
        report.value &&
        ['pending', 'reviewed'].includes(report.value.review_status),
);

onMounted(loadReport);
</script>

<template>
    <Head title="Report Detail" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-6">
            <!-- ── Back button ─────────────────────────────────── -->
            <button
                @click="router.visit('/guardian-reports')"
                class="flex items-center gap-2 text-sm font-medium text-gray-500 transition-colors hover:text-blue-600"
            >
                <svg
                    class="h-4 w-4"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M10 19l-7-7m0 0l7-7m-7 7h18"
                    />
                </svg>
                Back to Reports
            </button>

            <!-- ── Loading ────────────────────────────────────── -->
            <div v-if="loading" class="flex items-center justify-center py-32">
                <div
                    class="h-8 w-8 animate-spin rounded-full border-2 border-blue-600 border-t-transparent"
                ></div>
                <span class="ml-3 text-sm text-gray-500">Loading report…</span>
            </div>

            <!-- ── Error ──────────────────────────────────────── -->
            <div
                v-else-if="errorMessage && !report"
                class="rounded-xl border border-red-200 bg-red-50 p-6 text-sm text-red-700"
            >
                {{ errorMessage }}
            </div>

            <template v-else-if="report">
                <!-- ── Toast ──────────────────────────────────── -->
                <Transition name="toast">
                    <div
                        v-if="successMessage"
                        class="fixed top-6 right-6 z-50 flex items-center gap-2 rounded-xl border border-green-200 bg-green-50 px-4 py-3 shadow-lg"
                    >
                        <svg
                            class="h-4 w-4 text-green-600"
                            fill="currentColor"
                            viewBox="0 0 20 20"
                        >
                            <path
                                fill-rule="evenodd"
                                d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                clip-rule="evenodd"
                            />
                        </svg>
                        <span class="text-sm font-medium text-green-700">{{
                            successMessage
                        }}</span>
                    </div>
                </Transition>

                <!-- ── Header card ────────────────────────────── -->
                <div
                    class="rounded-xl border border-gray-100 bg-white p-6 shadow"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-4"
                    >
                        <div class="flex items-center gap-4">
                            <div
                                class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-xl bg-indigo-100 text-lg font-bold text-indigo-600"
                            >
                                {{
                                    (report.reporting_user?.name || 'U')
                                        .charAt(0)
                                        .toUpperCase()
                                }}
                            </div>
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h1 class="text-lg font-bold text-gray-900">
                                        {{
                                            report.reporting_user?.name ??
                                            'Unknown Reporter'
                                        }}
                                    </h1>
                                    <span
                                        :class="[
                                            'rounded-full px-2.5 py-1 text-xs font-semibold capitalize',
                                            statusClass(report.review_status),
                                        ]"
                                    >
                                        {{ report.review_status }}
                                    </span>
                                    <span
                                        :class="[
                                            'rounded-full px-2.5 py-1 text-xs font-semibold uppercase',
                                            alertTypeClass(report.alert_type),
                                        ]"
                                    >
                                        {{ report.alert_type }}
                                    </span>
                                    <span
                                        :class="[
                                            'rounded-full px-2.5 py-1 text-xs font-semibold capitalize',
                                            severityClass(report.severity),
                                        ]"
                                    >
                                        {{ report.severity }} severity
                                    </span>
                                </div>
                                <p class="mt-1 text-sm text-gray-500">
                                    Alert
                                    <span
                                        class="font-mono font-semibold text-gray-700"
                                        >#{{ report.alert_id }}</span
                                    >
                                    · Submitted
                                    {{ timeAgo(report.submitted_at) }}
                                </p>
                            </div>
                        </div>

                        <!-- Action buttons -->
                        <div class="flex items-center gap-2">
                            <button
                                v-if="canEscalate"
                                @click="showEscalateModal = true"
                                class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-red-700"
                            >
                                Escalate to Incident
                            </button>
                            <button
                                v-if="canReview"
                                @click="markReviewed"
                                :disabled="saving"
                                class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700 disabled:opacity-50"
                            >
                                <span v-if="saving">Saving…</span>
                                <span v-else>Mark Reviewed</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── Two column layout ──────────────────────── -->
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Left col: main content -->
                    <div class="space-y-6 lg:col-span-2">
                        <!-- Description -->
                        <div
                            class="rounded-xl border border-gray-100 bg-white p-6 shadow"
                        >
                            <h2 class="mb-4 text-sm font-bold text-gray-700">
                                Witness Statement
                            </h2>
                            <p class="leading-relaxed text-gray-700">
                                {{ report.description }}
                            </p>
                        </div>

                        <!-- Flags -->
                        <div
                            class="rounded-xl border border-gray-100 bg-white p-6 shadow"
                        >
                            <h2 class="mb-4 text-sm font-bold text-gray-700">
                                Observations
                            </h2>
                            <div class="grid grid-cols-2 gap-4">
                                <div
                                    :class="[
                                        'flex items-center gap-3 rounded-xl border p-4',
                                        report.seen_perpetrator
                                            ? 'border-red-200 bg-red-50'
                                            : 'border-gray-100 bg-gray-50',
                                    ]"
                                >
                                    <div
                                        :class="[
                                            'flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full',
                                            report.seen_perpetrator
                                                ? 'bg-red-100'
                                                : 'bg-gray-100',
                                        ]"
                                    >
                                        <svg
                                            class="h-5 w-5"
                                            :class="
                                                report.seen_perpetrator
                                                    ? 'text-red-600'
                                                    : 'text-gray-400'
                                            "
                                            fill="currentColor"
                                            viewBox="0 0 20 20"
                                        >
                                            <path
                                                d="M10 12a2 2 0 100-4 2 2 0 000 4z"
                                            />
                                            <path
                                                fill-rule="evenodd"
                                                d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z"
                                                clip-rule="evenodd"
                                            />
                                        </svg>
                                    </div>
                                    <div>
                                        <p
                                            class="text-sm font-semibold"
                                            :class="
                                                report.seen_perpetrator
                                                    ? 'text-red-700'
                                                    : 'text-gray-500'
                                            "
                                        >
                                            Saw perpetrator
                                        </p>
                                        <p
                                            class="text-xs"
                                            :class="
                                                report.seen_perpetrator
                                                    ? 'text-red-500'
                                                    : 'text-gray-400'
                                            "
                                        >
                                            {{
                                                report.seen_perpetrator
                                                    ? 'Yes — reported'
                                                    : 'Not reported'
                                            }}
                                        </p>
                                    </div>
                                </div>

                                <div
                                    :class="[
                                        'flex items-center gap-3 rounded-xl border p-4',
                                        report.heard_disturbance
                                            ? 'border-yellow-200 bg-yellow-50'
                                            : 'border-gray-100 bg-gray-50',
                                    ]"
                                >
                                    <div
                                        :class="[
                                            'flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full',
                                            report.heard_disturbance
                                                ? 'bg-yellow-100'
                                                : 'bg-gray-100',
                                        ]"
                                    >
                                        <svg
                                            class="h-5 w-5"
                                            :class="
                                                report.heard_disturbance
                                                    ? 'text-yellow-600'
                                                    : 'text-gray-400'
                                            "
                                            fill="currentColor"
                                            viewBox="0 0 20 20"
                                        >
                                            <path
                                                fill-rule="evenodd"
                                                d="M9.383 3.076A1 1 0 0110 4v12a1 1 0 01-1.707.707L4.586 13H2a1 1 0 01-1-1V8a1 1 0 011-1h2.586l3.707-3.707a1 1 0 011.09-.217zM14.657 2.929a1 1 0 011.414 0A9.972 9.972 0 0119 10a9.972 9.972 0 01-2.929 7.071 1 1 0 01-1.414-1.414A7.971 7.971 0 0017 10c0-2.21-.894-4.208-2.343-5.657a1 1 0 010-1.414zm-2.829 2.828a1 1 0 011.415 0A5.983 5.983 0 0115 10a5.984 5.984 0 01-1.757 4.243 1 1 0 01-1.415-1.415A3.984 3.984 0 0013 10a3.983 3.983 0 00-1.172-2.828 1 1 0 010-1.415z"
                                                clip-rule="evenodd"
                                            />
                                        </svg>
                                    </div>
                                    <div>
                                        <p
                                            class="text-sm font-semibold"
                                            :class="
                                                report.heard_disturbance
                                                    ? 'text-yellow-700'
                                                    : 'text-gray-500'
                                            "
                                        >
                                            Heard disturbance
                                        </p>
                                        <p
                                            class="text-xs"
                                            :class="
                                                report.heard_disturbance
                                                    ? 'text-yellow-500'
                                                    : 'text-gray-400'
                                            "
                                        >
                                            {{
                                                report.heard_disturbance
                                                    ? 'Yes — reported'
                                                    : 'Not reported'
                                            }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Review notes -->
                        <div
                            class="rounded-xl border border-gray-100 bg-white p-6 shadow"
                        >
                            <h2 class="mb-4 text-sm font-bold text-gray-700">
                                Review Notes
                            </h2>
                            <textarea
                                v-model="reviewNotes"
                                :disabled="!canReview"
                                rows="4"
                                :class="[
                                    'w-full resize-none rounded-lg border p-3 text-sm leading-relaxed transition-colors',
                                    canReview
                                        ? 'border-gray-200 focus:border-blue-400 focus:outline-none'
                                        : 'cursor-not-allowed border-gray-100 bg-gray-50 text-gray-500',
                                ]"
                                placeholder="Add review notes here…"
                            ></textarea>
                            <div
                                v-if="canReview"
                                class="mt-3 flex items-center justify-between"
                            >
                                <p
                                    v-if="errorMessage"
                                    class="text-xs text-red-600"
                                >
                                    {{ errorMessage }}
                                </p>
                                <span v-else></span>
                                <button
                                    @click="markReviewed"
                                    :disabled="saving"
                                    class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700 disabled:opacity-50"
                                >
                                    <span v-if="saving">Saving…</span>
                                    <span v-else>Save & Mark Reviewed</span>
                                </button>
                            </div>
                            <div
                                v-else-if="report.reviewed_at"
                                class="mt-3 flex items-center gap-2 text-xs text-gray-400"
                            >
                                <svg
                                    class="h-3.5 w-3.5"
                                    fill="currentColor"
                                    viewBox="0 0 20 20"
                                >
                                    <path
                                        fill-rule="evenodd"
                                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                        clip-rule="evenodd"
                                    />
                                </svg>
                                Reviewed by
                                <span class="font-medium text-gray-600">{{
                                    report.reviewed_by?.name ?? 'Admin'
                                }}</span>
                                on {{ formatDate(report.reviewed_at) }}
                            </div>
                        </div>

                        <!-- Linked incident report -->
                        <div
                            v-if="report.incident_report"
                            class="rounded-xl border border-indigo-100 bg-indigo-50 p-6 shadow"
                        >
                            <div class="mb-3 flex items-center gap-2">
                                <svg
                                    class="h-4 w-4 text-indigo-600"
                                    fill="currentColor"
                                    viewBox="0 0 20 20"
                                >
                                    <path
                                        fill-rule="evenodd"
                                        d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z"
                                        clip-rule="evenodd"
                                    />
                                </svg>
                                <h2 class="text-sm font-bold text-indigo-700">
                                    Linked Incident Report
                                </h2>
                            </div>
                            <p class="text-sm text-indigo-700">
                                This report has been escalated and linked to
                                incident
                                <span class="font-mono font-semibold"
                                    >#{{ report.incident_report.id }}</span
                                >.
                            </p>
                        </div>
                    </div>

                    <!-- Right col: metadata -->
                    <div class="space-y-6">
                        <!-- Reporter info -->
                        <div
                            class="rounded-xl border border-gray-100 bg-white p-6 shadow"
                        >
                            <h2 class="mb-4 text-sm font-bold text-gray-700">
                                Reporter
                            </h2>
                            <div class="space-y-3">
                                <div class="flex items-center gap-3">
                                    <div
                                        class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-indigo-100 text-sm font-bold text-indigo-600"
                                    >
                                        {{
                                            (report.reporting_user?.name || 'U')
                                                .charAt(0)
                                                .toUpperCase()
                                        }}
                                    </div>
                                    <div>
                                        <p class="font-semibold text-gray-800">
                                            {{
                                                report.reporting_user?.name ??
                                                '—'
                                            }}
                                        </p>
                                        <p class="text-xs text-gray-400">
                                            {{
                                                report.reporting_user?.role ??
                                                ''
                                            }}
                                        </p>
                                    </div>
                                </div>
                                <div
                                    v-if="report.reporting_user?.phone"
                                    class="flex items-center gap-2 text-sm text-gray-600"
                                >
                                    <svg
                                        class="h-4 w-4 text-gray-400"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"
                                        />
                                    </svg>
                                    {{ report.reporting_user.phone }}
                                </div>
                                <div
                                    v-if="report.reporting_user?.address_line_1"
                                    class="flex items-start gap-2 text-sm text-gray-600"
                                >
                                    <svg
                                        class="mt-0.5 h-4 w-4 flex-shrink-0 text-gray-400"
                                        fill="currentColor"
                                        viewBox="0 0 20 20"
                                    >
                                        <path
                                            fill-rule="evenodd"
                                            d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                    <span>{{
                                        report.reporting_user.address_line_1
                                    }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Alert details -->
                        <div
                            class="rounded-xl border border-gray-100 bg-white p-6 shadow"
                        >
                            <h2 class="mb-4 text-sm font-bold text-gray-700">
                                Alert Details
                            </h2>
                            <dl class="space-y-3">
                                <div>
                                    <dt
                                        class="text-xs font-semibold tracking-wide text-gray-400 uppercase"
                                    >
                                        Alert ID
                                    </dt>
                                    <dd
                                        class="mt-0.5 font-mono text-sm font-semibold text-gray-800"
                                    >
                                        #{{ report.alert_id }}
                                    </dd>
                                </div>
                                <div>
                                    <dt
                                        class="text-xs font-semibold tracking-wide text-gray-400 uppercase"
                                    >
                                        Alert Type
                                    </dt>
                                    <dd class="mt-1">
                                        <span
                                            :class="[
                                                'rounded-full px-2.5 py-1 text-xs font-semibold uppercase',
                                                alertTypeClass(
                                                    report.alert_type,
                                                ),
                                            ]"
                                        >
                                            {{ report.alert_type }}
                                        </span>
                                    </dd>
                                </div>
                                <div>
                                    <dt
                                        class="text-xs font-semibold tracking-wide text-gray-400 uppercase"
                                    >
                                        Severity
                                    </dt>
                                    <dd class="mt-1">
                                        <span
                                            :class="[
                                                'rounded-full px-2.5 py-1 text-xs font-semibold capitalize',
                                                severityClass(report.severity),
                                            ]"
                                        >
                                            {{ report.severity }}
                                        </span>
                                    </dd>
                                </div>
                                <div>
                                    <dt
                                        class="text-xs font-semibold tracking-wide text-gray-400 uppercase"
                                    >
                                        Submitted
                                    </dt>
                                    <dd class="mt-0.5 text-sm text-gray-700">
                                        {{ formatDate(report.submitted_at) }}
                                    </dd>
                                </div>
                                <div v-if="report.reviewed_at">
                                    <dt
                                        class="text-xs font-semibold tracking-wide text-gray-400 uppercase"
                                    >
                                        Reviewed
                                    </dt>
                                    <dd class="mt-0.5 text-sm text-gray-700">
                                        {{ formatDate(report.reviewed_at) }}
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Status timeline -->
                        <div
                            class="rounded-xl border border-gray-100 bg-white p-6 shadow"
                        >
                            <h2 class="mb-4 text-sm font-bold text-gray-700">
                                Timeline
                            </h2>
                            <ol
                                class="relative space-y-4 border-l border-gray-200 pl-5"
                            >
                                <li>
                                    <div
                                        class="absolute -left-1.5 mt-1 h-3 w-3 rounded-full border-2 border-white bg-orange-400"
                                    ></div>
                                    <p
                                        class="text-xs font-semibold text-gray-700"
                                    >
                                        Report submitted
                                    </p>
                                    <p class="text-xs text-gray-400">
                                        {{ formatDate(report.submitted_at) }}
                                    </p>
                                </li>
                                <li v-if="report.reviewed_at">
                                    <div
                                        class="absolute -left-1.5 mt-1 h-3 w-3 rounded-full border-2 border-white bg-blue-500"
                                    ></div>
                                    <p
                                        class="text-xs font-semibold text-gray-700"
                                    >
                                        Marked reviewed
                                    </p>
                                    <p class="text-xs text-gray-400">
                                        {{ formatDate(report.reviewed_at) }}
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        by
                                        {{
                                            report.reviewed_by?.name ?? 'Admin'
                                        }}
                                    </p>
                                </li>
                                <li v-if="report.review_status === 'escalated'">
                                    <div
                                        class="absolute -left-1.5 mt-1 h-3 w-3 rounded-full border-2 border-white bg-red-500"
                                    ></div>
                                    <p
                                        class="text-xs font-semibold text-red-700"
                                    >
                                        Escalated to incident
                                    </p>
                                </li>
                                <li
                                    v-if="report.review_status === 'pending'"
                                    class="opacity-40"
                                >
                                    <div
                                        class="absolute -left-1.5 mt-1 h-3 w-3 rounded-full border-2 border-white bg-gray-300"
                                    ></div>
                                    <p class="text-xs text-gray-400">
                                        Awaiting review…
                                    </p>
                                </li>
                            </ol>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- ── Escalate Modal ──────────────────────────────────── -->
        <Teleport to="body">
            <Transition name="modal">
                <div
                    v-if="showEscalateModal"
                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm"
                    @click.self="showEscalateModal = false"
                >
                    <div
                        class="w-full max-w-md rounded-2xl bg-white shadow-2xl"
                    >
                        <!-- Header -->
                        <div
                            class="flex items-center justify-between border-b border-gray-100 px-6 py-5"
                        >
                            <div>
                                <h2 class="text-base font-bold text-gray-900">
                                    Escalate to Incident
                                </h2>
                                <p class="mt-0.5 text-sm text-gray-500">
                                    This will link the report to a new or
                                    existing incident record.
                                </p>
                            </div>
                            <button
                                @click="showEscalateModal = false"
                                class="rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600"
                            >
                                <svg
                                    class="h-5 w-5"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>

                        <!-- Body -->
                        <div class="space-y-4 px-6 py-5">
                            <div
                                class="rounded-lg border border-red-100 bg-red-50 p-4 text-sm text-red-700"
                            >
                                <div
                                    class="flex items-center gap-2 font-semibold"
                                >
                                    <svg
                                        class="h-4 w-4"
                                        fill="currentColor"
                                        viewBox="0 0 20 20"
                                    >
                                        <path
                                            fill-rule="evenodd"
                                            d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                    Alert #{{ report?.alert_id }}
                                </div>
                                <p class="mt-1 text-red-600">
                                    {{ report?.reporting_user?.name }} ·
                                    {{ report?.severity }} severity
                                </p>
                            </div>

                            <div>
                                <label
                                    class="mb-1.5 block text-xs font-semibold tracking-wide text-gray-500 uppercase"
                                >
                                    Escalation Notes
                                </label>
                                <textarea
                                    v-model="escalateNotes"
                                    rows="4"
                                    class="w-full resize-none rounded-lg border border-gray-200 p-3 text-sm focus:border-red-400 focus:outline-none"
                                    placeholder="Describe why this is being escalated…"
                                ></textarea>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div
                            class="flex items-center justify-end gap-3 border-t border-gray-100 px-6 py-4"
                        >
                            <button
                                @click="showEscalateModal = false"
                                class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-50"
                            >
                                Cancel
                            </button>
                            <button
                                @click="submitEscalate"
                                :disabled="escalating"
                                class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-red-700 disabled:opacity-50"
                            >
                                <span v-if="escalating">Escalating…</span>
                                <span v-else>Confirm Escalation</span>
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AppLayout>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.2s;
}
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
.modal-enter-active .rounded-2xl,
.modal-leave-active .rounded-2xl {
    transition:
        transform 0.2s ease,
        opacity 0.2s;
}
.modal-enter-from .rounded-2xl,
.modal-leave-to .rounded-2xl {
    transform: scale(0.96) translateY(10px);
    opacity: 0;
}
.toast-enter-active,
.toast-leave-active {
    transition:
        opacity 0.3s,
        transform 0.3s;
}
.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateY(-8px);
}
</style>

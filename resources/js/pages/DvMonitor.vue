<script setup lang="ts">
// ══════════════════════════════════════════════════════════════
// DvMonitor.vue  —  Standalone page
//
// FILE LOCATION:
//   resources/js/pages/DvMonitor.vue
//
// INSTALL socket.io-client if not already:
//   npm install socket.io-client
//
// REGISTER THE ROUTE (add to your routes file):
//   { path: '/dv-monitor', component: () => import('@/pages/DvMonitor.vue') }
//
// ADD NAV LINK (in your sidebar/nav component):
//   { title: 'DV Monitor', href: '/dv-monitor', icon: '🛡️' }
// ══════════════════════════════════════════════════════════════

import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { type BreadcrumbItem } from '@/types';
import axios from 'axios';
import { io, type Socket } from 'socket.io-client';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const auth = useAuthStore();

const breadcrumbs: BreadcrumbItem[] = [
    // { title: 'Dashboard', href: '/dashboard' },
    // { title: 'DV Monitor', href: '/dv-monitor' },
];

// ── Channel selector ──────────────────────────────────────────
// Reuses your existing /api/channels-list endpoint
const channels = ref<{ id: number; name: string }[]>([]);
const selectedChannel = ref<number | null>(auth.user?.channel_id ?? null);

// ── Stream state ──────────────────────────────────────────────
const isStreaming = ref(false);
const hasEnded = ref(false);
const isMuted = ref(false);
const currentAlertId = ref<string | null>(null);
const streamUrl = ref<string | null>(null);
const socketError = ref<string | null>(null);
const socketConnected = ref(false);
const recordingMeta = ref<any>(null);
const elapsedSecs = ref(0);
const waveformBars = ref<number[]>(Array(32).fill(10));

// ── Past recordings ───────────────────────────────────────────
const pastRecordings = ref<any[]>([]);
const loadingHistory = ref(false);

// ── Internal refs ─────────────────────────────────────────────
const audioEl = ref<HTMLAudioElement | null>(null);
let socket: Socket | null = null;
let audioCtx: AudioContext | null = null;
let nextPlayAt = 0;
let elapsedTimer: ReturnType<typeof setInterval> | null = null;
let waveTimer: ReturnType<typeof setInterval> | null = null;

const token = computed(() => auth.token ?? localStorage.getItem('token') ?? '');

const playingId = ref<string | null>(null);

function streamUri(alertId: number) {
    const token = auth.token ?? localStorage.getItem('token') ?? '';
    return `${import.meta.env.VITE_APP_URL}/api/dv-recordings/${alertId}/stream?token=${encodeURIComponent(token)}`;
}

function togglePlay(alertId: number) {
    const id = String(alertId);
    playingId.value = playingId.value === id ? null : id;
}

const formattedElapsed = computed(() => {
    const m = Math.floor(elapsedSecs.value / 60);
    const s = elapsedSecs.value % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
});

// ── Load channels ─────────────────────────────────────────────
async function loadChannels() {
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/channels`,
            { headers: { Authorization: `Bearer ${token.value}` } },
        );

        console.log('Channels loaded:', data.channels.data);
        channels.value = Array.isArray(data)
            ? data?.channels?.data
            : (data ?? []);
        if (!selectedChannel.value && channels.value.length > 0) {
            selectedChannel.value = channels.value[0].id;
        }
    } catch (e) {
        console.error('Failed to load channels:', e);
    }
}

// ── Load past DV recordings ───────────────────────────────────
async function loadHistory() {
    if (!selectedChannel.value) return;
    loadingHistory.value = true;
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/dv-recording-list?channel_id=${selectedChannel.value}&limit=10`,
            { headers: { Authorization: `Bearer ${token.value}` } },
        );
        pastRecordings.value = data.data ?? data ?? [];
    } catch {
        pastRecordings.value = [];
    } finally {
        loadingHistory.value = false;
    }
}

// ── Socket ────────────────────────────────────────────────────
function connectSocket() {
    socket = io(
        import.meta.env.VITE_SOCKET_URL ??
            'https://radio.server.jaroworkspace.com',
        {
            transports: ['websocket'],
            reconnectionAttempts: 10,
            reconnectionDelay: 2000,
        },
    );

    socket.on('connect', () => {
        socketConnected.value = true;
        socketError.value = null;
        joinRoom();
    });

    socket.on('disconnect', (r) => {
        socketConnected.value = false;
        socketError.value = r;
    });
    socket.on('connect_error', (err) => {
        socketConnected.value = false;
        socketError.value = err.message;
    });

    socket.on('dv-stream-started', ({ alertId }: { alertId: string }) => {
        currentAlertId.value = alertId;
        isStreaming.value = true;
        hasEnded.value = false;
        streamUrl.value = null;
        recordingMeta.value = null;
        elapsedSecs.value = 0;
        nextPlayAt = 0;

        if (!audioCtx) {
            audioCtx = new AudioContext({ sampleRate: 16000 });
        } else if (audioCtx.state === 'suspended') {
            audioCtx.resume();
        }
        nextPlayAt = audioCtx.currentTime + 0.1;
        elapsedTimer = setInterval(() => {
            elapsedSecs.value++;
        }, 1000);
        waveTimer = setInterval(animateWaveform, 130);
    });

    socket.on('dv-audio-chunk', ({ chunk }: { chunk: string }) => {
        if (!audioCtx || isMuted.value) return;
        try {
            const raw = atob(chunk);
            const pcm = new Int16Array(raw.length / 2);
            for (let i = 0; i < pcm.length; i++)
                pcm[i] =
                    raw.charCodeAt(i * 2) | (raw.charCodeAt(i * 2 + 1) << 8);
            const f32 = new Float32Array(pcm.length);
            for (let i = 0; i < pcm.length; i++) f32[i] = pcm[i] / 32768;
            const buf = audioCtx.createBuffer(1, f32.length, 16000);
            buf.getChannelData(0).set(f32);
            const source = audioCtx.createBufferSource();
            source.buffer = buf;
            source.connect(audioCtx.destination);
            const now = audioCtx.currentTime;
            if (nextPlayAt < now) nextPlayAt = now + 0.05;
            source.start(nextPlayAt);
            nextPlayAt += buf.duration;
        } catch (e) {
            console.warn('[DvMonitor] decode error:', e);
        }
    });

    socket.on(
        'dv-stream-ended',
        async ({
            alertId,
            durationSecs,
        }: {
            alertId: string;
            durationSecs: number;
        }) => {
            isStreaming.value = false;
            hasEnded.value = true;
            if (elapsedTimer) {
                clearInterval(elapsedTimer);
                elapsedTimer = null;
            }
            if (waveTimer) {
                clearInterval(waveTimer);
                waveTimer = null;
            }
            waveformBars.value = Array(32).fill(10);
            streamUrl.value = `${import.meta.env.VITE_APP_URL}/api/dv-recordings/${alertId}/stream`;
            try {
                const { data } = await axios.get(
                    `${import.meta.env.VITE_APP_URL}/api/dv-recordings/${alertId}`,
                    { headers: { Authorization: `Bearer ${token.value}` } },
                );
                recordingMeta.value = data;
            } catch {
                recordingMeta.value = { duration_secs: durationSecs };
            }
            // Refresh history list
            loadHistory();
        },
    );
}

function joinRoom() {
    if (socket?.connected && selectedChannel.value) {
        socket.emit('join-cpf-room', {
            channelId: selectedChannel.value,
            token: token.value,
        });
    }
}

function animateWaveform() {
    waveformBars.value = waveformBars.value.map(() =>
        isStreaming.value ? Math.floor(Math.random() * 80) + 15 : 10,
    );
}

function toggleMute() {
    isMuted.value = !isMuted.value;
    audioCtx && (isMuted.value ? audioCtx.suspend() : audioCtx.resume());
}

function resetStream() {
    isStreaming.value = hasEnded.value = false;
    currentAlertId.value = streamUrl.value = recordingMeta.value = null;
    elapsedSecs.value = 0;
    waveformBars.value = Array(32).fill(10);
}

function formatDuration(secs: number | null) {
    if (!secs) return '—';
    const m = Math.floor(secs / 60);
    const s = Math.round(secs % 60);
    return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

function timeAgo(ts: string) {
    const diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
    if (diff < 60) return 'Just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
}

// Re-join room when channel selection changes
watch(selectedChannel, () => {
    joinRoom();
    loadHistory();
    resetStream();
});

onMounted(async () => {
    await loadChannels();
    connectSocket();
    loadHistory();
});

onBeforeUnmount(() => {
    if (elapsedTimer) clearInterval(elapsedTimer);
    if (waveTimer) clearInterval(waveTimer);
    socket?.disconnect();
    audioCtx?.close();
});
</script>

<template>
    <Head title="DV Monitor" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <!-- ── Page header + channel selector ─────────────── -->
        <div
            class="flex flex-col gap-4 p-6 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <h1 class="text-xl font-bold text-gray-900">
                    DV Alert Monitor
                </h1>
                <p class="mt-0.5 text-sm text-gray-500">
                    Live audio streaming and recordings for domestic violence
                    alerts
                </p>
            </div>

            <!-- Channel selector -->
            <div class="flex items-center gap-3">
                <label
                    class="text-xs font-semibold tracking-wide text-gray-500 uppercase"
                >
                    Channel
                </label>
                <select
                    v-model="selectedChannel"
                    class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition focus:border-blue-300 focus:ring-2 focus:ring-blue-100 focus:outline-none"
                >
                    <option :value="null" disabled>Select channel</option>
                    <option
                        v-for="ch in channels?.channels?.data"
                        :key="ch.id"
                        :value="ch.id"
                    >
                        {{ ch.name }}
                    </option>
                </select>

                <!-- Connection indicator -->
                <div
                    class="flex items-center gap-1.5 rounded-full border border-gray-100 bg-white px-3 py-1.5 shadow-sm"
                >
                    <span
                        :class="[
                            'h-2 w-2 rounded-full',
                            socketConnected ? 'bg-green-500' : 'bg-red-400',
                        ]"
                    ></span>
                    <span class="text-xs font-semibold text-gray-500">
                        {{ socketConnected ? 'Connected' : 'Offline' }}
                    </span>
                </div>
            </div>
        </div>

        <!-- ── Main content grid ───────────────────────────── -->
        <div class="grid grid-cols-1 gap-6 px-6 pb-6 lg:grid-cols-3">
            <!-- ── LEFT: Live monitor (spans 2 cols) ──────── -->
            <div class="flex flex-col gap-6 lg:col-span-2">
                <!-- Live stream card -->
                <div
                    :class="[
                        'rounded-xl border bg-white p-6 shadow transition-all duration-300',
                        isStreaming
                            ? 'border-purple-200 shadow-md shadow-purple-100'
                            : hasEnded
                              ? 'border-indigo-100'
                              : 'border-gray-100',
                    ]"
                >
                    <!-- Card header -->
                    <div class="mb-5 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div
                                :class="[
                                    'flex h-10 w-10 items-center justify-center rounded-full transition-colors',
                                    isStreaming
                                        ? 'bg-purple-100'
                                        : 'bg-gray-50',
                                ]"
                            >
                                <span
                                    :class="[
                                        'text-xl',
                                        isStreaming ? 'animate-pulse' : '',
                                    ]"
                                >
                                    🛡️
                                </span>
                            </div>
                            <div>
                                <h3 class="font-bold text-gray-800">
                                    Live Audio Stream
                                </h3>
                                <p class="text-xs text-gray-400">
                                    {{
                                        isStreaming
                                            ? `Alert #${currentAlertId}`
                                            : 'Waiting for incoming DV alert…'
                                    }}
                                </p>
                            </div>
                        </div>

                        <!-- Status badge -->
                        <span
                            :class="[
                                'rounded-full px-3 py-1 text-xs font-semibold',
                                isStreaming
                                    ? 'animate-pulse bg-purple-50 text-purple-700'
                                    : hasEnded
                                      ? 'bg-indigo-50 text-indigo-600'
                                      : 'bg-gray-100 text-gray-500',
                            ]"
                        >
                            <span
                                :class="[
                                    'mr-1.5 inline-block h-1.5 w-1.5 rounded-full align-middle',
                                    isStreaming
                                        ? 'bg-purple-500'
                                        : hasEnded
                                          ? 'bg-indigo-400'
                                          : 'bg-gray-400',
                                ]"
                            ></span>
                            {{
                                isStreaming
                                    ? 'LIVE'
                                    : hasEnded
                                      ? 'Recording Ended'
                                      : 'Monitoring'
                            }}
                        </span>
                    </div>

                    <!-- Socket error -->
                    <div
                        v-if="socketError"
                        class="mb-4 rounded-lg border border-red-100 bg-red-50 px-4 py-2.5 text-xs font-medium text-red-600"
                    >
                        ⚠ Socket disconnected — {{ socketError }}
                    </div>

                    <!-- Idle state -->
                    <div
                        v-if="!isStreaming && !hasEnded"
                        class="flex flex-col items-center justify-center rounded-xl bg-gray-50 py-16 text-center"
                    >
                        <span class="mb-3 text-5xl opacity-10">🛡️</span>
                        <p class="font-semibold text-gray-400">
                            No active DV alert
                        </p>
                        <p class="mt-1 text-xs text-gray-300">
                            Monitoring will begin automatically when a DV alert
                            is triggered
                        </p>
                    </div>

                    <!-- Active stream -->
                    <div v-if="isStreaming || hasEnded">
                        <!-- Alert info row -->
                        <div class="mb-3 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span
                                    :class="[
                                        'h-2.5 w-2.5 rounded-full',
                                        isStreaming
                                            ? 'animate-pulse bg-purple-500'
                                            : 'bg-indigo-300',
                                    ]"
                                ></span>
                                <span
                                    class="font-mono text-sm font-semibold text-gray-700"
                                >
                                    Alert #{{ currentAlertId }}
                                </span>
                            </div>
                            <span
                                class="font-mono text-sm font-semibold text-gray-500"
                            >
                                {{ formattedElapsed }}
                            </span>
                        </div>

                        <!-- Waveform visualiser -->
                        <div
                            :class="[
                                'mb-5 flex items-end justify-center gap-0.5 rounded-xl px-4 py-4',
                                isStreaming ? 'bg-purple-50' : 'bg-gray-50',
                            ]"
                            style="height: 96px"
                        >
                            <div
                                v-for="(h, i) in waveformBars"
                                :key="i"
                                :class="[
                                    'flex-1 rounded-sm transition-all duration-100',
                                    isStreaming
                                        ? 'bg-purple-400'
                                        : 'bg-gray-200',
                                ]"
                                :style="{ height: `${h}%` }"
                            ></div>
                        </div>

                        <!-- Audio player after stream ends -->

                        <audio
                            v-if="hasEnded && streamUrl"
                            ref="audioEl"
                            :src="streamUrl"
                            controls
                            preload="metadata"
                            class="mb-4 w-full rounded-lg"
                        ></audio>

                        <!-- Actions -->
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-if="isStreaming"
                                class="rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-600 shadow-sm transition hover:border-gray-300 hover:bg-gray-50 active:scale-95"
                                @click="toggleMute"
                            >
                                {{ isMuted ? '🔇  Muted' : '🔊  Live Audio' }}
                            </button>

                            <a
                                v-if="hasEnded && streamUrl"
                                :href="streamUrl"
                                :download="`dv_alert_${currentAlertId}.wav`"
                                class="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-700 shadow-sm transition hover:bg-indigo-100 active:scale-95"
                            >
                                ⬇ Download Recording
                            </a>

                            <button
                                v-if="hasEnded"
                                class="rounded-lg border border-gray-100 bg-white px-4 py-2 text-sm font-medium text-gray-400 transition hover:text-gray-600 active:scale-95"
                                @click="resetStream"
                            >
                                Clear
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Recording metadata card (shows after stream ends) -->
                <div
                    v-if="hasEnded && recordingMeta"
                    class="rounded-xl border border-gray-100 bg-white p-6 shadow"
                >
                    <h3 class="mb-4 text-sm font-bold text-gray-700">
                        Recording Details
                    </h3>
                    <ul class="divide-y divide-gray-50">
                        <li
                            v-for="row in [
                                {
                                    label: 'Alert ID',
                                    value: `#${currentAlertId}`,
                                },
                                {
                                    label: 'Duration',
                                    value: formatDuration(
                                        recordingMeta.duration_secs,
                                    ),
                                },
                                {
                                    label: 'Chunks received',
                                    value: recordingMeta.chunk_count ?? '—',
                                },
                                {
                                    label: 'Victim',
                                    value: recordingMeta.victim_name ?? '—',
                                },
                                {
                                    label: 'Phone',
                                    value: recordingMeta.victim_phone ?? '—',
                                },
                                {
                                    label: 'Channel',
                                    value: recordingMeta.channel_name ?? '—',
                                },
                                {
                                    label: 'PIN used',
                                    value: recordingMeta.cancel_pin_used ?? '—',
                                },
                            ]"
                            :key="row.label"
                            class="flex items-center justify-between py-3"
                        >
                            <span class="text-sm text-gray-400">{{
                                row.label
                            }}</span>
                            <span
                                :class="[
                                    'font-mono text-sm font-semibold',
                                    row.label === 'PIN used' &&
                                    row.value === 'duress'
                                        ? 'text-red-600'
                                        : row.label === 'PIN used' &&
                                            row.value === 'safe_cancel'
                                          ? 'text-green-600'
                                          : 'text-gray-700',
                                ]"
                            >
                                {{ row.value }}
                            </span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- ── RIGHT: Past recordings ──────────────────── -->
            <div class="rounded-xl border border-gray-100 bg-white p-6 shadow">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-gray-700">
                        Past Recordings
                    </h3>
                    <button
                        class="text-xs font-semibold text-blue-500 hover:text-blue-700"
                        @click="loadHistory"
                    >
                        Refresh
                    </button>
                </div>

                <!-- Loading -->
                <div v-if="loadingHistory" class="flex justify-center py-8">
                    <svg
                        class="h-5 w-5 animate-spin text-gray-300"
                        viewBox="0 0 24 24"
                        fill="none"
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
                            d="M4 12a8 8 0 018-8v8z"
                        />
                    </svg>
                </div>

                <!-- Empty -->
                <div
                    v-else-if="pastRecordings.length === 0"
                    class="py-10 text-center text-sm text-gray-300"
                >
                    No recordings yet
                </div>

                <!-- List -->
                <ul v-else class="space-y-2">
                    <li
                        v-for="rec in pastRecordings"
                        :key="rec.id"
                        class="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm transition-all duration-200 hover:shadow-md"
                    >
                        <!-- Top row -->
                        <div class="flex items-center gap-3 px-4 py-3">
                            <!-- Left accent dot -->
                            <div
                                :class="[
                                    'h-2 w-2 shrink-0 rounded-full',
                                    rec.cancel_pin_used === 'duress'
                                        ? 'bg-rose-400'
                                        : rec.cancel_pin_used &&
                                            rec.cancel_pin_used !== 'none'
                                          ? 'bg-emerald-400'
                                          : !rec.is_finalised
                                            ? 'animate-pulse bg-amber-400'
                                            : 'bg-gray-200',
                                ]"
                            ></div>

                            <!-- Info -->
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="font-mono text-xs font-bold tracking-widest text-gray-400 uppercase"
                                    >
                                        #{{ rec.alert_id }}
                                    </span>
                                    <span
                                        v-if="
                                            rec.cancel_pin_used &&
                                            rec.cancel_pin_used !== 'none'
                                        "
                                        :class="[
                                            'rounded-full px-2 py-px text-[10px] font-bold tracking-wider uppercase',
                                            rec.cancel_pin_used === 'duress'
                                                ? 'bg-rose-100 text-rose-600'
                                                : 'bg-emerald-100 text-emerald-700',
                                        ]"
                                    >
                                        {{
                                            rec.cancel_pin_used === 'duress'
                                                ? '⚠ Duress'
                                                : '✓ Safe'
                                        }}
                                    </span>
                                </div>
                                <div
                                    class="mt-0.5 flex items-center gap-1.5 text-xs text-gray-400"
                                >
                                    <span
                                        class="truncate font-medium text-gray-600"
                                        >{{
                                            rec.victim_name ?? 'Unknown'
                                        }}</span
                                    >
                                    <span>·</span>
                                    <span>{{
                                        formatDuration(rec.duration_secs)
                                    }}</span>
                                    <span>·</span>
                                    <span>{{ timeAgo(rec.started_at) }}</span>
                                </div>
                            </div>

                            <!-- Action buttons -->
                            <div
                                v-if="rec.is_finalised"
                                class="flex shrink-0 items-center gap-1.5"
                            >
                                <!-- Play / Pause -->
                                <button
                                    @click="togglePlay(rec.alert_id)"
                                    :class="[
                                        'flex h-8 w-8 items-center justify-center rounded-lg transition-all duration-150',
                                        playingId === String(rec.alert_id)
                                            ? 'bg-indigo-600 text-white shadow-md shadow-orange-200'
                                            : 'bg-gray-50 text-gray-400 ring-1 ring-gray-200/80 hover:bg-orange-600 hover:text-white hover:shadow-md hover:shadow-orange-200',
                                    ]"
                                    :title="
                                        playingId === String(rec.alert_id)
                                            ? 'Stop'
                                            : 'Play'
                                    "
                                >
                                    <!-- Play icon -->
                                    <svg
                                        v-if="
                                            playingId !== String(rec.alert_id)
                                        "
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="h-3.5 w-3.5"
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                    >
                                        <path
                                            fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                    <!-- Stop icon -->
                                    <svg
                                        v-else
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="h-3.5 w-3.5"
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                    >
                                        <path
                                            fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zM8 7a1 1 0 00-1 1v4a1 1 0 001 1h4a1 1 0 001-1V8a1 1 0 00-1-1H8z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                </button>

                                <!-- Download -->
                                <a
                                    :href="streamUri(rec.alert_id)"
                                    :download="`dv_alert_${rec.alert_id}.wav`"
                                    class="flex h-8 w-8 items-center justify-center rounded-lg bg-gray-50 text-gray-400 ring-1 ring-gray-200/80 transition-all duration-150 hover:bg-indigo-600 hover:text-white hover:shadow-md hover:shadow-indigo-200"
                                    title="Download"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="h-3.5 w-3.5"
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                    >
                                        <path
                                            fill-rule="evenodd"
                                            d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                </a>
                            </div>

                            <!-- Live indicator -->
                            <span
                                v-else
                                class="ml-auto flex shrink-0 items-center gap-1.5 text-[11px] font-medium text-amber-500"
                            >
                                <span class="relative flex h-1.5 w-1.5">
                                    <span
                                        class="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75"
                                    ></span>
                                    <span
                                        class="relative inline-flex h-1.5 w-1.5 rounded-full bg-amber-400"
                                    ></span>
                                </span>
                                Live
                            </span>
                        </div>

                        <!-- Inline audio player — expands when play is clicked -->
                        <div
                            v-if="playingId === String(rec.alert_id)"
                            class="border-t border-gray-100 bg-gray-50 px-4 py-3"
                        >
                            <audio
                                :src="streamUri(rec.alert_id)"
                                controls
                                autoplay
                                class="w-full rounded-lg"
                                @ended="playingId = null"
                            ></audio>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>

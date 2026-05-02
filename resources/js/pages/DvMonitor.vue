<script lang="ts">
declare global {
    interface HTMLElement {
        _clickOutsideHandler?: (event: MouseEvent) => void;
    }
}
export default {};
</script>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { type BreadcrumbItem } from '@/types';
import axios from 'axios';
import { io, type Socket } from 'socket.io-client';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const auth = useAuthStore();
const breadcrumbs: BreadcrumbItem[] = [];

// ── Channel selector ──────────────────────────────────────────
const channels = ref<{ id: number; name: string }[]>([]);
const channelSearch = ref('');
const showChannelDropdown = ref(false);
const selectedChannelId = ref<number | null>(auth.user?.channel_id ?? null);
const selectedChannelName = computed(
    () =>
        channels.value.find((c) => c.id === selectedChannelId.value)?.name ??
        'Select channel',
);
const filteredChannels = computed(() =>
    channels.value.filter((c) =>
        c.name.toLowerCase().includes(channelSearch.value.toLowerCase()),
    ),
);

function selectChannel(ch: { id: number; name: string }) {
    selectedChannelId.value = ch.id;
    channelSearch.value = '';
    showChannelDropdown.value = false;
}

// ── Multi-screen streams ──────────────────────────────────────
interface StreamScreen {
    alertId: string;
    isStreaming: boolean;
    hasEnded: boolean;
    isMuted: boolean;
    elapsedSecs: number;
    waveformBars: number[];
    streamUrl: string | null;
    recordingMeta: any;
    audioCtx: AudioContext | null;
    nextPlayAt: number;
    currentFormat: string;
    activeSources: AudioBufferSourceNode[];
    decodeQueue: Promise<void>;
    elapsedTimer: ReturnType<typeof setInterval> | null;
    waveTimer: ReturnType<typeof setInterval> | null;
    pinnedAt: number;
}

const screens = ref<StreamScreen[]>([]);

function getOrCreateScreen(alertId: string): StreamScreen {
    const existing = screens.value.find((s) => s.alertId === alertId);
    if (existing) return existing;
    const screen: StreamScreen = {
        alertId,
        isStreaming: true,
        hasEnded: false,
        isMuted: false,
        elapsedSecs: 0,
        waveformBars: Array(28).fill(8),
        streamUrl: null,
        recordingMeta: null,
        audioCtx: null,
        nextPlayAt: 0,
        currentFormat: 'adts-aac',
        activeSources: [],
        decodeQueue: Promise.resolve(),
        elapsedTimer: null,
        waveTimer: null,
        pinnedAt: Date.now(),
    };
    screens.value.unshift(screen);
    return screen;
}

function removeScreen(alertId: string) {
    const screen = screens.value.find((s) => s.alertId === alertId);
    if (screen) {
        if (screen.elapsedTimer) clearInterval(screen.elapsedTimer);
        if (screen.waveTimer) clearInterval(screen.waveTimer);
        screen.activeSources.forEach((s) => {
            try {
                s.stop();
            } catch (_) {}
        });
        screen.audioCtx?.close();
    }
    screens.value = screens.value.filter((s) => s.alertId !== alertId);
}

function toggleMute(screen: StreamScreen) {
    screen.isMuted = !screen.isMuted;
    if (screen.audioCtx) {
        screen.isMuted ? screen.audioCtx.suspend() : screen.audioCtx.resume();
    }
}

function formatElapsed(secs: number) {
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
}

function callerInitials(name: string): string {
    if (!name) return '?';
    return name
        .split(' ')
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('');
}

// ── Socket ────────────────────────────────────────────────────
const socketConnected = ref(false);
const socketError = ref<string | null>(null);
let socket: Socket | null = null;

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
        const screen = getOrCreateScreen(alertId);
        screen.isStreaming = true;
        screen.hasEnded = false;
        screen.streamUrl = null;
        screen.recordingMeta = null;
        screen.elapsedSecs = 0;
        screen.nextPlayAt = 0;
        if (!screen.audioCtx) {
            screen.audioCtx = new AudioContext({ sampleRate: 16000 });
        } else if (screen.audioCtx.state === 'suspended') {
            screen.audioCtx.resume();
        }
        screen.nextPlayAt = screen.audioCtx.currentTime + 0.1;
        screen.elapsedTimer = setInterval(() => {
            screen.elapsedSecs++;
        }, 1000);
        screen.waveTimer = setInterval(() => animateWaveform(screen), 120);
    });

    socket.on(
        'dv-audio-format',
        ({ alertId, format }: { alertId: string; format: string }) => {
            const screen = screens.value.find((s) => s.alertId === alertId);
            if (screen) screen.currentFormat = format;
        },
    );

    socket.on(
        'dv-audio-chunk',
        ({ alertId, chunk }: { alertId: string; chunk: string }) => {
            const screen = screens.value.find((s) => s.alertId === alertId);
            if (!screen || !screen.audioCtx || screen.isMuted) return;
            if (screen.currentFormat === 'm4a') return;
            screen.decodeQueue = screen.decodeQueue.then(async () => {
                try {
                    const raw = atob(chunk);
                    const bytes = new Uint8Array(raw.length);
                    for (let i = 0; i < raw.length; i++)
                        bytes[i] = raw.charCodeAt(i);
                    const decoded = await screen.audioCtx!.decodeAudioData(
                        bytes.buffer,
                    );
                    const now = screen.audioCtx!.currentTime;
                    if (screen.nextPlayAt < now) screen.nextPlayAt = now + 0.1;
                    const source = screen.audioCtx!.createBufferSource();
                    source.buffer = decoded;
                    source.connect(screen.audioCtx!.destination);
                    source.start(screen.nextPlayAt);
                    screen.nextPlayAt += decoded.duration;
                    screen.activeSources.push(source);
                    source.onended = () => {
                        const idx = screen.activeSources.indexOf(source);
                        if (idx !== -1) screen.activeSources.splice(idx, 1);
                    };
                } catch (e) {
                    console.warn('[DvMonitor] decode error:', e);
                }
            });
        },
    );

    socket.on(
        'dv-stream-ended',
        async ({
            alertId,
            durationSecs,
        }: {
            alertId: string;
            durationSecs: number;
        }) => {
            const screen = screens.value.find((s) => s.alertId === alertId);
            if (!screen) return;
            screen.isStreaming = false;
            screen.hasEnded = true;
            screen.currentFormat = 'adts-aac';
            screen.nextPlayAt = 0;
            screen.activeSources.forEach((s) => {
                try {
                    s.stop();
                } catch (_) {}
            });
            screen.activeSources.length = 0;
            if (screen.elapsedTimer) {
                clearInterval(screen.elapsedTimer);
                screen.elapsedTimer = null;
            }
            if (screen.waveTimer) {
                clearInterval(screen.waveTimer);
                screen.waveTimer = null;
            }
            screen.waveformBars = Array(28).fill(8);
            screen.streamUrl = `${import.meta.env.VITE_APP_URL}/api/dv-recordings/${alertId}/stream`;
            try {
                const { data } = await axios.get(
                    `${import.meta.env.VITE_APP_URL}/api/dv-recordings/${alertId}`,
                    { headers: { Authorization: `Bearer ${token.value}` } },
                );
                screen.recordingMeta = data;
            } catch {
                screen.recordingMeta = { duration_secs: durationSecs };
            }
            loadHistory();
        },
    );
}

function joinRoom() {
    if (socket?.connected && selectedChannelId.value) {
        socket.emit('join-cpf-room', {
            channelId: selectedChannelId.value,
            token: token.value,
        });
    }
}

function animateWaveform(screen: StreamScreen) {
    screen.waveformBars = screen.waveformBars.map(() =>
        screen.isStreaming ? Math.floor(Math.random() * 78) + 12 : 8,
    );
}

// ── Auth / token ──────────────────────────────────────────────
const token = computed(() => auth.token ?? localStorage.getItem('token') ?? '');

// ── Recordings modal ──────────────────────────────────────────
const showRecordingsModal = ref(false);
const pastRecordings = ref<any[]>([]);
const loadingHistory = ref(false);
const modalSearch = ref('');
const selectedHousehold = ref<any | null>(null);
const householdRecordings = ref<any[]>([]);
const playingId = ref<string | null>(null);

// ── Household detail filters ──────────────────────────────────
const detailFilterFrom = ref('');
const detailFilterTo = ref('');
const detailFilterTimeFrom = ref('');
const detailFilterTimeTo = ref('');
const detailFilterPin = ref<'all' | 'duress' | 'safe' | 'none'>('all');
const detailFilterStatus = ref<'all' | 'finalised' | 'live'>('all');
const detailFilterMinDur = ref<number | ''>('');
const detailFilterMaxDur = ref<number | ''>('');
const detailSortBy = ref<'newest' | 'oldest' | 'longest' | 'shortest'>(
    'newest',
);
const detailSearch = ref('');
const showDetailFilters = ref(false);

function resetDetailFilters() {
    detailFilterFrom.value = '';
    detailFilterTo.value = '';
    detailFilterTimeFrom.value = '';
    detailFilterTimeTo.value = '';
    detailFilterPin.value = 'all';
    detailFilterStatus.value = 'all';
    detailFilterMinDur.value = '';
    detailFilterMaxDur.value = '';
    detailSortBy.value = 'newest';
    detailSearch.value = '';
}

const activeDetailFilterCount = computed(() => {
    let n = 0;
    if (detailFilterFrom.value || detailFilterTo.value) n++;
    if (detailFilterTimeFrom.value || detailFilterTimeTo.value) n++;
    if (detailFilterPin.value !== 'all') n++;
    if (detailFilterStatus.value !== 'all') n++;
    if (detailFilterMinDur.value !== '' || detailFilterMaxDur.value !== '') n++;
    if (detailSearch.value) n++;
    return n;
});

const filteredHouseholdRecordings = computed(() => {
    let recs = [...householdRecordings.value];
    if (detailSearch.value.trim()) {
        const q = detailSearch.value.toLowerCase();
        recs = recs.filter(
            (r) =>
                String(r.alert_id).includes(q) ||
                r.gps?.toLowerCase().includes(q) ||
                r.channel_name?.toLowerCase().includes(q),
        );
    }
    if (detailFilterFrom.value) {
        const from = new Date(detailFilterFrom.value + 'T00:00:00');
        recs = recs.filter((r) => parseTs(r.started_at) >= from);
    }
    if (detailFilterTo.value) {
        const to = new Date(detailFilterTo.value + 'T23:59:59');
        recs = recs.filter((r) => parseTs(r.started_at) <= to);
    }
    if (detailFilterTimeFrom.value) {
        const [fh, fm] = detailFilterTimeFrom.value.split(':').map(Number);
        recs = recs.filter((r) => {
            const d = parseTs(r.started_at);
            return d.getHours() * 60 + d.getMinutes() >= fh * 60 + fm;
        });
    }
    if (detailFilterTimeTo.value) {
        const [th, tm] = detailFilterTimeTo.value.split(':').map(Number);
        recs = recs.filter((r) => {
            const d = parseTs(r.started_at);
            return d.getHours() * 60 + d.getMinutes() <= th * 60 + tm;
        });
    }
    if (detailFilterPin.value !== 'all') {
        recs = recs.filter((r) => {
            const p = r.cancel_pin_used ?? 'none';
            return detailFilterPin.value === 'none'
                ? !p || p === 'none'
                : p === detailFilterPin.value;
        });
    }
    if (detailFilterStatus.value === 'finalised')
        recs = recs.filter((r) => r.is_finalised);
    if (detailFilterStatus.value === 'live')
        recs = recs.filter((r) => !r.is_finalised);
    if (detailFilterMinDur.value !== '')
        recs = recs.filter(
            (r) => (r.duration_secs ?? 0) >= Number(detailFilterMinDur.value),
        );
    if (detailFilterMaxDur.value !== '')
        recs = recs.filter(
            (r) => (r.duration_secs ?? 0) <= Number(detailFilterMaxDur.value),
        );
    recs.sort((a, b) => {
        if (detailSortBy.value === 'newest')
            return (
                parseTs(b.started_at).getTime() -
                parseTs(a.started_at).getTime()
            );
        if (detailSortBy.value === 'oldest')
            return (
                parseTs(a.started_at).getTime() -
                parseTs(b.started_at).getTime()
            );
        if (detailSortBy.value === 'longest')
            return (b.duration_secs ?? 0) - (a.duration_secs ?? 0);
        if (detailSortBy.value === 'shortest')
            return (a.duration_secs ?? 0) - (b.duration_secs ?? 0);
        return 0;
    });
    return recs;
});

const groupedRecordings = computed(() => {
    const filtered = pastRecordings.value.filter((r) => {
        const q = modalSearch.value.toLowerCase();
        return (
            !q ||
            r.victim_name?.toLowerCase().includes(q) ||
            String(r.alert_id).includes(q) ||
            r.household_name?.toLowerCase().includes(q) ||
            r.channel_name?.toLowerCase().includes(q) ||
            r.address?.toLowerCase().includes(q)
        );
    });
    const groups: Record<string, any[]> = {};
    for (const rec of filtered) {
        const key = rec.household_id ?? rec.victim_name ?? 'Unknown';
        if (!groups[key]) groups[key] = [];
        groups[key].push(rec);
    }
    return Object.entries(groups).map(([key, recs]) => ({
        householdId: key,
        householdName:
            recs[0].household_name ??
            recs[0].victim_name ??
            'Unknown Household',
        address: recs[0].address ?? null,
        gps: recs[0].gps ?? null,
        latestAt: recs[0].started_at,
        totalCount: recs.length,
        latestRec: recs[0],
        recs,
    }));
});

async function loadHistory() {
    if (!selectedChannelId.value) return;
    loadingHistory.value = true;
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/dv-recording-list?channel_id=${selectedChannelId.value}&limit=50`,
            { headers: { Authorization: `Bearer ${token.value}` } },
        );
        pastRecordings.value = data.data ?? data ?? [];
    } catch {
        pastRecordings.value = [];
    } finally {
        loadingHistory.value = false;
    }
}

function openHousehold(group: any) {
    selectedHousehold.value = group;
    householdRecordings.value = group.recs;
    playingId.value = null;
    resetDetailFilters();
    showDetailFilters.value = false;
}

function closeHousehold() {
    selectedHousehold.value = null;
    householdRecordings.value = [];
    playingId.value = null;
    resetDetailFilters();
    showDetailFilters.value = false;
}

function openModal() {
    showRecordingsModal.value = true;
    loadHistory();
}
function closeModal() {
    showRecordingsModal.value = false;
    closeHousehold();
    modalSearch.value = '';
}

function streamUri(alertId: number) {
    return `${import.meta.env.VITE_APP_URL}/api/dv-recordings/${alertId}/stream?token=${encodeURIComponent(token.value)}`;
}

function togglePlay(id: string) {
    playingId.value = playingId.value === id ? null : id;
}

// ── Helpers ───────────────────────────────────────────────────
function parseTs(ts: string): Date {
    if (!ts) return new Date(0);
    if (/[Z+]/.test(ts) || ts.includes('T')) return new Date(ts);
    return new Date(ts.replace(' ', 'T') + 'Z');
}

function formatDuration(secs: number | null) {
    if (!secs) return '—';
    const m = Math.floor(secs / 60);
    const s = Math.round(secs % 60);
    return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

function timeAgo(ts: string) {
    const diff = Math.floor((Date.now() - parseTs(ts).getTime()) / 1000);
    if (diff < 5) return 'Just now';
    if (diff < 60) return `${diff}s ago`;
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;
    return parseTs(ts).toLocaleDateString('en-ZA');
}

function formatTimestamp(ts: string) {
    return parseTs(ts).toLocaleString('en-ZA', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

async function loadChannels() {
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/channels`,
            { headers: { Authorization: `Bearer ${token.value}` } },
        );
        channels.value = Array.isArray(data)
            ? data
            : (data?.channels?.data ?? []);
        if (!selectedChannelId.value && channels.value.length > 0) {
            selectedChannelId.value = channels.value[0].id;
        }
    } catch (e) {
        console.error('Failed to load channels:', e);
    }
}

watch(selectedChannelId, () => {
    joinRoom();
    screens.value = [];
});

onMounted(async () => {
    await loadChannels();
    connectSocket();
});

onBeforeUnmount(() => {
    screens.value.forEach((s) => {
        if (s.elapsedTimer) clearInterval(s.elapsedTimer);
        if (s.waveTimer) clearInterval(s.waveTimer);
        s.activeSources.forEach((src) => {
            try {
                src.stop();
            } catch (_) {}
        });
        s.audioCtx?.close();
    });
    socket?.disconnect();
});

const vClickOutside = {
    mounted(el: HTMLElement, binding: { value: () => void }) {
        el._clickOutsideHandler = (event: MouseEvent) => {
            if (!el.contains(event.target as Node)) {
                binding.value();
            }
        };
        document.addEventListener('mousedown', el._clickOutsideHandler);
    },
    unmounted(el: HTMLElement) {
        document.removeEventListener('mousedown', el._clickOutsideHandler);
        delete el._clickOutsideHandler;
    },
};
</script>

<template>
    <Head title="DV Monitor" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="dvm-root">
            <!-- ══ TOP BAR ═══════════════════════════════════════════════ -->
            <header class="dvm-topbar">
                <div class="dvm-topbar-left">
                    <div class="dvm-shield">
                        <svg
                            width="16"
                            height="16"
                            viewBox="0 0 20 20"
                            fill="none"
                        >
                            <path
                                d="M10 1.5L2 5.5V10C2 14.1 5.4 17.9 10 19C14.6 17.9 18 14.1 18 10V5.5L10 1.5Z"
                                fill="white"
                                opacity=".9"
                            />
                            <path
                                d="M7 10L9 12L13 8"
                                stroke="#e63946"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                    </div>
                    <div class="dvm-brand">
                        <h1 class="dvm-title">DV Monitor</h1>
                        <p class="dvm-subtitle">
                            DOMESTIC VIOLENCE ALERT COMMAND
                        </p>
                    </div>
                    <div v-if="screens.length > 0" class="dvm-alert-counter">
                        <span class="dvm-alert-counter-dot"></span>
                        {{ screens.filter((s) => s.isStreaming).length }} ACTIVE
                    </div>
                </div>

                <div class="dvm-topbar-right">
                    <!-- Channel selector -->
                    <div
                        class="dvm-channel-wrap"
                        v-click-outside="() => (showChannelDropdown = false)"
                    >
                        <button
                            class="dvm-channel-btn"
                            @click="showChannelDropdown = !showChannelDropdown"
                        >
                            <span class="dvm-channel-dot"></span>
                            <span
                                >Channel:
                                <strong>{{ selectedChannelName }}</strong></span
                            >
                            <svg
                                width="10"
                                height="10"
                                viewBox="0 0 20 20"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.2"
                                :style="{
                                    transform: showChannelDropdown
                                        ? 'rotate(180deg)'
                                        : '',
                                    transition: 'transform 0.2s',
                                }"
                            >
                                <path d="M4 7l6 6 6-6" stroke-linecap="round" />
                            </svg>
                        </button>
                        <Transition name="dropdown">
                            <div
                                v-if="showChannelDropdown"
                                class="dvm-channel-dropdown"
                            >
                                <div class="dvm-channel-search-wrap">
                                    <svg
                                        width="13"
                                        height="13"
                                        viewBox="0 0 20 20"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                    >
                                        <circle cx="9" cy="9" r="6" />
                                        <path d="M15 15l-3.5-3.5" />
                                    </svg>
                                    <input
                                        v-model="channelSearch"
                                        class="dvm-channel-search"
                                        placeholder="Search channels…"
                                        autofocus
                                    />
                                    <button
                                        v-if="channelSearch"
                                        @click="channelSearch = ''"
                                        class="dvm-clear-btn"
                                    >
                                        ×
                                    </button>
                                </div>
                                <div class="dvm-channel-list">
                                    <div
                                        v-if="filteredChannels.length === 0"
                                        class="dvm-channel-empty"
                                    >
                                        No channels found
                                    </div>
                                    <button
                                        v-for="ch in filteredChannels"
                                        :key="ch.id"
                                        class="dvm-channel-item"
                                        :class="{
                                            active: ch.id === selectedChannelId,
                                        }"
                                        @click="selectChannel(ch)"
                                    >
                                        <span
                                            class="dvm-channel-item-dot"
                                            :class="{
                                                active:
                                                    ch.id === selectedChannelId,
                                            }"
                                        ></span>
                                        {{ ch.name }}
                                    </button>
                                </div>
                            </div>
                        </Transition>
                    </div>

                    <!-- Recordings -->
                    <button class="dvm-rec-btn" @click="openModal">
                        <svg
                            width="13"
                            height="13"
                            viewBox="0 0 20 20"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                        >
                            <circle cx="10" cy="10" r="3" />
                            <path
                                d="M10 1v3M10 16v3M1 10h3M16 10h3"
                                stroke-linecap="round"
                            />
                        </svg>
                        Recordings
                        <span
                            v-if="pastRecordings.length > 0"
                            class="dvm-rec-count"
                            >{{ pastRecordings.length }}</span
                        >
                    </button>

                    <!-- Status -->
                    <div
                        class="dvm-status-pill"
                        :class="socketConnected ? 'connected' : 'offline'"
                    >
                        <span class="dvm-status-dot"></span>
                        {{ socketConnected ? 'CONNECTED' : 'OFFLINE' }}
                    </div>
                </div>
            </header>

            <!-- ══ STATS BAR ═════════════════════════════════════════════ -->
            <div v-if="screens.length > 0" class="dvm-stats-bar">
                <div class="dvm-stat">
                    <span class="dvm-stat-val">{{ screens.length }}</span>
                    <span class="dvm-stat-lbl">Total Alerts</span>
                </div>
                <div class="dvm-stat-div"></div>
                <div class="dvm-stat">
                    <span class="dvm-stat-val live">{{
                        screens.filter((s) => s.isStreaming).length
                    }}</span>
                    <span class="dvm-stat-lbl">Live Streams</span>
                </div>
                <div class="dvm-stat-div"></div>
                <div class="dvm-stat">
                    <span class="dvm-stat-val ended">{{
                        screens.filter((s) => s.hasEnded).length
                    }}</span>
                    <span class="dvm-stat-lbl">Ended</span>
                </div>
                <div class="dvm-stat-div"></div>
                <div class="dvm-stat">
                    <span class="dvm-stat-val warn">{{
                        screens.filter(
                            (s) =>
                                s.recordingMeta?.cancel_pin_used === 'duress',
                        ).length
                    }}</span>
                    <span class="dvm-stat-lbl">Duress PINs</span>
                </div>
            </div>

            <!-- ══ CANVAS ════════════════════════════════════════════════ -->
            <main class="dvm-canvas">
                <!-- Empty state -->
                <div v-if="screens.length === 0" class="dvm-empty">
                    <div class="dvm-empty-ring">
                        <svg
                            width="34"
                            height="34"
                            viewBox="0 0 48 48"
                            fill="none"
                        >
                            <path
                                d="M24 4L6 13V24C6 33.9 14.1 43 24 45.5C33.9 43 42 33.9 42 24V13L24 4Z"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linejoin="round"
                            />
                            <path
                                d="M17 24L21 28L31 18"
                                stroke="currentColor"
                                stroke-width="2.5"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                    </div>
                    <div>
                        <p class="dvm-empty-title">
                            All clear — no active alerts
                        </p>
                        <p class="dvm-empty-sub">
                            Monitoring <strong>{{ selectedChannelName }}</strong
                            >.<br />
                            Streams appear automatically when a DV alert is
                            triggered.
                        </p>
                    </div>
                    <div class="dvm-empty-hint">
                        <svg
                            width="11"
                            height="11"
                            viewBox="0 0 20 20"
                            fill="currentColor"
                        >
                            <path
                                fill-rule="evenodd"
                                d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"
                                clip-rule="evenodd"
                            />
                        </svg>
                        View past recordings via the Recordings button above
                    </div>
                </div>

                <!-- Stream grid -->
                <div
                    v-else
                    class="dvm-grid"
                    :class="`cols-${Math.min(screens.length, 3)}`"
                >
                    <div
                        v-for="screen in screens"
                        :key="screen.alertId"
                        class="dvm-screen"
                        :class="{
                            streaming: screen.isStreaming,
                            ended: screen.hasEnded,
                        }"
                    >
                        <!-- ── CALLER IDENTITY BLOCK ── -->
                        <div
                            class="dvm-caller-block"
                            :class="{ ended: screen.hasEnded }"
                        >
                            <div
                                class="dvm-caller-avatar"
                                :class="{ ended: screen.hasEnded }"
                            >
                                {{
                                    screen.recordingMeta?.victim_name
                                        ? callerInitials(
                                              screen.recordingMeta.victim_name,
                                          )
                                        : '?'
                                }}
                            </div>
                            <div class="dvm-caller-info">
                                <div class="dvm-caller-name">
                                    {{
                                        screen.recordingMeta?.victim_name ??
                                        screen.recordingMeta?.household_name ??
                                        'Identifying caller…'
                                    }}
                                </div>
                                <div class="dvm-caller-meta">
                                    <span
                                        v-if="
                                            screen.recordingMeta?.channel_name
                                        "
                                        class="dvm-caller-meta-item"
                                    >
                                        <svg
                                            width="10"
                                            height="10"
                                            viewBox="0 0 20 20"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.8"
                                        >
                                            <circle cx="10" cy="10" r="8" />
                                            <path
                                                d="M10 2a14 14 0 010 16M2 10h16"
                                            />
                                        </svg>
                                        {{ screen.recordingMeta.channel_name }}
                                    </span>
                                    <span
                                        v-if="screen.recordingMeta?.gps"
                                        class="dvm-caller-meta-item"
                                    >
                                        <svg
                                            width="10"
                                            height="10"
                                            viewBox="0 0 20 20"
                                            fill="currentColor"
                                        >
                                            <path
                                                fill-rule="evenodd"
                                                d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z"
                                                clip-rule="evenodd"
                                            />
                                        </svg>
                                        {{ screen.recordingMeta.gps }}
                                    </span>
                                    <span
                                        v-if="
                                            screen.recordingMeta
                                                ?.cancel_pin_used &&
                                            screen.recordingMeta
                                                .cancel_pin_used !== 'none'
                                        "
                                        class="dvm-pin-chip"
                                        :class="
                                            screen.recordingMeta.cancel_pin_used
                                        "
                                    >
                                        <svg
                                            v-if="
                                                screen.recordingMeta
                                                    .cancel_pin_used ===
                                                'duress'
                                            "
                                            width="8"
                                            height="8"
                                            viewBox="0 0 20 20"
                                            fill="currentColor"
                                        >
                                            <path
                                                fill-rule="evenodd"
                                                d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                                                clip-rule="evenodd"
                                            />
                                        </svg>
                                        <svg
                                            v-else
                                            width="8"
                                            height="8"
                                            viewBox="0 0 20 20"
                                            fill="currentColor"
                                        >
                                            <path
                                                fill-rule="evenodd"
                                                d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                clip-rule="evenodd"
                                            />
                                        </svg>
                                        {{
                                            screen.recordingMeta
                                                .cancel_pin_used === 'duress'
                                                ? 'DURESS PIN'
                                                : 'SAFE PIN'
                                        }}
                                    </span>
                                </div>
                            </div>
                            <div class="dvm-caller-right">
                                <div
                                    class="dvm-caller-badge"
                                    :class="
                                        screen.isStreaming ? 'live' : 'ended'
                                    "
                                >
                                    <span
                                        v-if="screen.isStreaming"
                                        class="dvm-live-blink"
                                    ></span>
                                    {{ screen.isStreaming ? 'LIVE' : 'ENDED' }}
                                </div>
                                <div class="dvm-alert-id">
                                    #{{ screen.alertId }}
                                </div>
                            </div>
                        </div>

                        <!-- ── WAVEFORM ── -->
                        <div class="dvm-screen-body">
                            <div
                                class="dvm-wave-wrap"
                                :class="{
                                    'live-wave':
                                        screen.isStreaming && !screen.isMuted,
                                }"
                            >
                                <div
                                    v-for="(h, i) in screen.waveformBars"
                                    :key="i"
                                    class="dvm-bar"
                                    :style="{
                                        height: `${h}%`,
                                        animationDelay: `${i * 20}ms`,
                                    }"
                                ></div>
                                <div
                                    v-if="screen.isMuted"
                                    class="dvm-muted-overlay"
                                >
                                    <svg
                                        width="14"
                                        height="14"
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                    >
                                        <path d="M9 3L5 7H2v6h3l4 4V3z" />
                                        <path
                                            d="M13 9l3 3M16 9l-3 3"
                                            stroke="currentColor"
                                            stroke-width="1.5"
                                            fill="none"
                                            stroke-linecap="round"
                                        />
                                    </svg>
                                    Muted
                                </div>
                            </div>

                            <!-- Controls row -->
                            <div class="dvm-ctrl-row">
                                <div class="dvm-ctrl-left">
                                    <span class="dvm-elapsed">{{
                                        formatElapsed(screen.elapsedSecs)
                                    }}</span>
                                    <div
                                        v-if="screen.isStreaming"
                                        class="dvm-live-footer"
                                    >
                                        <span
                                            class="dvm-live-pulse"
                                            :class="{ muted: screen.isMuted }"
                                        ></span>
                                        {{
                                            screen.isMuted
                                                ? 'Muted — tap to unmute'
                                                : 'Receiving live audio…'
                                        }}
                                    </div>
                                </div>
                                <div class="dvm-ctrl-right">
                                    <button
                                        v-if="screen.isStreaming"
                                        class="dvm-ctrl-btn"
                                        :class="{ muted: screen.isMuted }"
                                        :title="
                                            screen.isMuted ? 'Unmute' : 'Mute'
                                        "
                                        @click="toggleMute(screen)"
                                    >
                                        <svg
                                            v-if="!screen.isMuted"
                                            width="13"
                                            height="13"
                                            viewBox="0 0 20 20"
                                            fill="currentColor"
                                        >
                                            <path d="M9 3L5 7H2v6h3l4 4V3z" />
                                            <path
                                                d="M14.5 7.5a4 4 0 010 5M17 5a7 7 0 010 10"
                                                stroke="currentColor"
                                                stroke-width="1.5"
                                                fill="none"
                                                stroke-linecap="round"
                                            />
                                        </svg>
                                        <svg
                                            v-else
                                            width="13"
                                            height="13"
                                            viewBox="0 0 20 20"
                                            fill="currentColor"
                                        >
                                            <path d="M9 3L5 7H2v6h3l4 4V3z" />
                                            <path
                                                d="M13 9l3 3M16 9l-3 3"
                                                stroke="currentColor"
                                                stroke-width="1.5"
                                                fill="none"
                                                stroke-linecap="round"
                                            />
                                        </svg>
                                    </button>
                                    <button
                                        class="dvm-ctrl-btn dismiss"
                                        title="Dismiss alert"
                                        @click="removeScreen(screen.alertId)"
                                    >
                                        <svg
                                            width="12"
                                            height="12"
                                            viewBox="0 0 20 20"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2.2"
                                            stroke-linecap="round"
                                        >
                                            <path d="M5 5l10 10M15 5L5 15" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Ended: audio player -->
                            <div
                                v-if="screen.hasEnded && screen.streamUrl"
                                class="dvm-ended-section"
                            >
                                <div class="dvm-ended-label">
                                    <svg
                                        width="12"
                                        height="12"
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                    >
                                        <path
                                            fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                    Recording complete
                                    <span
                                        v-if="
                                            screen.recordingMeta?.duration_secs
                                        "
                                        class="dvm-ended-dur"
                                    >
                                        {{
                                            formatDuration(
                                                screen.recordingMeta
                                                    .duration_secs,
                                            )
                                        }}
                                    </span>
                                </div>
                                <div class="dvm-audio-wrap">
                                    <audio
                                        :src="screen.streamUrl"
                                        controls
                                        preload="metadata"
                                        class="dvm-audio"
                                    ></audio>
                                </div>
                                <a
                                    :href="screen.streamUrl"
                                    :download="`dv_alert_${screen.alertId}.mp3`"
                                    class="dvm-dl-btn"
                                >
                                    <svg
                                        width="11"
                                        height="11"
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                    >
                                        <path
                                            fill-rule="evenodd"
                                            d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                    Download Recording
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </main>

            <!-- ══ RECORDINGS MODAL ══════════════════════════════════════ -->
            <Teleport to="body">
                <Transition name="modal">
                    <div
                        v-if="showRecordingsModal"
                        class="dvm-modal-overlay"
                        @click.self="closeModal"
                    >
                        <div class="dvm-modal">
                            <!-- Modal header -->
                            <div class="dvm-modal-header">
                                <div class="dvm-modal-title-row">
                                    <div class="dvm-modal-icon">
                                        <svg
                                            width="15"
                                            height="15"
                                            viewBox="0 0 20 20"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.8"
                                        >
                                            <circle cx="10" cy="10" r="3" />
                                            <path
                                                d="M10 1v3M10 16v3M1 10h3M16 10h3"
                                                stroke-linecap="round"
                                            />
                                        </svg>
                                    </div>
                                    <div>
                                        <h2 class="dvm-modal-title">
                                            {{
                                                selectedHousehold
                                                    ? selectedHousehold.householdName
                                                    : 'DV Recordings'
                                            }}
                                        </h2>
                                        <p class="dvm-modal-subtitle">
                                            <template v-if="selectedHousehold">
                                                {{
                                                    selectedHousehold.totalCount
                                                }}
                                                recording{{
                                                    selectedHousehold.totalCount !==
                                                    1
                                                        ? 's'
                                                        : ''
                                                }}
                                                <span
                                                    v-if="
                                                        selectedHousehold.address ||
                                                        selectedHousehold.gps
                                                    "
                                                >
                                                    ·
                                                    {{
                                                        selectedHousehold.address ??
                                                        selectedHousehold.gps
                                                    }}
                                                </span>
                                            </template>
                                            <template v-else>
                                                {{ groupedRecordings.length }}
                                                households ·
                                                {{ pastRecordings.length }}
                                                total recordings
                                            </template>
                                        </p>
                                    </div>
                                </div>
                                <div class="dvm-modal-actions">
                                    <button
                                        v-if="selectedHousehold"
                                        class="dvm-back-btn"
                                        @click="closeHousehold"
                                    >
                                        <svg
                                            width="12"
                                            height="12"
                                            viewBox="0 0 20 20"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2.2"
                                            stroke-linecap="round"
                                        >
                                            <path d="M12 4l-7 6 7 6" />
                                        </svg>
                                        All Households
                                    </button>
                                    <button
                                        class="dvm-modal-close"
                                        @click="closeModal"
                                        title="Close"
                                    >
                                        <svg
                                            width="13"
                                            height="13"
                                            viewBox="0 0 20 20"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2.2"
                                            stroke-linecap="round"
                                        >
                                            <path d="M5 5l10 10M15 5L5 15" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Search -->
                            <div
                                v-if="!selectedHousehold"
                                class="dvm-modal-search-bar"
                            >
                                <svg
                                    width="13"
                                    height="13"
                                    viewBox="0 0 20 20"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                >
                                    <circle cx="9" cy="9" r="6" />
                                    <path d="M15 15l-3.5-3.5" />
                                </svg>
                                <input
                                    v-model="modalSearch"
                                    class="dvm-modal-search"
                                    placeholder="Search by name, address, alert ID…"
                                />
                                <button
                                    v-if="modalSearch"
                                    @click="modalSearch = ''"
                                    class="dvm-clear-btn"
                                >
                                    <svg
                                        width="11"
                                        height="11"
                                        viewBox="0 0 20 20"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2.2"
                                        stroke-linecap="round"
                                    >
                                        <path d="M5 5l10 10M15 5L5 15" />
                                    </svg>
                                </button>
                            </div>

                            <!-- Loading -->
                            <div
                                v-if="loadingHistory"
                                class="dvm-modal-loading"
                            >
                                <div class="dvm-spinner"></div>
                                <span>Loading recordings…</span>
                            </div>

                            <!-- ── HOUSEHOLD LIST ── -->
                            <div
                                v-else-if="!selectedHousehold"
                                class="dvm-modal-body"
                            >
                                <div
                                    v-if="groupedRecordings.length === 0"
                                    class="dvm-modal-empty"
                                >
                                    <svg
                                        width="40"
                                        height="40"
                                        viewBox="0 0 20 20"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1"
                                        opacity=".2"
                                    >
                                        <circle cx="10" cy="10" r="8" />
                                        <path d="M10 6v4l3 3" />
                                    </svg>
                                    <p>No recordings found</p>
                                    <p class="dvm-modal-empty-hint">
                                        Try adjusting your search or select a
                                        different channel
                                    </p>
                                </div>
                                <div v-else class="dvm-household-grid">
                                    <button
                                        v-for="group in groupedRecordings"
                                        :key="group.householdId"
                                        class="dvm-household-card"
                                        @click="openHousehold(group)"
                                    >
                                        <div class="dvm-hh-left">
                                            <div class="dvm-hh-avatar">
                                                {{
                                                    (group.householdName || 'U')
                                                        .charAt(0)
                                                        .toUpperCase()
                                                }}
                                            </div>
                                            <div class="dvm-hh-info">
                                                <div class="dvm-hh-name">
                                                    {{ group.householdName }}
                                                </div>
                                                <div class="dvm-hh-meta">
                                                    <span
                                                        v-if="
                                                            group.address ||
                                                            group.gps
                                                        "
                                                        class="dvm-hh-meta-item"
                                                    >
                                                        <svg
                                                            width="9"
                                                            height="9"
                                                            viewBox="0 0 20 20"
                                                            fill="currentColor"
                                                        >
                                                            <path
                                                                fill-rule="evenodd"
                                                                d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z"
                                                                clip-rule="evenodd"
                                                            />
                                                        </svg>
                                                        {{
                                                            group.address ??
                                                            group.gps
                                                        }}
                                                    </span>
                                                    <span
                                                        class="dvm-hh-meta-item"
                                                    >
                                                        <svg
                                                            width="9"
                                                            height="9"
                                                            viewBox="0 0 20 20"
                                                            fill="currentColor"
                                                        >
                                                            <path
                                                                fill-rule="evenodd"
                                                                d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z"
                                                                clip-rule="evenodd"
                                                            />
                                                        </svg>
                                                        {{
                                                            timeAgo(
                                                                group.latestAt,
                                                            )
                                                        }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="dvm-hh-right">
                                            <div class="dvm-hh-stats">
                                                <div class="dvm-hh-count">
                                                    {{ group.totalCount }}
                                                </div>
                                                <div class="dvm-hh-count-lbl">
                                                    alert{{
                                                        group.totalCount !== 1
                                                            ? 's'
                                                            : ''
                                                    }}
                                                </div>
                                            </div>
                                            <span
                                                v-if="
                                                    group.latestRec
                                                        .cancel_pin_used &&
                                                    group.latestRec
                                                        .cancel_pin_used !==
                                                        'none'
                                                "
                                                class="dvm-pin-tag"
                                                :class="
                                                    group.latestRec
                                                        .cancel_pin_used
                                                "
                                            >
                                                {{
                                                    group.latestRec
                                                        .cancel_pin_used ===
                                                    'duress'
                                                        ? '⚠ Duress'
                                                        : '✓ Safe'
                                                }}
                                            </span>
                                            <svg
                                                width="13"
                                                height="13"
                                                viewBox="0 0 20 20"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="1.8"
                                                class="dvm-hh-arrow"
                                            >
                                                <path
                                                    d="M7 5l5 5-5 5"
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                />
                                            </svg>
                                        </div>
                                    </button>
                                </div>
                            </div>

                            <!-- ── HOUSEHOLD DETAIL ── -->
                            <div v-else class="dvm-modal-body">
                                <div class="dvm-detail-header">
                                    <div class="dvm-detail-avatar">
                                        {{
                                            (
                                                selectedHousehold.householdName ||
                                                'U'
                                            )
                                                .charAt(0)
                                                .toUpperCase()
                                        }}
                                    </div>
                                    <div class="dvm-detail-info">
                                        <h3 class="dvm-detail-name">
                                            {{
                                                selectedHousehold.householdName
                                            }}
                                        </h3>
                                        <div class="dvm-detail-chips">
                                            <span
                                                v-if="
                                                    selectedHousehold.address ||
                                                    selectedHousehold.gps
                                                "
                                                class="dvm-detail-chip"
                                            >
                                                <svg
                                                    width="10"
                                                    height="10"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fill-rule="evenodd"
                                                        d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z"
                                                        clip-rule="evenodd"
                                                    />
                                                </svg>
                                                {{
                                                    selectedHousehold.address ??
                                                    selectedHousehold.gps
                                                }}
                                            </span>
                                            <span class="dvm-detail-chip">
                                                {{
                                                    selectedHousehold.totalCount
                                                }}
                                                total ·
                                                {{
                                                    filteredHouseholdRecordings.length
                                                }}
                                                shown
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Filter toolbar -->
                                <div class="dvm-filter-toolbar">
                                    <div class="dvm-filter-search-wrap">
                                        <svg
                                            width="12"
                                            height="12"
                                            viewBox="0 0 20 20"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2"
                                        >
                                            <circle cx="9" cy="9" r="6" />
                                            <path d="M15 15l-3.5-3.5" />
                                        </svg>
                                        <input
                                            v-model="detailSearch"
                                            class="dvm-filter-search"
                                            placeholder="Search alert ID, GPS…"
                                        />
                                        <button
                                            v-if="detailSearch"
                                            @click="detailSearch = ''"
                                            class="dvm-clear-btn"
                                        >
                                            ×
                                        </button>
                                    </div>
                                    <select
                                        v-model="detailSortBy"
                                        class="dvm-filter-select"
                                    >
                                        <option value="newest">
                                            Newest first
                                        </option>
                                        <option value="oldest">
                                            Oldest first
                                        </option>
                                        <option value="longest">
                                            Longest first
                                        </option>
                                        <option value="shortest">
                                            Shortest first
                                        </option>
                                    </select>
                                    <button
                                        class="dvm-filter-toggle-btn"
                                        :class="{
                                            active:
                                                showDetailFilters ||
                                                activeDetailFilterCount > 0,
                                        }"
                                        @click="
                                            showDetailFilters =
                                                !showDetailFilters
                                        "
                                    >
                                        <svg
                                            width="12"
                                            height="12"
                                            viewBox="0 0 20 20"
                                            fill="currentColor"
                                        >
                                            <path
                                                fill-rule="evenodd"
                                                d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm2 4a1 1 0 011-1h8a1 1 0 110 2H6a1 1 0 01-1-1zm2 4a1 1 0 011-1h4a1 1 0 110 2H8a1 1 0 01-1-1z"
                                                clip-rule="evenodd"
                                            />
                                        </svg>
                                        Filters
                                        <span
                                            v-if="activeDetailFilterCount > 0"
                                            class="dvm-filter-count"
                                            >{{ activeDetailFilterCount }}</span
                                        >
                                    </button>
                                    <button
                                        v-if="activeDetailFilterCount > 0"
                                        class="dvm-filter-reset-btn"
                                        @click="resetDetailFilters"
                                    >
                                        Reset
                                    </button>
                                </div>

                                <!-- Advanced filter panel -->
                                <Transition name="filters">
                                    <div
                                        v-if="showDetailFilters"
                                        class="dvm-filter-panel"
                                    >
                                        <div class="dvm-filter-group">
                                            <label class="dvm-filter-label"
                                                >Date Range</label
                                            >
                                            <div class="dvm-filter-row">
                                                <div class="dvm-filter-field">
                                                    <span
                                                        class="dvm-field-label"
                                                        >From</span
                                                    >
                                                    <input
                                                        type="date"
                                                        v-model="
                                                            detailFilterFrom
                                                        "
                                                        class="dvm-date-input"
                                                    />
                                                </div>
                                                <div class="dvm-filter-arrow">
                                                    →
                                                </div>
                                                <div class="dvm-filter-field">
                                                    <span
                                                        class="dvm-field-label"
                                                        >To</span
                                                    >
                                                    <input
                                                        type="date"
                                                        v-model="detailFilterTo"
                                                        class="dvm-date-input"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                        <div class="dvm-filter-group">
                                            <label class="dvm-filter-label"
                                                >Time of Day</label
                                            >
                                            <div class="dvm-filter-row">
                                                <div class="dvm-filter-field">
                                                    <span
                                                        class="dvm-field-label"
                                                        >From</span
                                                    >
                                                    <input
                                                        type="time"
                                                        v-model="
                                                            detailFilterTimeFrom
                                                        "
                                                        class="dvm-date-input"
                                                    />
                                                </div>
                                                <div class="dvm-filter-arrow">
                                                    →
                                                </div>
                                                <div class="dvm-filter-field">
                                                    <span
                                                        class="dvm-field-label"
                                                        >To</span
                                                    >
                                                    <input
                                                        type="time"
                                                        v-model="
                                                            detailFilterTimeTo
                                                        "
                                                        class="dvm-date-input"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                        <div class="dvm-filter-group">
                                            <label class="dvm-filter-label"
                                                >Duration (seconds)</label
                                            >
                                            <div class="dvm-filter-row">
                                                <div class="dvm-filter-field">
                                                    <span
                                                        class="dvm-field-label"
                                                        >Min</span
                                                    >
                                                    <input
                                                        type="number"
                                                        v-model="
                                                            detailFilterMinDur
                                                        "
                                                        min="0"
                                                        placeholder="0"
                                                        class="dvm-date-input"
                                                    />
                                                </div>
                                                <div class="dvm-filter-arrow">
                                                    →
                                                </div>
                                                <div class="dvm-filter-field">
                                                    <span
                                                        class="dvm-field-label"
                                                        >Max</span
                                                    >
                                                    <input
                                                        type="number"
                                                        v-model="
                                                            detailFilterMaxDur
                                                        "
                                                        min="0"
                                                        placeholder="∞"
                                                        class="dvm-date-input"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                        <div class="dvm-filter-group">
                                            <label class="dvm-filter-label"
                                                >PIN Type</label
                                            >
                                            <div class="dvm-filter-pills">
                                                <button
                                                    v-for="opt in [
                                                        { v: 'all', l: 'All' },
                                                        {
                                                            v: 'duress',
                                                            l: '⚠ Duress',
                                                        },
                                                        {
                                                            v: 'safe',
                                                            l: '✓ Safe',
                                                        },
                                                        {
                                                            v: 'none',
                                                            l: 'No PIN',
                                                        },
                                                    ]"
                                                    :key="opt.v"
                                                    class="dvm-pill-btn"
                                                    :class="[
                                                        {
                                                            active:
                                                                detailFilterPin ===
                                                                opt.v,
                                                        },
                                                        opt.v,
                                                    ]"
                                                    @click="
                                                        detailFilterPin =
                                                            opt.v as any
                                                    "
                                                >
                                                    {{ opt.l }}
                                                </button>
                                            </div>
                                        </div>
                                        <div class="dvm-filter-group">
                                            <label class="dvm-filter-label"
                                                >Status</label
                                            >
                                            <div class="dvm-filter-pills">
                                                <button
                                                    v-for="opt in [
                                                        { v: 'all', l: 'All' },
                                                        {
                                                            v: 'finalised',
                                                            l: 'Completed',
                                                        },
                                                        {
                                                            v: 'live',
                                                            l: '● Live',
                                                        },
                                                    ]"
                                                    :key="opt.v"
                                                    class="dvm-pill-btn"
                                                    :class="{
                                                        active:
                                                            detailFilterStatus ===
                                                            opt.v,
                                                    }"
                                                    @click="
                                                        detailFilterStatus =
                                                            opt.v as any
                                                    "
                                                >
                                                    {{ opt.l }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </Transition>

                                <!-- Recording list -->
                                <div
                                    v-if="
                                        filteredHouseholdRecordings.length === 0
                                    "
                                    class="dvm-no-results"
                                >
                                    <svg
                                        width="32"
                                        height="32"
                                        viewBox="0 0 20 20"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1"
                                        opacity=".2"
                                    >
                                        <circle cx="10" cy="10" r="8" />
                                        <path d="M10 6v4l3 3" />
                                    </svg>
                                    <p>
                                        No recordings match the current filters
                                    </p>
                                    <button
                                        class="dvm-filter-reset-btn"
                                        @click="resetDetailFilters"
                                    >
                                        Clear filters
                                    </button>
                                </div>
                                <div v-else class="dvm-rec-list">
                                    <div
                                        v-for="rec in filteredHouseholdRecordings"
                                        :key="rec.id"
                                        class="dvm-rec-card"
                                        :class="{
                                            playing:
                                                playingId ===
                                                String(rec.alert_id),
                                        }"
                                    >
                                        <div class="dvm-rec-main">
                                            <div class="dvm-rec-info">
                                                <div class="dvm-rec-header-row">
                                                    <span class="dvm-rec-num"
                                                        >#{{
                                                            rec.alert_id
                                                        }}</span
                                                    >
                                                    <span
                                                        v-if="!rec.is_finalised"
                                                        class="dvm-live-badge"
                                                        >● Live</span
                                                    >
                                                    <span
                                                        v-if="
                                                            rec.cancel_pin_used &&
                                                            rec.cancel_pin_used !==
                                                                'none'
                                                        "
                                                        class="dvm-pin-tag sm"
                                                        :class="
                                                            rec.cancel_pin_used
                                                        "
                                                    >
                                                        {{
                                                            rec.cancel_pin_used ===
                                                            'duress'
                                                                ? '⚠ Duress'
                                                                : '✓ Safe'
                                                        }}
                                                    </span>
                                                </div>
                                                <div class="dvm-rec-meta">
                                                    <span>
                                                        <svg
                                                            width="9"
                                                            height="9"
                                                            viewBox="0 0 20 20"
                                                            fill="currentColor"
                                                        >
                                                            <path
                                                                fill-rule="evenodd"
                                                                d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z"
                                                                clip-rule="evenodd"
                                                            />
                                                        </svg>
                                                        {{
                                                            formatTimestamp(
                                                                rec.started_at,
                                                            )
                                                        }}
                                                    </span>
                                                    <span>
                                                        <svg
                                                            width="9"
                                                            height="9"
                                                            viewBox="0 0 20 20"
                                                            fill="currentColor"
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
                                                        {{
                                                            formatDuration(
                                                                rec.duration_secs,
                                                            )
                                                        }}
                                                    </span>
                                                    <span v-if="rec.gps">
                                                        <svg
                                                            width="9"
                                                            height="9"
                                                            viewBox="0 0 20 20"
                                                            fill="currentColor"
                                                        >
                                                            <path
                                                                fill-rule="evenodd"
                                                                d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z"
                                                                clip-rule="evenodd"
                                                            />
                                                        </svg>
                                                        {{ rec.gps }}
                                                    </span>
                                                    <span
                                                        class="dvm-time-ago"
                                                        >{{
                                                            timeAgo(
                                                                rec.started_at,
                                                            )
                                                        }}</span
                                                    >
                                                </div>
                                            </div>
                                            <div
                                                class="dvm-rec-controls"
                                                v-if="rec.is_finalised"
                                            >
                                                <button
                                                    class="dvm-play-btn"
                                                    :class="{
                                                        active:
                                                            playingId ===
                                                            String(
                                                                rec.alert_id,
                                                            ),
                                                    }"
                                                    @click="
                                                        togglePlay(
                                                            String(
                                                                rec.alert_id,
                                                            ),
                                                        )
                                                    "
                                                >
                                                    <svg
                                                        v-if="
                                                            playingId !==
                                                            String(rec.alert_id)
                                                        "
                                                        width="11"
                                                        height="11"
                                                        viewBox="0 0 20 20"
                                                        fill="currentColor"
                                                    >
                                                        <path
                                                            d="M6 4l12 6-12 6V4z"
                                                        />
                                                    </svg>
                                                    <svg
                                                        v-else
                                                        width="11"
                                                        height="11"
                                                        viewBox="0 0 20 20"
                                                        fill="currentColor"
                                                    >
                                                        <path
                                                            d="M6 4h3v12H6zM11 4h3v12h-3z"
                                                        />
                                                    </svg>
                                                    {{
                                                        playingId ===
                                                        String(rec.alert_id)
                                                            ? 'Pause'
                                                            : 'Play'
                                                    }}
                                                </button>
                                                <a
                                                    :href="
                                                        streamUri(rec.alert_id)
                                                    "
                                                    :download="`dv_${rec.alert_id}.mp3`"
                                                    class="dvm-dl-btn-sm"
                                                    title="Download"
                                                >
                                                    <svg
                                                        width="11"
                                                        height="11"
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
                                            <div
                                                v-else
                                                class="dvm-recording-live"
                                            >
                                                <span
                                                    class="dvm-live-badge pulse"
                                                    >● Recording</span
                                                >
                                            </div>
                                        </div>
                                        <Transition name="player">
                                            <div
                                                v-if="
                                                    playingId ===
                                                    String(rec.alert_id)
                                                "
                                                class="dvm-inline-player"
                                            >
                                                <audio
                                                    :src="
                                                        streamUri(rec.alert_id)
                                                    "
                                                    controls
                                                    autoplay
                                                    class="dvm-audio"
                                                    @ended="playingId = null"
                                                ></audio>
                                            </div>
                                        </Transition>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </Transition>
            </Teleport>
        </div>
    </AppLayout>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=Syne:wght@400;500;600;700;800&display=swap');

/* ════════════════════════════════════════════════════════════
   DESIGN TOKENS — Dark command-centre aesthetic
   ════════════════════════════════════════════════════════════ */
.dvm-root {
    --c0: #080c12;
    --c1: #0d1420;
    --c2: #111b2e;
    --c3: #172035;
    --c4: #1e2d47;
    --border: rgba(255, 255, 255, 0.06);
    --border2: rgba(255, 255, 255, 0.12);
    --accent: #e63946;
    --accent2: #ff6b6b;
    --blue: #4cc9f0;
    --green: #06d6a0;
    --amber: #ffd166;
    --text: #e8edf4;
    --text2: #7a8fa8;
    --text3: #3d5170;
    --radius: 12px;
    --radius-sm: 8px;
    --mono: 'IBM Plex Mono', 'Fira Mono', monospace;
    --sans: 'Syne', system-ui, sans-serif;

    font-family: var(--sans);
    background: var(--c0);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* ── TOP BAR ─────────────────────────────────────────────── */
.dvm-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 22px;
    height: 56px;
    background: var(--c1);
    border-bottom: 1px solid var(--border);
    position: sticky;
    top: 0;
    z-index: 50;
    gap: 14px;
    flex-wrap: wrap;
}

.dvm-topbar-left {
    display: flex;
    align-items: center;
    gap: 12px;
}

.dvm-shield {
    width: 34px;
    height: 34px;
    background: var(--accent);
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.dvm-title {
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--text);
    margin: 0;
    line-height: 1;
}

.dvm-subtitle {
    font-size: 9px;
    color: var(--text3);
    font-family: var(--mono);
    letter-spacing: 0.06em;
    margin: 3px 0 0;
}

.dvm-alert-counter {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    background: rgba(230, 57, 70, 0.12);
    border: 1px solid rgba(230, 57, 70, 0.25);
    border-radius: 999px;
    font-size: 10px;
    font-weight: 600;
    color: var(--accent2);
    font-family: var(--mono);
    letter-spacing: 0.04em;
}
.dvm-alert-counter-dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: var(--accent);
    animation: blink 1s infinite;
}

.dvm-topbar-right {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

/* Channel selector */
.dvm-channel-wrap {
    position: relative;
}

.dvm-channel-btn {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 7px 12px;
    background: var(--c3);
    border: 1px solid var(--border2);
    border-radius: var(--radius-sm);
    color: var(--text2);
    font-size: 11px;
    font-family: var(--sans);
    cursor: pointer;
    transition: all 0.15s;
}
.dvm-channel-btn:hover {
    background: var(--c4);
    color: var(--text);
    border-color: rgba(255, 255, 255, 0.2);
}
.dvm-channel-btn strong {
    color: var(--text);
    font-weight: 600;
}

.dvm-channel-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--blue);
    flex-shrink: 0;
}

.dvm-channel-dropdown {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    min-width: 240px;
    background: var(--c2);
    border: 1px solid var(--border2);
    border-radius: var(--radius);
    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.5);
    z-index: 200;
    overflow: hidden;
}

.dropdown-enter-active,
.dropdown-leave-active {
    transition:
        opacity 0.15s,
        transform 0.15s;
}
.dropdown-enter-from,
.dropdown-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}

.dvm-channel-search-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 13px;
    border-bottom: 1px solid var(--border);
    color: var(--text3);
}
.dvm-channel-search {
    flex: 1;
    background: none;
    border: none;
    outline: none;
    color: var(--text);
    font-size: 12px;
    font-family: var(--sans);
}
.dvm-channel-search::placeholder {
    color: var(--text3);
}

.dvm-channel-list {
    max-height: 220px;
    overflow-y: auto;
    padding: 6px;
}
.dvm-channel-empty {
    padding: 18px;
    text-align: center;
    color: var(--text3);
    font-size: 12px;
    font-family: var(--mono);
}

.dvm-channel-item {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 8px 10px;
    background: none;
    border: none;
    border-radius: 6px;
    color: var(--text2);
    font-size: 12px;
    font-family: var(--sans);
    cursor: pointer;
    text-align: left;
    transition: all 0.1s;
}
.dvm-channel-item:hover {
    background: var(--c3);
    color: var(--text);
}
.dvm-channel-item.active {
    color: var(--blue);
    font-weight: 600;
}
.dvm-channel-item-dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: var(--border2);
    flex-shrink: 0;
    transition: background 0.15s;
}
.dvm-channel-item-dot.active {
    background: var(--blue);
}

/* Recordings button */
.dvm-rec-btn {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 7px 13px;
    background: var(--c3);
    border: 1px solid var(--border2);
    border-radius: var(--radius-sm);
    color: var(--text2);
    font-size: 11px;
    font-family: var(--sans);
    cursor: pointer;
    transition: all 0.15s;
}
.dvm-rec-btn:hover {
    background: var(--accent);
    color: #fff;
    border-color: var(--accent);
}
.dvm-rec-count {
    background: rgba(230, 57, 70, 0.2);
    color: var(--accent2);
    border-radius: 999px;
    padding: 1px 6px;
    font-size: 9px;
    font-weight: 700;
    font-family: var(--mono);
}
.dvm-rec-btn:hover .dvm-rec-count {
    background: rgba(255, 255, 255, 0.2);
    color: #fff;
}

/* Status pill */
.dvm-status-pill {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 5px 11px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.08em;
    font-family: var(--mono);
    border: 1px solid transparent;
}
.dvm-status-pill.connected {
    background: rgba(6, 214, 160, 0.1);
    border-color: rgba(6, 214, 160, 0.25);
    color: var(--green);
}
.dvm-status-pill.offline {
    background: rgba(230, 57, 70, 0.1);
    border-color: rgba(230, 57, 70, 0.2);
    color: var(--accent);
}
.dvm-status-dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: currentColor;
}
.dvm-status-pill.connected .dvm-status-dot {
    animation: blink 2s infinite;
}

/* Clear button */
.dvm-clear-btn {
    background: none;
    border: none;
    color: var(--text3);
    cursor: pointer;
    padding: 2px 4px;
    display: flex;
    align-items: center;
    border-radius: 4px;
    transition: color 0.15s;
    font-size: 14px;
    line-height: 1;
}
.dvm-clear-btn:hover {
    color: var(--text);
}

/* ── STATS BAR ───────────────────────────────────────────── */
.dvm-stats-bar {
    display: flex;
    align-items: center;
    padding: 0 22px;
    height: 46px;
    background: var(--c1);
    border-bottom: 1px solid var(--border);
    gap: 0;
    flex-shrink: 0;
    animation: slideDown 0.3s ease;
}
.dvm-stat {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 22px 0 0;
}
.dvm-stat:first-child {
    padding-left: 0;
}
.dvm-stat-val {
    font-size: 20px;
    font-weight: 700;
    font-family: var(--mono);
    line-height: 1;
    letter-spacing: -0.02em;
    color: var(--text);
}
.dvm-stat-val.live {
    color: var(--accent);
}
.dvm-stat-val.ended {
    color: var(--blue);
}
.dvm-stat-val.warn {
    color: var(--amber);
}
.dvm-stat-lbl {
    font-size: 9px;
    color: var(--text3);
    font-family: var(--mono);
    letter-spacing: 0.08em;
    text-transform: uppercase;
    line-height: 1.3;
}
.dvm-stat-div {
    width: 1px;
    height: 26px;
    background: var(--border);
    margin: 0 22px 0 0;
}

/* ── CANVAS ──────────────────────────────────────────────── */
.dvm-canvas {
    flex: 1;
    padding: 20px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

/* ── GRID ────────────────────────────────────────────────── */
.dvm-grid {
    display: grid;
    gap: 14px;
}
.dvm-grid.cols-1 {
    grid-template-columns: 1fr;
    max-width: 560px;
}
.dvm-grid.cols-2 {
    grid-template-columns: repeat(2, 1fr);
}
.dvm-grid.cols-3 {
    grid-template-columns: repeat(3, 1fr);
}
@media (max-width: 960px) {
    .dvm-grid.cols-3 {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 640px) {
    .dvm-grid.cols-2,
    .dvm-grid.cols-3 {
        grid-template-columns: 1fr;
    }
}

/* ── SCREEN CARD ─────────────────────────────────────────── */
.dvm-screen {
    background: var(--c1);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: border-color 0.25s;
    animation: cardIn 0.35s cubic-bezier(0.16, 1, 0.3, 1);
}
.dvm-screen.streaming {
    border-color: rgba(230, 57, 70, 0.35);
}
.dvm-screen.ended {
    border-color: rgba(76, 201, 240, 0.2);
}

@keyframes cardIn {
    from {
        opacity: 0;
        transform: translateY(14px) scale(0.98);
    }
    to {
        opacity: 1;
        transform: none;
    }
}
@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-8px);
    }
    to {
        opacity: 1;
        transform: none;
    }
}

/* ── CALLER IDENTITY BLOCK ───────────────────────────────── */
.dvm-caller-block {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: linear-gradient(
        135deg,
        rgba(230, 57, 70, 0.13) 0%,
        rgba(230, 57, 70, 0.03) 100%
    );
    border-bottom: 1px solid rgba(230, 57, 70, 0.14);
    position: relative;
    overflow: hidden;
}
.dvm-caller-block::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background: var(--accent);
    border-radius: 0 2px 2px 0;
}
.dvm-caller-block.ended {
    background: linear-gradient(
        135deg,
        rgba(76, 201, 240, 0.08) 0%,
        rgba(76, 201, 240, 0.02) 100%
    );
    border-bottom-color: rgba(76, 201, 240, 0.12);
}
.dvm-caller-block.ended::before {
    background: var(--blue);
}

.dvm-caller-avatar {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    background: rgba(230, 57, 70, 0.18);
    border: 1px solid rgba(230, 57, 70, 0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    font-weight: 700;
    color: var(--accent2);
    flex-shrink: 0;
    font-family: var(--mono);
}
.dvm-caller-avatar.ended {
    background: rgba(76, 201, 240, 0.1);
    border-color: rgba(76, 201, 240, 0.25);
    color: var(--blue);
}

.dvm-caller-info {
    flex: 1;
    min-width: 0;
}
.dvm-caller-name {
    font-size: 14px;
    font-weight: 700;
    color: var(--text);
    letter-spacing: -0.01em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 4px;
}

.dvm-caller-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.dvm-caller-meta-item {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 10px;
    color: var(--text2);
    font-family: var(--mono);
}

.dvm-pin-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 7px;
    border-radius: 999px;
    font-size: 9px;
    font-weight: 700;
    font-family: var(--mono);
    letter-spacing: 0.05em;
}
.dvm-pin-chip.duress {
    background: rgba(230, 57, 70, 0.15);
    color: var(--accent2);
    border: 1px solid rgba(230, 57, 70, 0.3);
}
.dvm-pin-chip.safe {
    background: rgba(6, 214, 160, 0.1);
    color: var(--green);
    border: 1px solid rgba(6, 214, 160, 0.25);
}

.dvm-caller-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 5px;
    flex-shrink: 0;
}

.dvm-caller-badge {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    font-family: var(--mono);
}
.dvm-caller-badge.live {
    background: rgba(230, 57, 70, 0.15);
    border: 1px solid rgba(230, 57, 70, 0.35);
    color: var(--accent2);
}
.dvm-caller-badge.ended {
    background: rgba(76, 201, 240, 0.1);
    border: 1px solid rgba(76, 201, 240, 0.25);
    color: var(--blue);
}

.dvm-live-blink {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: var(--accent);
    animation: blink 1s infinite;
}

.dvm-alert-id {
    font-family: var(--mono);
    font-size: 10px;
    color: var(--text3);
    letter-spacing: 0.06em;
}

/* ── SCREEN BODY ─────────────────────────────────────────── */
.dvm-screen-body {
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

/* ── WAVEFORM ────────────────────────────────────────────── */
.dvm-wave-wrap {
    background: var(--c2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    height: 72px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 2px;
    padding: 10px 14px;
    position: relative;
    overflow: hidden;
    transition: border-color 0.2s;
}
.dvm-wave-wrap.live-wave {
    border-color: rgba(230, 57, 70, 0.2);
}

.dvm-bar {
    flex: 1;
    min-width: 2px;
    max-width: 5px;
    border-radius: 2px;
    background: var(--border2);
    transition: height 0.1s ease;
    min-height: 3px;
    align-self: center;
}
.dvm-wave-wrap.live-wave .dvm-bar {
    background: var(--accent);
    opacity: 0.7;
}
.dvm-wave-wrap.live-wave .dvm-bar:nth-child(even) {
    opacity: 1;
}

.dvm-muted-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    background: rgba(255, 209, 102, 0.06);
    color: var(--amber);
    font-size: 11px;
    font-family: var(--mono);
    backdrop-filter: blur(3px);
}

/* ── CONTROLS ────────────────────────────────────────────── */
.dvm-ctrl-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.dvm-ctrl-left {
    display: flex;
    align-items: center;
    gap: 8px;
}
.dvm-ctrl-right {
    display: flex;
    align-items: center;
    gap: 6px;
}

.dvm-elapsed {
    font-size: 12px;
    font-family: var(--mono);
    color: var(--text2);
    letter-spacing: 0.05em;
    min-width: 36px;
    font-variant-numeric: tabular-nums;
}

.dvm-live-footer {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 10px;
    font-family: var(--mono);
    color: var(--text3);
    letter-spacing: 0.03em;
}
.dvm-live-pulse {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--accent);
    animation: blink 1.4s infinite;
    flex-shrink: 0;
}
.dvm-live-pulse.muted {
    background: var(--amber);
    animation: none;
}

.dvm-ctrl-btn {
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--c3);
    border: 1px solid var(--border2);
    border-radius: 7px;
    color: var(--text2);
    cursor: pointer;
    transition: all 0.12s;
}
.dvm-ctrl-btn:hover {
    background: var(--c4);
    color: var(--text);
    border-color: rgba(255, 255, 255, 0.18);
}
.dvm-ctrl-btn.muted {
    background: rgba(255, 209, 102, 0.1);
    color: var(--amber);
    border-color: rgba(255, 209, 102, 0.2);
}
.dvm-ctrl-btn.dismiss:hover {
    background: rgba(230, 57, 70, 0.1);
    color: var(--accent);
    border-color: rgba(230, 57, 70, 0.25);
}

/* ── ENDED SECTION ───────────────────────────────────────── */
.dvm-ended-section {
    display: flex;
    flex-direction: column;
    gap: 9px;
}

.dvm-ended-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 10px;
    font-family: var(--mono);
    color: var(--green);
    letter-spacing: 0.04em;
}
.dvm-ended-dur {
    margin-left: 4px;
    color: var(--text3);
}

.dvm-audio-wrap {
    background: var(--c2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 2px 8px;
}
.dvm-audio {
    width: 100%;
    height: 32px;
    display: block;
}

.dvm-dl-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 13px;
    background: var(--c3);
    border: 1px solid var(--border2);
    border-radius: var(--radius-sm);
    color: var(--text2);
    font-size: 11px;
    font-family: var(--sans);
    text-decoration: none;
    cursor: pointer;
    transition: all 0.12s;
    width: fit-content;
}
.dvm-dl-btn:hover {
    background: var(--blue);
    color: var(--c0);
    border-color: var(--blue);
}

/* ── EMPTY STATE ─────────────────────────────────────────── */
.dvm-empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 18px;
    min-height: 60vh;
    text-align: center;
}
.dvm-empty-ring {
    width: 88px;
    height: 88px;
    border-radius: 50%;
    border: 1.5px solid var(--border2);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    color: var(--text3);
}
.dvm-empty-ring::before {
    content: '';
    position: absolute;
    inset: -10px;
    border-radius: 50%;
    border: 1px solid var(--border);
}
.dvm-empty-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 8px;
}
.dvm-empty-sub {
    font-size: 12px;
    color: var(--text2);
    line-height: 1.7;
    max-width: 340px;
    font-family: var(--mono);
}
.dvm-empty-sub strong {
    color: var(--text);
}
.dvm-empty-hint {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 10px;
    font-family: var(--mono);
    color: var(--text3);
    background: var(--c2);
    border: 1px solid var(--border);
    border-radius: 999px;
    padding: 5px 14px;
}

/* ── ANIMATIONS ──────────────────────────────────────────── */
@keyframes blink {
    0%,
    100% {
        opacity: 1;
    }
    50% {
        opacity: 0.3;
    }
}

/* ════════════════════════════════════════════════════════════
   MODAL — tokens re-declared (teleported outside .dvm-root)
   ════════════════════════════════════════════════════════════ */
.dvm-modal-overlay {
    --c0: #080c12;
    --c1: #0d1420;
    --c2: #111b2e;
    --c3: #172035;
    --c4: #1e2d47;
    --border: rgba(255, 255, 255, 0.06);
    --border2: rgba(255, 255, 255, 0.12);
    --accent: #e63946;
    --accent2: #ff6b6b;
    --blue: #4cc9f0;
    --green: #06d6a0;
    --amber: #ffd166;
    --text: #e8edf4;
    --text2: #7a8fa8;
    --text3: #3d5170;
    --radius: 12px;
    --radius-sm: 8px;
    --mono: 'IBM Plex Mono', 'Fira Mono', monospace;
    --sans: 'Syne', system-ui, sans-serif;

    font-family: var(--sans);
    position: fixed;
    inset: 0;
    z-index: 9999;
    background: rgba(4, 7, 14, 0.7);
    backdrop-filter: blur(10px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.2s;
}
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
.modal-enter-active .dvm-modal,
.modal-leave-active .dvm-modal {
    transition:
        transform 0.25s ease,
        opacity 0.2s;
}
.modal-enter-from .dvm-modal,
.modal-leave-to .dvm-modal {
    transform: scale(0.96) translateY(12px);
    opacity: 0;
}

.dvm-modal {
    background: var(--c1);
    border: 1px solid var(--border2);
    border-radius: 16px;
    width: 100%;
    max-width: 860px;
    max-height: 88vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 32px 80px rgba(0, 0, 0, 0.7);
    overflow: hidden;
}

.dvm-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 22px 16px;
    border-bottom: 1px solid var(--border);
    gap: 12px;
    flex-shrink: 0;
}
.dvm-modal-title-row {
    display: flex;
    align-items: center;
    gap: 12px;
}
.dvm-modal-icon {
    width: 34px;
    height: 34px;
    background: rgba(230, 57, 70, 0.12);
    border: 1px solid rgba(230, 57, 70, 0.2);
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--accent);
    flex-shrink: 0;
}
.dvm-modal-title {
    font-size: 15px;
    font-weight: 700;
    color: var(--text);
    margin: 0 0 3px;
}
.dvm-modal-subtitle {
    font-size: 11px;
    color: var(--text3);
    margin: 0;
    font-family: var(--mono);
}

.dvm-modal-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

.dvm-back-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: var(--c3);
    border: 1px solid var(--border2);
    border-radius: var(--radius-sm);
    color: var(--text2);
    font-size: 11px;
    font-family: var(--sans);
    cursor: pointer;
    transition: all 0.15s;
}
.dvm-back-btn:hover {
    color: var(--text);
    border-color: rgba(255, 255, 255, 0.2);
}

.dvm-modal-close {
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--c3);
    border: 1px solid var(--border);
    border-radius: 7px;
    color: var(--text2);
    cursor: pointer;
    transition: all 0.15s;
}
.dvm-modal-close:hover {
    background: rgba(230, 57, 70, 0.1);
    color: var(--accent);
    border-color: rgba(230, 57, 70, 0.25);
}

.dvm-modal-search-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 22px;
    border-bottom: 1px solid var(--border);
    color: var(--text3);
    flex-shrink: 0;
    background: var(--c2);
}
.dvm-modal-search {
    flex: 1;
    background: none;
    border: none;
    outline: none;
    color: var(--text);
    font-size: 12px;
    font-family: var(--sans);
}
.dvm-modal-search::placeholder {
    color: var(--text3);
}

.dvm-modal-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 14px;
    padding: 60px;
    color: var(--text3);
    font-size: 12px;
    font-family: var(--mono);
}
.dvm-spinner {
    width: 22px;
    height: 22px;
    border: 2px solid var(--border2);
    border-top-color: var(--accent);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

.dvm-modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 18px 22px 28px;
}

.dvm-modal-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    padding: 60px;
    color: var(--text3);
    font-size: 12px;
    font-family: var(--mono);
}
.dvm-modal-empty-hint {
    font-size: 11px;
    color: var(--text3);
}

/* ── HOUSEHOLD GRID ──────────────────────────────────────── */
.dvm-household-grid {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.dvm-household-card {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 13px 15px;
    background: var(--c2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    width: 100%;
    text-align: left;
    transition: all 0.15s;
}
.dvm-household-card:hover {
    border-color: rgba(230, 57, 70, 0.3);
    background: var(--c3);
    transform: translateX(2px);
}

.dvm-hh-left {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
    min-width: 0;
}
.dvm-hh-avatar {
    width: 40px;
    height: 40px;
    border-radius: 9px;
    background: rgba(230, 57, 70, 0.12);
    border: 1px solid rgba(230, 57, 70, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    font-weight: 700;
    color: var(--accent2);
    flex-shrink: 0;
    font-family: var(--mono);
}
.dvm-hh-info {
    flex: 1;
    min-width: 0;
}
.dvm-hh-name {
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 4px;
}
.dvm-hh-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}
.dvm-hh-meta-item {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 10px;
    color: var(--text3);
    font-family: var(--mono);
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.dvm-hh-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}
.dvm-hh-stats {
    text-align: center;
}
.dvm-hh-count {
    font-size: 18px;
    font-weight: 700;
    color: var(--text);
    font-family: var(--mono);
    line-height: 1;
}
.dvm-hh-count-lbl {
    font-size: 9px;
    color: var(--text3);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-family: var(--mono);
}
.dvm-hh-arrow {
    opacity: 0.2;
    transition: opacity 0.15s;
}
.dvm-household-card:hover .dvm-hh-arrow {
    opacity: 0.5;
}

/* ── PIN TAGS ─────────────────────────────────────────────── */
.dvm-pin-tag {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.03em;
    font-family: var(--mono);
    padding: 3px 8px;
    border-radius: 999px;
    border: 1px solid transparent;
}
.dvm-pin-tag.duress {
    background: rgba(230, 57, 70, 0.12);
    color: var(--accent2);
    border-color: rgba(230, 57, 70, 0.25);
}
.dvm-pin-tag.safe {
    background: rgba(6, 214, 160, 0.1);
    color: var(--green);
    border-color: rgba(6, 214, 160, 0.25);
}
.dvm-pin-tag.sm {
    font-size: 9px;
    padding: 2px 6px;
}

/* ── DETAIL HEADER ───────────────────────────────────────── */
.dvm-detail-header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    background: var(--c2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    margin-bottom: 14px;
}
.dvm-detail-avatar {
    width: 46px;
    height: 46px;
    border-radius: 10px;
    background: rgba(230, 57, 70, 0.12);
    border: 1px solid rgba(230, 57, 70, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    font-weight: 700;
    color: var(--accent2);
    flex-shrink: 0;
    font-family: var(--mono);
}
.dvm-detail-name {
    font-size: 14px;
    font-weight: 700;
    color: var(--text);
    margin: 0 0 6px;
}
.dvm-detail-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.dvm-detail-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 10px;
    color: var(--text3);
    font-family: var(--mono);
    background: var(--c3);
    border: 1px solid var(--border);
    border-radius: 999px;
    padding: 3px 10px;
}

/* ── FILTER TOOLBAR ──────────────────────────────────────── */
.dvm-filter-toolbar {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 14px;
}

.dvm-filter-search-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
    min-width: 160px;
    padding: 7px 10px;
    background: var(--c2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text3);
}
.dvm-filter-search {
    flex: 1;
    background: none;
    border: none;
    outline: none;
    color: var(--text);
    font-size: 12px;
    font-family: var(--sans);
}
.dvm-filter-search::placeholder {
    color: var(--text3);
}

.dvm-filter-select {
    padding: 7px 10px;
    background: var(--c2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text2);
    font-size: 11px;
    font-family: var(--sans);
    cursor: pointer;
    outline: none;
    transition: border-color 0.15s;
}
.dvm-filter-select:hover {
    border-color: var(--border2);
}

.dvm-filter-toggle-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 7px 12px;
    background: var(--c2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text2);
    font-size: 11px;
    font-family: var(--sans);
    cursor: pointer;
    transition: all 0.15s;
}
.dvm-filter-toggle-btn:hover,
.dvm-filter-toggle-btn.active {
    border-color: var(--accent);
    color: var(--accent);
    background: rgba(230, 57, 70, 0.08);
}
.dvm-filter-count {
    background: var(--accent);
    color: #fff;
    border-radius: 999px;
    padding: 1px 6px;
    font-size: 9px;
    font-weight: 700;
    font-family: var(--mono);
}

.dvm-filter-reset-btn {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 7px 12px;
    background: none;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text3);
    font-size: 11px;
    font-family: var(--sans);
    cursor: pointer;
    transition: all 0.15s;
}
.dvm-filter-reset-btn:hover {
    color: var(--accent);
    border-color: rgba(230, 57, 70, 0.25);
}

/* ── FILTER PANEL ────────────────────────────────────────── */
.filters-enter-active,
.filters-leave-active {
    transition:
        max-height 0.25s ease,
        opacity 0.2s;
}
.filters-enter-from,
.filters-leave-to {
    max-height: 0;
    opacity: 0;
}
.filters-enter-to,
.filters-leave-from {
    max-height: 600px;
    opacity: 1;
}

.dvm-filter-panel {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 12px;
    padding: 14px;
    background: var(--c2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    margin-bottom: 14px;
    overflow: hidden;
}
.dvm-filter-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.dvm-filter-label {
    font-size: 10px;
    color: var(--text3);
    font-family: var(--mono);
    letter-spacing: 0.06em;
    text-transform: uppercase;
}
.dvm-filter-row {
    display: flex;
    align-items: center;
    gap: 8px;
}
.dvm-filter-field {
    display: flex;
    flex-direction: column;
    gap: 3px;
    flex: 1;
}
.dvm-field-label {
    font-size: 9px;
    color: var(--text3);
    font-family: var(--mono);
}
.dvm-filter-arrow {
    color: var(--text3);
    font-size: 12px;
}
.dvm-date-input {
    padding: 5px 8px;
    background: var(--c3);
    border: 1px solid var(--border2);
    border-radius: 6px;
    color: var(--text);
    font-size: 11px;
    font-family: var(--mono);
    outline: none;
    width: 100%;
}
.dvm-filter-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
}
.dvm-pill-btn {
    padding: 4px 10px;
    background: var(--c3);
    border: 1px solid var(--border);
    border-radius: 999px;
    color: var(--text2);
    font-size: 10px;
    font-family: var(--mono);
    cursor: pointer;
    transition: all 0.12s;
}
.dvm-pill-btn:hover {
    border-color: var(--border2);
    color: var(--text);
}
.dvm-pill-btn.active {
    background: var(--accent);
    color: #fff;
    border-color: var(--accent);
}
.dvm-pill-btn.active.safe {
    background: var(--green);
    border-color: var(--green);
    color: var(--c0);
}

/* ── RECORDING LIST ──────────────────────────────────────── */
.dvm-no-results {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    padding: 40px;
    color: var(--text3);
    font-size: 12px;
    font-family: var(--mono);
    text-align: center;
}
.dvm-rec-list {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.dvm-rec-card {
    background: var(--c2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    overflow: hidden;
    transition: border-color 0.15s;
}
.dvm-rec-card.playing {
    border-color: rgba(230, 57, 70, 0.35);
}

.dvm-rec-main {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 11px 13px;
    gap: 12px;
}
.dvm-rec-info {
    flex: 1;
    min-width: 0;
}
.dvm-rec-header-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 4px;
}
.dvm-rec-num {
    font-size: 12px;
    font-weight: 700;
    color: var(--text);
    font-family: var(--mono);
    letter-spacing: 0.03em;
}
.dvm-rec-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    font-size: 10px;
    color: var(--text3);
    font-family: var(--mono);
}
.dvm-rec-meta span {
    display: flex;
    align-items: center;
    gap: 4px;
}
.dvm-time-ago {
    color: var(--accent2);
}

.dvm-rec-controls {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}

.dvm-play-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: var(--c3);
    border: 1px solid var(--border2);
    border-radius: var(--radius-sm);
    color: var(--text2);
    font-size: 11px;
    font-family: var(--sans);
    cursor: pointer;
    transition: all 0.12s;
}
.dvm-play-btn:hover,
.dvm-play-btn.active {
    background: var(--accent);
    color: #fff;
    border-color: var(--accent);
}

.dvm-dl-btn-sm {
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--c3);
    border: 1px solid var(--border2);
    border-radius: var(--radius-sm);
    color: var(--text2);
    text-decoration: none;
    transition: all 0.12s;
}
.dvm-dl-btn-sm:hover {
    background: rgba(6, 214, 160, 0.1);
    color: var(--green);
    border-color: rgba(6, 214, 160, 0.25);
}

.dvm-live-badge {
    font-size: 10px;
    font-weight: 700;
    color: var(--accent);
    font-family: var(--mono);
    letter-spacing: 0.04em;
}
.dvm-live-badge.pulse {
    animation: blink 1.2s infinite;
}
.dvm-recording-live {
    flex-shrink: 0;
}

.dvm-inline-player {
    padding: 10px 13px;
    border-top: 1px solid var(--border);
    background: var(--c1);
}
.player-enter-active,
.player-leave-active {
    transition:
        max-height 0.25s ease,
        opacity 0.2s;
}
.player-enter-from,
.player-leave-to {
    max-height: 0;
    opacity: 0;
}
.player-enter-to,
.player-leave-from {
    max-height: 80px;
    opacity: 1;
}
</style>

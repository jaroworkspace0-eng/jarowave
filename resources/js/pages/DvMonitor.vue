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

        // ── fetch caller identity shortly after stream starts ──
        setTimeout(async () => {
            try {
                const { data } = await axios.get(
                    `${import.meta.env.VITE_APP_URL}/api/dv-recordings/${alertId}`,
                    { headers: { Authorization: `Bearer ${token.value}` } },
                );
                const s = screens.value.find((s) => s.alertId === alertId);
                if (s && s.isStreaming) s.recordingMeta = data;
            } catch {
                /* record may not exist yet — silently ignore */
            }
        }, 1500);
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
            <!-- ══ PAGE HEADER ═══════════════════════════════════════════ -->
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Command Centre</div>
                    <h1 class="page-header__title">DV Monitor</h1>
                    <div v-if="screens.length > 0" class="dvm-alert-counter">
                        <span class="dvm-alert-counter-dot"></span>
                        {{ screens.filter((s) => s.isStreaming).length }} active
                        alert{{
                            screens.filter((s) => s.isStreaming).length !== 1
                                ? 's'
                                : ''
                        }}
                    </div>
                </div>

                <div class="page-header__right">
                    <!-- Channel selector -->
                    <div
                        class="dvm-channel-wrap"
                        v-click-outside="() => (showChannelDropdown = false)"
                    >
                        <button
                            class="btn-secondary"
                            @click="showChannelDropdown = !showChannelDropdown"
                        >
                            <span class="dvm-channel-dot"></span>
                            Channel: <strong>{{ selectedChannelName }}</strong>
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
                                        class="search-input"
                                        placeholder="Search channels…"
                                        autofocus
                                    />
                                    <span
                                        v-if="channelSearch"
                                        class="search-clear"
                                        @click="channelSearch = ''"
                                        >×</span
                                    >
                                </div>
                                <div class="dvm-channel-list">
                                    <div
                                        v-if="filteredChannels.length === 0"
                                        class="search-list__empty"
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
                    <button class="btn-secondary" @click="openModal">
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
                        {{ socketConnected ? 'Connected' : 'Offline' }}
                    </div>
                </div>
            </div>

            <!-- ══ STAT CARDS ════════════════════════════════════════════ -->
            <div v-if="screens.length > 0" class="stat-row">
                <div class="stat-card">
                    <div class="stat-card__label">Total Alerts</div>
                    <div class="stat-card__value">{{ screens.length }}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Live Streams</div>
                    <div class="stat-card__value stat-card__value--red">
                        {{ screens.filter((s) => s.isStreaming).length }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Ended</div>
                    <div class="stat-card__value stat-card__value--blue">
                        {{ screens.filter((s) => s.hasEnded).length }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Duress PINs</div>
                    <div class="stat-card__value stat-card__value--amber">
                        {{
                            screens.filter(
                                (s) =>
                                    s.recordingMeta?.cancel_pin_used ===
                                    'duress',
                            ).length
                        }}
                    </div>
                </div>
            </div>

            <!-- ══ CANVAS ════════════════════════════════════════════════ -->
            <div class="table-card dvm-canvas-card">
                <!-- Empty state -->
                <div v-if="screens.length === 0" class="empty-state">
                    <div class="empty-state__icon">
                        <svg
                            width="30"
                            height="30"
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
                    <p class="empty-state__title">
                        All clear — no active alerts
                    </p>
                    <p class="empty-state__sub">
                        Monitoring <strong>{{ selectedChannelName }}</strong
                        >. Streams appear automatically when a DV alert is
                        triggered.
                    </p>
                    <p class="dvm-empty-hint">
                        View past recordings via the Recordings button above
                    </p>
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
            </div>

            <!-- ══ RECORDINGS MODAL ══════════════════════════════════════ -->
            <Teleport to="body">
                <Transition name="modal">
                    <div
                        v-if="showRecordingsModal"
                        class="modal-backdrop"
                        @click.self="closeModal"
                    >
                        <div class="dvm-modal">
                            <!-- Modal header -->
                            <div class="modal-sheet__header">
                                <div class="modal-sheet__header-left">
                                    <div>
                                        <div class="modal-sheet__title">
                                            {{
                                                selectedHousehold
                                                    ? selectedHousehold.householdName
                                                    : 'DV Recordings'
                                            }}
                                        </div>
                                        <div class="modal-sheet__sub">
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
                                        </div>
                                    </div>
                                </div>
                                <div class="dvm-modal-actions">
                                    <button
                                        v-if="selectedHousehold"
                                        class="ca-back-btn"
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
                                        class="close-btn"
                                        @click="closeModal"
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
                            </div>

                            <!-- Search -->
                            <div v-if="!selectedHousehold" class="ca-filters">
                                <div class="search-input-row" style="flex: 1">
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
                                        v-model="modalSearch"
                                        class="search-input"
                                        placeholder="Search by name, address, alert ID…"
                                    />
                                    <span
                                        v-if="modalSearch"
                                        class="search-clear"
                                        @click="modalSearch = ''"
                                        >×</span
                                    >
                                </div>
                            </div>

                            <!-- Loading -->
                            <div v-if="loadingHistory" class="empty-state">
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
                                    >Loading recordings…</span
                                >
                            </div>

                            <!-- ── HOUSEHOLD LIST ── -->
                            <div
                                v-else-if="!selectedHousehold"
                                class="ca-modal__body"
                            >
                                <div
                                    v-if="groupedRecordings.length === 0"
                                    class="empty-state"
                                >
                                    <p class="empty-state__title">
                                        No recordings found
                                    </p>
                                    <p class="empty-state__sub">
                                        Try adjusting your search or select a
                                        different channel
                                    </p>
                                </div>
                                <div v-else class="ca-client-list">
                                    <div
                                        v-for="group in groupedRecordings"
                                        :key="group.householdId"
                                        class="ca-client-row"
                                        @click="openHousehold(group)"
                                    >
                                        <div class="ca-client-row__avatar">
                                            {{
                                                (group.householdName || 'U')
                                                    .charAt(0)
                                                    .toUpperCase()
                                            }}
                                        </div>
                                        <div class="ca-client-row__info">
                                            <div class="ca-client-row__name">
                                                {{ group.householdName }}
                                            </div>
                                            <div class="ca-client-row__email">
                                                <template
                                                    v-if="
                                                        group.address ||
                                                        group.gps
                                                    "
                                                    >{{
                                                        group.address ??
                                                        group.gps
                                                    }}</template
                                                >
                                            </div>
                                        </div>
                                        <div
                                            v-if="
                                                group.latestRec
                                                    .cancel_pin_used &&
                                                group.latestRec
                                                    .cancel_pin_used !== 'none'
                                            "
                                            class="dvm-pin-tag"
                                            :class="
                                                group.latestRec.cancel_pin_used
                                            "
                                        >
                                            {{
                                                group.latestRec
                                                    .cancel_pin_used ===
                                                'duress'
                                                    ? '⚠ Duress'
                                                    : '✓ Safe'
                                            }}
                                        </div>
                                        <div class="ca-client-row__meta">
                                            <span class="ca-client-row__count"
                                                >{{ group.totalCount }} alert{{
                                                    group.totalCount !== 1
                                                        ? 's'
                                                        : ''
                                                }}</span
                                            >
                                            <span class="ca-client-row__last">{{
                                                timeAgo(group.latestAt)
                                            }}</span>
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
                            </div>

                            <!-- ── HOUSEHOLD DETAIL ── -->
                            <div v-else class="ca-modal__body dvm-detail-body">
                                <div class="dvm-detail-header">
                                    <div class="ca-client-row__avatar">
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
                                    <div
                                        class="search-input-row"
                                        style="flex: 1; min-width: 160px"
                                    >
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
                                            v-model="detailSearch"
                                            class="search-input"
                                            placeholder="Search alert ID, GPS…"
                                        />
                                        <span
                                            v-if="detailSearch"
                                            class="search-clear"
                                            @click="detailSearch = ''"
                                            >×</span
                                        >
                                    </div>
                                    <div
                                        class="select-wrapper"
                                        style="width: 160px"
                                    >
                                        <select
                                            v-model="detailSortBy"
                                            class="field__select"
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
                                    <button
                                        class="chip"
                                        :class="{
                                            'chip--active':
                                                showDetailFilters ||
                                                activeDetailFilterCount > 0,
                                        }"
                                        @click="
                                            showDetailFilters =
                                                !showDetailFilters
                                        "
                                    >
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
                                <transition name="slide-down">
                                    <div
                                        v-if="showDetailFilters"
                                        class="dvm-filter-panel"
                                    >
                                        <div class="dvm-filter-group">
                                            <label class="field__label"
                                                >Date Range</label
                                            >
                                            <div class="dvm-filter-row">
                                                <input
                                                    type="date"
                                                    v-model="detailFilterFrom"
                                                    class="field__input"
                                                />
                                                <span class="dvm-filter-arrow"
                                                    >→</span
                                                >
                                                <input
                                                    type="date"
                                                    v-model="detailFilterTo"
                                                    class="field__input"
                                                />
                                            </div>
                                        </div>
                                        <div class="dvm-filter-group">
                                            <label class="field__label"
                                                >Time of Day</label
                                            >
                                            <div class="dvm-filter-row">
                                                <input
                                                    type="time"
                                                    v-model="
                                                        detailFilterTimeFrom
                                                    "
                                                    class="field__input"
                                                />
                                                <span class="dvm-filter-arrow"
                                                    >→</span
                                                >
                                                <input
                                                    type="time"
                                                    v-model="detailFilterTimeTo"
                                                    class="field__input"
                                                />
                                            </div>
                                        </div>
                                        <div class="dvm-filter-group">
                                            <label class="field__label"
                                                >Duration (seconds)</label
                                            >
                                            <div class="dvm-filter-row">
                                                <input
                                                    type="number"
                                                    v-model="detailFilterMinDur"
                                                    min="0"
                                                    placeholder="0"
                                                    class="field__input"
                                                />
                                                <span class="dvm-filter-arrow"
                                                    >→</span
                                                >
                                                <input
                                                    type="number"
                                                    v-model="detailFilterMaxDur"
                                                    min="0"
                                                    placeholder="∞"
                                                    class="field__input"
                                                />
                                            </div>
                                        </div>
                                        <div class="dvm-filter-group">
                                            <label class="field__label"
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
                                                    class="subtype-btn"
                                                    :class="[
                                                        {
                                                            'subtype-btn--active':
                                                                detailFilterPin ===
                                                                opt.v,
                                                        },
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
                                            <label class="field__label"
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
                                                    class="subtype-btn"
                                                    :class="{
                                                        'subtype-btn--active':
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
                                </transition>

                                <!-- Recording list -->
                                <div
                                    v-if="
                                        filteredHouseholdRecordings.length === 0
                                    "
                                    class="empty-state"
                                >
                                    <p class="empty-state__title">
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
                                                    <span>{{
                                                        formatTimestamp(
                                                            rec.started_at,
                                                        )
                                                    }}</span>
                                                    <span>{{
                                                        formatDuration(
                                                            rec.duration_secs,
                                                        )
                                                    }}</span>
                                                    <span v-if="rec.gps">{{
                                                        rec.gps
                                                    }}</span>
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
                                        <transition name="slide-down">
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
                                        </transition>
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
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap');

.dvm-root,
.modal-backdrop {
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
    --c-green: #16a34a;
    --c-blue: #1d4ed8;
    --c-amber: #b45309;
    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 16px;
    --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
    --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.08);
    --shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.14);
    font-family: 'DM Sans', system-ui, sans-serif;
}

.dvm-root {
    padding: 28px 32px;
    display: flex;
    flex-direction: column;
    gap: 20px;
    min-height: 100%;
    background: var(--c-bg);
}

/* PAGE HEADER */
.page-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}
.page-header__left {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
.page-header__eyebrow {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: var(--c-primary);
    margin-bottom: 4px;
    width: 100%;
}
.page-header__title {
    font-size: 22px;
    font-weight: 700;
    color: var(--c-text);
    margin: 0;
    letter-spacing: -0.3px;
}
.page-header__right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.dvm-alert-counter {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    background: #fef2f2;
    border: 1px solid #fca5a5;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    color: var(--c-danger);
}
.dvm-alert-counter-dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: var(--c-danger);
    animation: blink 1s infinite;
}

/* BUTTONS (shared with Announcements design) */
.btn-secondary {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #ffffff;
    color: var(--c-text);
    border: 1.5px solid var(--c-border);
    border-radius: 12px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.18s;
    white-space: nowrap;
    font-family: inherit;
}
.btn-secondary:hover {
    border-color: var(--c-primary);
    color: var(--c-primary);
    background: #fff7ed;
}

/* Channel selector dropdown */
.dvm-channel-wrap {
    position: relative;
}
.dvm-channel-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--c-blue);
    display: inline-block;
    flex-shrink: 0;
}
.dvm-channel-dropdown {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    min-width: 260px;
    background: #ffffff;
    border: 1px solid var(--c-border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-lg);
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
.dvm-channel-list {
    max-height: 220px;
    overflow-y: auto;
    padding: 6px;
}
.dvm-channel-item {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 9px 10px;
    background: none;
    border: none;
    border-radius: 8px;
    color: var(--c-muted);
    font-size: 13px;
    font-family: inherit;
    font-weight: 600;
    cursor: pointer;
    text-align: left;
    transition: all 0.12s;
}
.dvm-channel-item:hover {
    background: #f8fafc;
    color: var(--c-text);
}
.dvm-channel-item.active {
    color: var(--c-primary);
    background: #fff7ed;
}
.dvm-channel-item-dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: var(--c-border);
    flex-shrink: 0;
}
.dvm-channel-item-dot.active {
    background: var(--c-primary);
}

.dvm-rec-count {
    background: #fff7ed;
    color: var(--c-primary);
    border-radius: 999px;
    padding: 1px 7px;
    font-size: 10px;
    font-weight: 700;
}

/* Status pill */
.dvm-status-pill {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 13px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 700;
    border: 1.5px solid transparent;
}
.dvm-status-pill.connected {
    background: #f0fdf4;
    border-color: #86efac;
    color: var(--c-green);
}
.dvm-status-pill.offline {
    background: #fef2f2;
    border-color: #fca5a5;
    color: var(--c-danger);
}
.dvm-status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
}
.dvm-status-pill.connected .dvm-status-dot {
    animation: blink 2s infinite;
}

/* STAT ROW (reused from Announcements) */
.stat-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
}
.stat-card {
    background: #ffffff;
    border: 1px solid var(--c-border);
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
    color: var(--c-faint);
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.stat-card__value {
    font-size: 30px;
    font-weight: 800;
    color: var(--c-text);
    line-height: 1;
    letter-spacing: -1px;
}
.stat-card__value--red {
    color: var(--c-danger);
}
.stat-card__value--blue {
    color: var(--c-blue);
}
.stat-card__value--amber {
    color: var(--c-amber);
}

/* CANVAS / TABLE CARD */
.table-card {
    background: #ffffff;
    border: 1px solid var(--c-border);
    border-radius: 16px;
    box-shadow: var(--shadow-sm);
}
.dvm-canvas-card {
    padding: 20px;
}
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 64px 24px;
    gap: 8px;
    text-align: center;
}
.empty-state__icon {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    background: #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--c-faint);
    margin-bottom: 6px;
}
.empty-state__title {
    font-size: 15px;
    font-weight: 700;
    color: var(--c-text);
}
.empty-state__sub {
    font-size: 13px;
    color: var(--c-muted);
    max-width: 360px;
}
.empty-state__sub strong {
    color: var(--c-text);
}
.dvm-empty-hint {
    margin-top: 6px;
    font-size: 12px;
    color: var(--c-faint);
    background: #f8fafc;
    border: 1px solid var(--c-border);
    border-radius: 999px;
    padding: 5px 14px;
}

/* STREAM GRID */
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

/* SCREEN CARD */
.dvm-screen {
    background: #ffffff;
    border: 1.5px solid var(--c-border);
    border-radius: var(--radius-md);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition:
        border-color 0.25s,
        box-shadow 0.25s;
    box-shadow: var(--shadow-sm);
}
.dvm-screen.streaming {
    border-color: #fca5a5;
}
.dvm-screen.ended {
    border-color: #bfdbfe;
}

/* CALLER IDENTITY BLOCK */
.dvm-caller-block {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: #fef2f2;
    border-bottom: 1px solid #fecaca;
    position: relative;
}
.dvm-caller-block::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background: var(--c-danger);
}
.dvm-caller-block.ended {
    background: #eff6ff;
    border-bottom-color: #bfdbfe;
}
.dvm-caller-block.ended::before {
    background: var(--c-blue);
}

.dvm-caller-avatar {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    background: #fee2e2;
    border: 1px solid #fca5a5;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    font-weight: 800;
    color: var(--c-danger-h);
    flex-shrink: 0;
}
.dvm-caller-avatar.ended {
    background: #dbeafe;
    border-color: #bfdbfe;
    color: var(--c-blue);
}

.dvm-caller-info {
    flex: 1;
    min-width: 0;
}
.dvm-caller-name {
    font-size: 14px;
    font-weight: 700;
    color: var(--c-text);
    letter-spacing: -0.01em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 4px;
}
.dvm-caller-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.dvm-caller-meta-item {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    color: var(--c-muted);
    font-weight: 500;
}

.dvm-pin-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.03em;
}
.dvm-pin-chip.duress {
    background: #fef2f2;
    color: var(--c-danger);
    border: 1px solid #fca5a5;
}
.dvm-pin-chip.safe {
    background: #f0fdf4;
    color: var(--c-green);
    border: 1px solid #86efac;
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
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}
.dvm-caller-badge.live {
    background: #fef2f2;
    border: 1px solid #fca5a5;
    color: var(--c-danger);
}
.dvm-caller-badge.ended {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: var(--c-blue);
}
.dvm-live-blink {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: var(--c-danger);
    animation: blink 1s infinite;
}
.dvm-alert-id {
    font-size: 11px;
    color: var(--c-faint);
    font-weight: 600;
}

/* SCREEN BODY */
.dvm-screen-body {
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

/* WAVEFORM */
.dvm-wave-wrap {
    background: #f8fafc;
    border: 1px solid var(--c-border);
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
    border-color: #fca5a5;
}
.dvm-bar {
    flex: 1;
    min-width: 2px;
    max-width: 5px;
    border-radius: 2px;
    background: #cbd5e1;
    transition: height 0.1s ease;
    min-height: 3px;
    align-self: center;
}
.dvm-wave-wrap.live-wave .dvm-bar {
    background: var(--c-danger);
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
    background: rgba(255, 251, 235, 0.9);
    color: var(--c-amber);
    font-size: 11px;
    font-weight: 700;
    backdrop-filter: blur(2px);
}

/* CONTROLS */
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
    color: var(--c-muted);
    font-weight: 700;
    min-width: 36px;
    font-variant-numeric: tabular-nums;
}
.dvm-live-footer {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 11px;
    color: var(--c-faint);
}
.dvm-live-pulse {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--c-danger);
    animation: blink 1.4s infinite;
    flex-shrink: 0;
}
.dvm-live-pulse.muted {
    background: var(--c-amber);
    animation: none;
}
.dvm-ctrl-btn {
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f8fafc;
    border: 1.5px solid var(--c-border);
    border-radius: 8px;
    color: var(--c-muted);
    cursor: pointer;
    transition: all 0.12s;
}
.dvm-ctrl-btn:hover {
    background: #f1f5f9;
    color: var(--c-text);
    border-color: #cbd5e1;
}
.dvm-ctrl-btn.muted {
    background: #fffbeb;
    color: var(--c-amber);
    border-color: #fcd34d;
}
.dvm-ctrl-btn.dismiss:hover {
    background: #fef2f2;
    color: var(--c-danger);
    border-color: #fca5a5;
}

/* ENDED SECTION */
.dvm-ended-section {
    display: flex;
    flex-direction: column;
    gap: 9px;
}
.dvm-ended-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 700;
    color: var(--c-green);
}
.dvm-ended-dur {
    margin-left: 4px;
    color: var(--c-faint);
    font-weight: 500;
}
.dvm-audio-wrap {
    background: #f8fafc;
    border: 1px solid var(--c-border);
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
    padding: 8px 14px;
    background: #f8fafc;
    border: 1.5px solid var(--c-border);
    border-radius: var(--radius-sm);
    color: var(--c-muted);
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.12s;
    width: fit-content;
}
.dvm-dl-btn:hover {
    background: var(--c-blue);
    color: #fff;
    border-color: var(--c-blue);
}

/* ══ MODAL (matches Announcements ca-modal) ══ */
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
.dvm-modal {
    background: #ffffff;
    border-radius: 20px;
    width: 100%;
    max-width: 820px;
    max-height: 88vh;
    display: flex;
    flex-direction: column;
    box-shadow: var(--shadow-lg);
    border: 1px solid var(--c-border);
    overflow: hidden;
}
.modal-sheet__header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 20px 24px;
    border-bottom: 1px solid var(--c-border);
    justify-content: space-between;
    flex-shrink: 0;
}
.modal-sheet__header-left {
    display: flex;
    align-items: center;
    gap: 14px;
}
.modal-sheet__title {
    font-size: 15px;
    font-weight: 700;
    color: var(--c-text);
}
.modal-sheet__sub {
    font-size: 12px;
    color: var(--c-faint);
    margin-top: 1px;
}
.dvm-modal-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}
.ca-back-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 10px;
    border-radius: 8px;
    border: 1.5px solid var(--c-border);
    background: #f8fafc;
    font-size: 12px;
    font-weight: 600;
    color: var(--c-muted);
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
}
.ca-back-btn:hover {
    border-color: var(--c-primary);
    color: var(--c-primary);
    background: #fff7ed;
}
.close-btn {
    flex-shrink: 0;
    width: 34px;
    height: 34px;
    background: #f1f5f9;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    color: var(--c-muted);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s;
}
.close-btn:hover {
    background: #e2e8f0;
}

.ca-filters {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 24px;
    border-bottom: 1px solid var(--c-border);
    background: #f8fafc;
    flex-shrink: 0;
}
.ca-modal__body {
    flex: 1;
    overflow-y: auto;
    padding: 0;
}

/* search input row reused across the page */
.search-input-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border: 1.5px solid var(--c-border);
    border-radius: 10px;
    background: #ffffff;
    transition: border-color 0.15s;
}
.search-input-row:focus-within {
    border-color: var(--c-primary);
}
.search-icon {
    width: 15px;
    height: 15px;
    color: var(--c-faint);
    flex-shrink: 0;
}
.search-input {
    flex: 1;
    border: none;
    background: transparent;
    font-size: 13px;
    font-family: inherit;
    color: var(--c-text);
    outline: none;
}
.search-input::placeholder {
    color: var(--c-faint);
}
.search-clear {
    font-size: 16px;
    color: var(--c-faint);
    cursor: pointer;
    line-height: 1;
    padding: 0 2px;
    transition: color 0.15s;
}
.search-clear:hover {
    color: var(--c-muted);
}
.search-list__empty {
    padding: 12px 16px;
    font-size: 12px;
    color: var(--c-faint);
    text-align: center;
}

/* Client / household rows (shared visual language) */
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
    color: var(--c-text);
}
.ca-client-row__email {
    font-size: 12px;
    color: var(--c-faint);
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
    color: var(--c-primary);
}
.ca-client-row__last {
    font-size: 11px;
    color: var(--c-faint);
}
.ca-client-row__chevron {
    color: #cbd5e1;
    flex-shrink: 0;
}

/* PIN tags */
.dvm-pin-tag {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.02em;
    padding: 3px 9px;
    border-radius: 999px;
    border: 1px solid transparent;
    white-space: nowrap;
}
.dvm-pin-tag.duress {
    background: #fef2f2;
    color: var(--c-danger);
    border-color: #fca5a5;
}
.dvm-pin-tag.safe {
    background: #f0fdf4;
    color: var(--c-green);
    border-color: #86efac;
}
.dvm-pin-tag.sm {
    font-size: 10px;
    padding: 2px 7px;
}

/* Household detail */
.dvm-detail-body {
    padding: 20px 24px 28px;
}
.dvm-detail-header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    background: #f8fafc;
    border: 1px solid var(--c-border);
    border-radius: var(--radius-sm);
    margin-bottom: 16px;
}
.dvm-detail-name {
    font-size: 14px;
    font-weight: 700;
    color: var(--c-text);
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
    font-size: 11px;
    color: var(--c-muted);
    background: #ffffff;
    border: 1px solid var(--c-border);
    border-radius: 999px;
    padding: 3px 10px;
}

/* Filter toolbar */
.dvm-filter-toolbar {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 14px;
}
.select-wrapper {
    position: relative;
}
.field__select {
    width: 100%;
    box-sizing: border-box;
    background: #f8fafc;
    border: 1.5px solid var(--c-border);
    border-radius: 8px;
    padding: 8px 32px 8px 12px;
    font-size: 12px;
    font-family: inherit;
    color: var(--c-text);
    outline: none;
    appearance: none;
    cursor: pointer;
    transition: border-color 0.15s;
}
.field__select:focus {
    border-color: var(--c-primary);
    background: #fff;
}
.select-caret {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    width: 14px;
    height: 14px;
    color: var(--c-faint);
    pointer-events: none;
}
.dvm-filter-count {
    background: var(--c-primary);
    color: #fff;
    border-radius: 999px;
    padding: 1px 6px;
    font-size: 10px;
    font-weight: 700;
    margin-left: 4px;
}
.dvm-filter-reset-btn {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    background: none;
    border: 1.5px solid var(--c-border);
    border-radius: 999px;
    color: var(--c-faint);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s;
}
.dvm-filter-reset-btn:hover {
    color: var(--c-danger);
    border-color: #fca5a5;
    background: #fef2f2;
}

/* chip (reused) */
.chip {
    padding: 5px 14px;
    border-radius: 20px;
    border: 1px solid var(--c-border);
    background: #ffffff;
    font-size: 12px;
    font-weight: 600;
    color: var(--c-muted);
    cursor: pointer;
    transition: all 0.15s;
    display: inline-flex;
    align-items: center;
}
.chip:hover {
    border-color: #cbd5e1;
    background: #f8fafc;
}
.chip--active {
    background: var(--c-primary);
    color: #fff;
    border-color: var(--c-primary);
}

/* Filter panel */
.dvm-filter-panel {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 14px;
    padding: 16px;
    background: #f8fafc;
    border: 1.5px solid var(--c-border);
    border-radius: var(--radius-sm);
    margin-bottom: 14px;
}
.dvm-filter-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.field__label {
    font-size: 12px;
    font-weight: 700;
    color: var(--c-muted);
    letter-spacing: 0.3px;
}
.dvm-filter-row {
    display: flex;
    align-items: center;
    gap: 8px;
}
.dvm-filter-arrow {
    color: var(--c-faint);
    font-size: 12px;
}
.field__input {
    width: 100%;
    box-sizing: border-box;
    background: #ffffff;
    border: 1.5px solid var(--c-border);
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 12px;
    font-family: inherit;
    color: var(--c-text);
    outline: none;
    transition: border-color 0.15s;
}
.field__input:focus {
    border-color: var(--c-primary);
}
.dvm-filter-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.subtype-btn {
    padding: 6px 13px;
    border-radius: 20px;
    border: 1.5px solid var(--c-border);
    background: #fff;
    font-size: 12px;
    font-weight: 600;
    color: var(--c-muted);
    cursor: pointer;
    font-family: inherit;
    transition: all 0.15s;
    white-space: nowrap;
}
.subtype-btn:hover {
    border-color: #cbd5e1;
    background: #f8fafc;
    color: var(--c-text);
}
.subtype-btn--active {
    background: var(--c-primary);
    border-color: var(--c-primary);
    color: #fff;
}

/* RECORDING LIST */
.dvm-rec-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.dvm-rec-card {
    background: #f8fafc;
    border: 1.5px solid var(--c-border);
    border-radius: var(--radius-sm);
    overflow: hidden;
    transition: border-color 0.15s;
}
.dvm-rec-card.playing {
    border-color: #fca5a5;
}
.dvm-rec-main {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 14px;
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
    font-size: 13px;
    font-weight: 700;
    color: var(--c-text);
}
.dvm-rec-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    font-size: 11px;
    color: var(--c-faint);
}
.dvm-time-ago {
    color: var(--c-primary);
    font-weight: 600;
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
    padding: 7px 13px;
    background: #ffffff;
    border: 1.5px solid var(--c-border);
    border-radius: var(--radius-sm);
    color: var(--c-muted);
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.12s;
}
.dvm-play-btn:hover,
.dvm-play-btn.active {
    background: var(--c-primary);
    color: #fff;
    border-color: var(--c-primary);
}
.dvm-dl-btn-sm {
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #ffffff;
    border: 1.5px solid var(--c-border);
    border-radius: var(--radius-sm);
    color: var(--c-muted);
    text-decoration: none;
    transition: all 0.12s;
}
.dvm-dl-btn-sm:hover {
    background: #f0fdf4;
    color: var(--c-green);
    border-color: #86efac;
}
.dvm-live-badge {
    font-size: 11px;
    font-weight: 700;
    color: var(--c-danger);
}
.dvm-live-badge.pulse {
    animation: blink 1.2s infinite;
}
.dvm-inline-player {
    padding: 10px 14px;
    border-top: 1px solid var(--c-border);
    background: #ffffff;
}

/* TRANSITIONS */
.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.22s ease;
}
.modal-enter-active .dvm-modal,
.modal-leave-active .dvm-modal {
    transition:
        transform 0.22s ease,
        opacity 0.22s ease;
}
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
.modal-enter-from .dvm-modal,
.modal-leave-to .dvm-modal {
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
.spin {
    animation: spin 0.65s linear infinite;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
@keyframes blink {
    0%,
    100% {
        opacity: 1;
    }
    50% {
        opacity: 0.3;
    }
}

/* RESPONSIVE */
@media (max-width: 768px) {
    .stat-row {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }
}
@media (max-width: 640px) {
    .dvm-root {
        padding: 16px;
    }
    .stat-card {
        padding: 14px;
    }
    .stat-card__value {
        font-size: 22px;
    }
}
</style>

<script setup lang="ts">
// ══════════════════════════════════════════════════════════════
// DvLiveAudio.vue
// Matches your dashboard's white-card / Tailwind aesthetic exactly.
//
// FILE LOCATION:
//   resources/js/components/DvLiveAudio.vue
//
// INSTALL (if not already present):
//   npm install socket.io-client
//
// ══════════════════════════════════════════════════════════════

import { useAuthStore } from '@/stores/auth';
import axios from 'axios';
import { io, type Socket } from 'socket.io-client';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const auth = useAuthStore();

const props = defineProps<{
    channelId: number | string | null;
}>();

// ── State ─────────────────────────────────────────────────────
const isStreaming = ref(false);
const hasEnded = ref(false);
const isMuted = ref(false);
const currentAlertId = ref<string | null>(null);
const streamUrl = ref<string | null>(null);
const socketError = ref<string | null>(null);
const socketConnected = ref(false);
const recordingMeta = ref<any>(null);
const elapsedSecs = ref(0);
const waveformBars = ref<number[]>(Array(24).fill(10));

// ── Refs ──────────────────────────────────────────────────────
const audioEl = ref<HTMLAudioElement | null>(null);
let socket: Socket | null = null;
let audioCtx: AudioContext | null = null;
let nextPlayAt = 0;
let elapsedTimer: ReturnType<typeof setInterval> | null = null;
let waveTimer: ReturnType<typeof setInterval> | null = null;

// ── Computed ──────────────────────────────────────────────────
const formattedElapsed = computed(() => {
    const m = Math.floor(elapsedSecs.value / 60);
    const s = elapsedSecs.value % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
});

const token = computed(() => auth.token ?? localStorage.getItem('token') ?? '');

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
        if (props.channelId) {
            socket!.emit('join-cpf-room', {
                channelId: props.channelId,
                token: token.value,
            });
        }
    });

    socket.on('disconnect', (reason) => {
        socketConnected.value = false;
        socketError.value = reason;
    });
    socket.on('connect_error', (err) => {
        socketConnected.value = false;
        socketError.value = err.message;
    });

    // ── Stream started ─────────────────────────────────────
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

    // ── Audio chunk ────────────────────────────────────────
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
            console.warn('[DvLiveAudio] decode error:', e);
        }
    });

    // ── Stream ended ───────────────────────────────────────
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
            waveformBars.value = Array(24).fill(10);
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
        },
    );
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

function reset() {
    isStreaming.value = hasEnded.value = false;
    currentAlertId.value = streamUrl.value = recordingMeta.value = null;
    elapsedSecs.value = 0;
    waveformBars.value = Array(24).fill(10);
}

watch(
    () => props.channelId,
    (id) => {
        if (socket?.connected && id)
            socket.emit('join-cpf-room', { channelId: id, token: token.value });
    },
);

onMounted(connectSocket);
onBeforeUnmount(() => {
    if (elapsedTimer) clearInterval(elapsedTimer);
    if (waveTimer) clearInterval(waveTimer);
    socket?.disconnect();
    audioCtx?.close();
});
</script>

<template>
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
        <!-- ── Header ──────────────────────────────────────── -->
        <div class="mb-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div
                    :class="[
                        'flex h-9 w-9 items-center justify-center rounded-full transition-colors',
                        isStreaming ? 'bg-purple-100' : 'bg-gray-50',
                    ]"
                >
                    <span
                        :class="['text-lg', isStreaming ? 'animate-pulse' : '']"
                        >🛡️</span
                    >
                </div>
                <div>
                    <h3 class="text-sm font-bold text-gray-700">
                        DV Alert Monitor
                    </h3>
                    <p class="text-xs text-gray-400">
                        Channel {{ channelId ?? '—' }}
                    </p>
                </div>
            </div>

            <!-- Status pill — same pattern as your metric cards -->
            <span
                :class="[
                    'rounded-full px-2.5 py-1 text-xs font-semibold',
                    isStreaming
                        ? 'animate-pulse bg-purple-50 text-purple-700'
                        : hasEnded
                          ? 'bg-indigo-50 text-indigo-600'
                          : socketError
                            ? 'bg-red-50 text-red-600'
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
                              : socketError
                                ? 'bg-red-400'
                                : 'bg-gray-400',
                    ]"
                ></span>
                {{
                    isStreaming
                        ? 'LIVE'
                        : hasEnded
                          ? 'Ended'
                          : socketError
                            ? 'Disconnected'
                            : 'Monitoring'
                }}
            </span>
        </div>

        <!-- ── Socket error ────────────────────────────────── -->
        <div
            v-if="socketError"
            class="mb-4 rounded-lg border border-red-100 bg-red-50 px-4 py-2 text-xs font-medium text-red-600"
        >
            ⚠ Socket: {{ socketError }}
        </div>

        <!-- ── Idle ────────────────────────────────────────── -->
        <div
            v-if="!isStreaming && !hasEnded"
            class="flex flex-col items-center justify-center py-8 text-center"
        >
            <span class="mb-2 text-3xl opacity-20">🛡️</span>
            <p class="text-sm font-medium text-gray-400">No active DV alert</p>
            <p class="mt-1 text-xs text-gray-300">
                Listening on channel {{ channelId ?? '—' }}
            </p>
        </div>

        <!-- ── Active / ended body ─────────────────────────── -->
        <div v-if="isStreaming || hasEnded">
            <!-- Alert ID row -->
            <div class="mb-3 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span
                        :class="[
                            'h-2 w-2 rounded-full',
                            isStreaming
                                ? 'animate-pulse bg-purple-500'
                                : 'bg-indigo-300',
                        ]"
                    ></span>
                    <span class="font-mono text-xs font-semibold text-gray-600">
                        Alert #{{ currentAlertId }}
                    </span>
                </div>
                <span class="font-mono text-xs text-gray-400">{{
                    formattedElapsed
                }}</span>
            </div>

            <!-- Waveform -->
            <div
                :class="[
                    'mb-4 flex items-end justify-center gap-0.5 rounded-lg px-2 py-3',
                    isStreaming ? 'bg-purple-50' : 'bg-gray-50',
                ]"
                style="height: 68px"
            >
                <div
                    v-for="(h, i) in waveformBars"
                    :key="i"
                    :class="[
                        'w-1.5 rounded-sm transition-all duration-100',
                        isStreaming ? 'bg-purple-400' : 'bg-gray-200',
                    ]"
                    :style="{ height: `${h}%` }"
                ></div>
            </div>

            <!-- Audio player (after stream ends) -->
            <audio
                v-if="hasEnded && streamUrl"
                ref="audioEl"
                :src="streamUrl"
                controls
                preload="metadata"
                class="mb-4 w-full rounded-lg"
            ></audio>

            <!-- Action buttons -->
            <div class="mb-4 flex flex-wrap gap-2">
                <button
                    v-if="isStreaming"
                    class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-600 shadow-sm transition hover:border-gray-300 hover:bg-gray-50 active:scale-95"
                    @click="toggleMute"
                >
                    {{ isMuted ? '🔇 Muted' : '🔊 Live Audio' }}
                </button>

                <a
                    v-if="hasEnded && streamUrl"
                    :href="streamUrl"
                    :download="`dv_alert_${currentAlertId}.wav`"
                    class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 shadow-sm transition hover:bg-indigo-100 active:scale-95"
                >
                    ⬇ Download Recording
                </a>

                <button
                    v-if="hasEnded"
                    class="rounded-lg border border-gray-100 bg-white px-3 py-1.5 text-xs font-medium text-gray-400 transition hover:text-gray-600 active:scale-95"
                    @click="reset"
                >
                    Clear
                </button>
            </div>

            <!-- Metadata — matches your recent activity list style -->
            <div
                v-if="hasEnded && recordingMeta"
                class="divide-y divide-gray-50 rounded-lg border border-gray-100 bg-gray-50"
            >
                <div
                    v-for="row in [
                        {
                            label: 'Duration',
                            value: recordingMeta.duration_secs
                                ? `${recordingMeta.duration_secs}s`
                                : '—',
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
                            value:
                                recordingMeta.channel_name ?? channelId ?? '—',
                        },
                    ]"
                    :key="row.label"
                    class="flex items-center justify-between px-4 py-2.5"
                >
                    <span class="text-xs text-gray-400">{{ row.label }}</span>
                    <span
                        class="font-mono text-xs font-semibold text-gray-700"
                        >{{ row.value }}</span
                    >
                </div>
            </div>
        </div>
    </div>
</template>

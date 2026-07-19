<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    alert: { type: Object, required: true },
});
const emit = defineEmits(['mute', 'call-log', 'resolve', 'seen']);

const expanded = ref(false);
const mapFullscreen = ref(false);

const isDV = computed(() => props.alert.type === 'domestic_violence');
const isPanicLike = computed(() => ['panic', 'sos'].includes(props.alert.type));

const now = ref(Date.now());
let clockInterval;
onMounted(() => {
    clockInterval = setInterval(() => {
        now.value = Date.now();
    }, 1000);
});
onUnmounted(() => {
    clearInterval(clockInterval);
});

const secondsSinceAck = computed(() => {
    if (props.alert.first_ack_at) return null;
    return Math.floor(
        (now.value - new Date(props.alert.created_at).getTime()) / 1000,
    );
});
const escalated = computed(
    () => secondsSinceAck.value !== null && secondsSinceAck.value > 90,
);

// Stays "new" (distinct highlight colors) only until the guard explicitly
// dismisses it or the page is reloaded. Escalation state is independent and
// must not clear the "new" styling on its own.
const isNew = computed(() => !!props.alert.justArrived);

const typeMeta = computed(
    () =>
        ({
            panic: { label: 'Panic', badge: 'ac-badge--panic' },
            sos: { label: 'SOS', badge: 'ac-badge--panic' },
            domestic_violence: { label: 'DV Alert', badge: 'ac-badge--dv' },
            guardian: { label: 'Guardian', badge: 'ac-badge--guardian' },
        })[props.alert.type] || {
            label: props.alert.type || 'Alert',
            badge: 'ac-badge--general',
        },
);

const formattedDateTime = computed(() => {
    const d = new Date(props.alert.created_at);
    return d.toLocaleString([], {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
});

const formattedAckTime = computed(() => {
    if (!props.alert.first_ack_at) return null;
    return new Date(props.alert.first_ack_at).toLocaleTimeString();
});

// Treat (0, 0) as "no location" regardless of whether it arrives as a
// number or a string — avoids the false "0.00000, 0.00000" display when
// a device sends default/placeholder coordinates before a real GPS fix.
const hasRealLocation = computed(() => {
    const lat = Number(props.alert.last_lat);
    const lng = Number(props.alert.last_lng);
    return !!(lat || lng);
});

const coordsLabel = computed(() => {
    if (!hasRealLocation.value) return null;
    const lat = Number(props.alert.last_lat);
    const lng = Number(props.alert.last_lng);
    return `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
});

const accuracyLabel = computed(() => {
    const raw = props.alert.accuracy;
    if (!raw || raw === 'awaiting_gps') return null;
    const acc = Number(raw);
    if (!acc || Number.isNaN(acc)) return null;
    return `±${Math.round(acc)}m`;
});

const mapsApiKey = import.meta.env.VITE_GOOGLE_MAPS_API_KEY || '';

const staticMapUrl = computed(() => {
    if (!hasRealLocation.value) return null;
    return `https://maps.googleapis.com/maps/api/staticmap?center=${props.alert.last_lat},${props.alert.last_lng}&zoom=15&size=400x150&markers=${props.alert.last_lat},${props.alert.last_lng}&key=${mapsApiKey}`;
});

const embedMapUrl = computed(() => {
    if (!hasRealLocation.value) return null;
    return `https://www.google.com/maps/embed/v1/view?center=${props.alert.last_lat},${props.alert.last_lng}&zoom=16&key=${mapsApiKey}`;
});

function guardianStatusLabel(g) {
    if (!g.responded_at) return 'no response yet';
    const type = (g.response_type || 'responded').replace(/_/g, ' ');
    return `${type} · ${new Date(g.responded_at).toLocaleTimeString()}`;
}

function logCall(outcome) {
    window.location.href = `tel:${props.alert.household_phone}`;
    emit('call-log', props.alert.id, outcome);
}

function onResolveChange(e) {
    const value = e.target.value;
    e.target.value = '';
    if (value) emit('resolve', props.alert.id, value);
}
</script>

<template>
    <div
        class="ac-card"
        :class="{ 'ac-card--escalated': escalated, 'ac-card--new': isNew }"
    >
        <button
            v-if="isNew"
            type="button"
            class="ac-new-ribbon"
            @click="$emit('seen', alert.id)"
        >
            🚨 New Alert — tap to dismiss
        </button>

        <!-- Header -->
        <div class="ac-card__header">
            <div>
                <p class="ac-card__household">{{ alert.household_name }}</p>
                <p v-if="alert.household_phone" class="ac-card__phone">
                    {{ alert.household_phone }}
                </p>
                <p class="ac-card__meta">{{ alert.channel_name }}</p>
                <p v-if="alert.home_address" class="ac-card__address">
                    {{ alert.home_address }}
                </p>
            </div>
            <div class="ac-card__header-right">
                <span class="ac-badge" :class="typeMeta.badge">{{
                    typeMeta.label
                }}</span>
                <p class="ac-card__time">
                    {{ formattedDateTime }}
                </p>
            </div>
        </div>

        <p v-if="escalated" class="ac-escalation-flag">
            No guard acknowledgement &gt; 90s
        </p>
        <p v-else-if="formattedAckTime" class="ac-ack-flag">
            ✓ Acknowledged {{ formattedAckTime }}
        </p>

        <!-- Map thumbnail -->
        <button class="ac-map-thumb" @click="mapFullscreen = true">
            <img v-if="staticMapUrl" :src="staticMapUrl" alt="alert location" />
            <span v-else class="ac-map-thumb__empty">No location yet</span>
            <span class="ac-map-thumb__expand">Expand</span>
        </button>
        <p v-if="coordsLabel" class="ac-coords">
            {{ coordsLabel }}
            <span v-if="accuracyLabel" class="ac-accuracy"
                >({{ accuracyLabel }})</span
            >
        </p>

        <!-- Guardian notification summary -->
        <p class="ac-guardian-line">
            Notified {{ alert.guardian_count ?? 0 }} paired guardian{{
                (alert.guardian_count ?? 0) === 1 ? '' : 's'
            }}
            <button
                v-if="alert.guardian_count"
                class="ac-link-btn"
                @click="expanded = !expanded"
            >
                {{ expanded ? 'hide' : 'view' }}
            </button>
        </p>

        <!-- Actions -->
        <div class="ac-actions">
            <button
                v-if="!isDV"
                class="ac-toggle-btn"
                :class="{ 'ac-toggle-btn--muted-tone': isPanicLike }"
                @click="logCall('attempted')"
            >
                {{ isPanicLike ? 'Verify by phone' : 'Call household' }}
            </button>
            <span v-else class="ac-dv-note"
                >Silent alert — guardians/guards notified only</span
            >

            <button
                class="ac-toggle-btn"
                :class="{ 'ac-toggle-btn--on': alert.muted }"
                :disabled="isPanicLike"
                @click="$emit('mute', alert.id, !alert.muted)"
            >
                {{ alert.muted ? 'Unmute' : 'Mute' }}
            </button>

            <button class="ac-toggle-btn" @click="expanded = !expanded">
                {{ expanded ? 'Collapse' : 'Full journey' }}
            </button>

            <div class="ac-resolve-wrapper">
                <select class="ac-resolve-select" @change="onResolveChange">
                    <option value="" disabled selected>Resolve as…</option>
                    <option value="household_safe">
                        Household confirmed safe
                    </option>
                    <option value="guard_handled">
                        Guard attended / handled
                    </option>
                    <option value="false_alarm">False alarm</option>
                    <option value="escalated_external">
                        Escalated externally
                    </option>
                </select>
            </div>
        </div>

        <!-- Expanded: timeline + guardian list -->
        <transition name="ac-slide-down">
            <div v-if="expanded" class="ac-expanded">
                <div v-if="alert.guardians?.length" class="ac-expanded__block">
                    <p class="ac-expanded__label">Paired guardians notified</p>
                    <ul class="ac-expanded__list ac-guardian-list">
                        <li v-for="g in alert.guardians" :key="g.id">
                            <span class="ac-guardian-list__name">{{
                                g.name
                            }}</span>
                            <span
                                class="ac-guardian-list__status"
                                :class="{
                                    'ac-guardian-list__status--responded':
                                        g.responded_at,
                                }"
                            >
                                {{ guardianStatusLabel(g) }}
                            </span>
                        </li>
                    </ul>
                </div>
                <div
                    v-else-if="alert.guardian_ids?.length"
                    class="ac-expanded__block"
                >
                    <p class="ac-expanded__label">Paired guardians notified</p>
                    <ul class="ac-expanded__list">
                        <li v-for="g in alert.guardian_ids" :key="g">
                            Guardian #{{ g }}
                        </li>
                    </ul>
                </div>

                <div class="ac-expanded__block">
                    <p class="ac-expanded__label">Journey</p>
                    <ol class="ac-timeline">
                        <li v-for="(ev, i) in alert.events" :key="i">
                            <span class="ac-timeline__time">{{
                                new Date(ev.created_at).toLocaleTimeString()
                            }}</span>
                            <span
                                >{{ ev.actor_type }}
                                {{ ev.event_type.replace(/_/g, ' ') }}</span
                            >
                        </li>
                    </ol>
                </div>
            </div>
        </transition>

        <!-- Full-screen map modal -->
        <Teleport to="body">
            <transition name="ac-modal">
                <div
                    v-if="mapFullscreen"
                    class="ac-modal-backdrop"
                    @click.self="mapFullscreen = false"
                >
                    <div class="ac-map-modal">
                        <button
                            class="ac-close-btn"
                            @click="mapFullscreen = false"
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
                        <iframe
                            v-if="embedMapUrl"
                            class="ac-map-modal__frame"
                            :src="embedMapUrl"
                        />
                        <div v-else class="ac-map-modal__empty">
                            No location data for this alert yet
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>
    </div>
</template>

<style scoped>
.ac-card {
    --c-primary: #ea580c;
    --c-primary-h: #c2410c;
    --c-text: #1a2332;
    --c-muted: #64748b;
    --c-faint: #94a3b8;
    --c-border: #e4e8ef;
    font-family: 'DM Sans', system-ui, sans-serif;
    background: #ffffff;
    border: 1px solid var(--c-border);
    border-radius: 16px;
    padding: 18px 20px;
    position: relative;
    box-shadow:
        0 1px 3px rgba(0, 0, 0, 0.06),
        0 1px 2px rgba(0, 0, 0, 0.04);
    transition:
        box-shadow 0.2s,
        transform 0.2s;
}
.ac-card--escalated {
    border-color: #fca5a5;
    animation: ac-pulse 1.4s ease-in-out infinite;
}
@keyframes ac-pulse {
    0%,
    100% {
        box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.25);
    }
    50% {
        box-shadow: 0 0 0 6px rgba(220, 38, 38, 0.08);
    }
}

.ac-card--new {
    border: 3px solid #f59e0b;
    background: #fffbeb;
    animation: ac-new-pulse 1.1s ease-in-out infinite;
}
@keyframes ac-new-pulse {
    0%,
    100% {
        box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.5);
    }
    50% {
        box-shadow: 0 0 0 14px rgba(245, 158, 11, 0.12);
    }
}
.ac-new-ribbon {
    position: absolute;
    top: -12px;
    left: 16px;
    background: #f59e0b;
    color: #fff;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.4px;
    padding: 4px 12px;
    border: none;
    border-radius: 6px;
    text-transform: uppercase;
    box-shadow: 0 2px 8px rgba(245, 158, 11, 0.45);
    animation: ac-ribbon-bounce 0.5s ease-in-out infinite alternate;
    z-index: 1;
    cursor: pointer;
    font-family: inherit;
}
.ac-new-ribbon:hover {
    background: #d97706;
}
@keyframes ac-ribbon-bounce {
    from {
        transform: translateY(0);
    }
    to {
        transform: translateY(-3px);
    }
}

.ac-card__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}
.ac-card__household {
    font-size: 14px;
    font-weight: 700;
    color: var(--c-text);
    margin: 0;
}
.ac-card__phone {
    font-size: 12px;
    font-weight: 600;
    color: var(--c-muted);
    margin: 1px 0 0;
}
.ac-card__meta {
    font-size: 12px;
    color: var(--c-faint);
    margin: 1px 0 0;
}
.ac-card__address {
    font-size: 11px;
    color: var(--c-muted);
    margin: 2px 0 0;
}
.ac-coords {
    margin: 6px 0 0;
    font-size: 11px;
    color: var(--c-faint);
    font-variant-numeric: tabular-nums;
}
.ac-accuracy {
    color: var(--c-faint);
    font-weight: 500;
}
.ac-card__header-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
}
.ac-card__time {
    font-size: 11px;
    color: var(--c-faint);
    margin: 0;
}

.ac-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}
.ac-badge--panic {
    background: #fef2f2;
    color: #dc2626;
}
.ac-badge--dv {
    background: #f5f3ff;
    color: #7c3aed;
}
.ac-badge--guardian {
    background: #fff7ed;
    color: #ea580c;
}
.ac-badge--general {
    background: #f1f5f9;
    color: #475569;
}

.ac-escalation-flag {
    margin: 8px 0 0;
    font-size: 11px;
    font-weight: 700;
    color: #dc2626;
}
.ac-ack-flag {
    margin: 8px 0 0;
    font-size: 11px;
    font-weight: 700;
    color: #16a34a;
}

.ac-map-thumb {
    margin-top: 12px;
    width: 100%;
    height: 96px;
    border-radius: 10px;
    background: #f1f5f9;
    border: 1px solid var(--c-border);
    position: relative;
    overflow: hidden;
    cursor: pointer;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}
.ac-map-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.ac-map-thumb__empty {
    font-size: 12px;
    color: var(--c-faint);
    font-weight: 600;
}
.ac-map-thumb__expand {
    position: absolute;
    bottom: 6px;
    right: 6px;
    font-size: 10px;
    font-weight: 600;
    color: #fff;
    background: rgba(26, 35, 50, 0.65);
    padding: 2px 8px;
    border-radius: 6px;
}

.ac-guardian-line {
    margin: 10px 0 0;
    font-size: 12px;
    color: var(--c-muted);
}
.ac-link-btn {
    background: none;
    border: none;
    color: var(--c-primary);
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    padding: 0;
    margin-left: 4px;
    font-family: inherit;
    text-decoration: underline;
    text-underline-offset: 2px;
}
.ac-link-btn:hover {
    color: var(--c-primary-h);
}

.ac-actions {
    margin-top: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.ac-toggle-btn {
    padding: 7px 12px;
    background: #f8fafc;
    border: 1.5px solid var(--c-border);
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    color: var(--c-muted);
    cursor: pointer;
    transition: all 0.15s;
    font-family: inherit;
    white-space: nowrap;
}
.ac-toggle-btn:hover:not(:disabled) {
    border-color: #cbd5e1;
}
.ac-toggle-btn--on {
    border-color: var(--c-primary);
    background: #fff7ed;
    color: var(--c-primary);
}
.ac-toggle-btn--muted-tone {
    color: var(--c-faint);
}
.ac-toggle-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.ac-dv-note {
    font-size: 12px;
    color: #7c3aed;
    font-style: italic;
}

.ac-resolve-wrapper {
    margin-left: auto;
}
.ac-resolve-select {
    font-family: inherit;
    font-size: 12px;
    font-weight: 600;
    color: #16a34a;
    background: #fff;
    border: 1.5px solid #86efac;
    border-radius: 8px;
    padding: 7px 10px;
    cursor: pointer;
    outline: none;
}

.ac-expanded {
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid var(--c-border);
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.ac-expanded__label {
    font-size: 11px;
    font-weight: 700;
    color: var(--c-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0 0 6px;
}
.ac-expanded__list {
    margin: 0;
    padding-left: 18px;
    font-size: 12px;
    color: var(--c-muted);
}
.ac-guardian-list {
    padding-left: 0;
    list-style: none;
}
.ac-guardian-list li {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 8px;
    padding: 3px 0;
}
.ac-guardian-list__name {
    font-weight: 600;
    color: var(--c-text);
}
.ac-guardian-list__status {
    color: var(--c-faint);
    font-style: italic;
    white-space: nowrap;
    font-size: 11px;
}
.ac-guardian-list__status--responded {
    color: #16a34a;
    font-style: normal;
    font-weight: 600;
}
.ac-timeline {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.ac-timeline li {
    font-size: 12px;
    color: var(--c-muted);
    display: flex;
    gap: 8px;
}
.ac-timeline__time {
    color: var(--c-faint);
    flex-shrink: 0;
}

.ac-slide-down-enter-active,
.ac-slide-down-leave-active {
    transition: all 0.2s ease;
}
.ac-slide-down-enter-from,
.ac-slide-down-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}

.ac-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(10, 18, 30, 0.55);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 24px;
}
.ac-map-modal {
    background: #fff;
    border-radius: 20px;
    width: 100%;
    max-width: 900px;
    height: 80vh;
    position: relative;
    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.18);
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
}
.ac-map-modal__frame {
    width: 100%;
    height: 100%;
    border: none;
}
.ac-map-modal__empty {
    font-size: 14px;
    color: var(--c-faint);
    font-weight: 600;
}
.ac-close-btn {
    position: absolute;
    top: 12px;
    right: 12px;
    z-index: 10;
    width: 34px;
    height: 34px;
    background: #fff;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    transition: background 0.15s;
}
.ac-close-btn:hover {
    background: #f1f5f9;
}

.ac-modal-enter-active,
.ac-modal-leave-active {
    transition: opacity 0.22s ease;
}
.ac-modal-enter-from,
.ac-modal-leave-to {
    opacity: 0;
}
</style>

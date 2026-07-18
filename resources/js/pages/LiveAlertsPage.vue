<script setup>
import AlertCard from '@/components/AlertCard.vue';
import { useAdminAlerts } from '@/composables/useAdminAlerts';
import AppLayout from '@/layouts/AppLayout.vue';
import { computed, ref } from 'vue';

const props = defineProps({ socketHandshakeCode: String });

const { alerts, connectionStatus, toggleMute, logCallAttempt, resolve } =
    useAdminAlerts(props.socketHandshakeCode);

const activeFilter = ref('all');

const alertList = computed(() => Array.from(alerts.values()));

const filteredList = computed(() => {
    if (activeFilter.value === 'all') return alertList.value;
    if (activeFilter.value === 'panic')
        return alertList.value.filter((a) => ['panic', 'sos'].includes(a.type));
    return alertList.value.filter((a) => a.type === activeFilter.value);
});

const panicCount = computed(
    () =>
        alertList.value.filter((a) => ['panic', 'sos'].includes(a.type)).length,
);
const dvCount = computed(
    () => alertList.value.filter((a) => a.type === 'dv').length,
);
const escalatedCount = computed(
    () =>
        alertList.value.filter(
            (a) =>
                !a.first_ack_at &&
                (Date.now() - new Date(a.created_at).getTime()) / 1000 > 90,
        ).length,
);

function handleResolve(alertId, resolution) {
    resolve(alertId, resolution);
}
</script>

<template>
    <Head title="Live Alerts" />

    <AppLayout>
        <div class="page-root">
            <div class="page-header">
                <div class="page-header__left">
                    <div class="page-header__eyebrow">Community Safety</div>
                    <h1 class="page-header__title">Live Alerts</h1>
                </div>
                <div class="page-header__right">
                    <span
                        class="conn-badge"
                        :class="`conn-badge--${connectionStatus}`"
                    >
                        <span class="conn-dot" />
                        {{
                            connectionStatus === 'live'
                                ? 'Live'
                                : 'Reconnecting…'
                        }}
                    </span>
                </div>
            </div>

            <div class="stat-row">
                <div class="stat-card">
                    <div class="stat-card__label">Active Alerts</div>
                    <div class="stat-card__value">{{ alertList.length }}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">Panic / SOS</div>
                    <div class="stat-card__value stat-card__value--red">
                        {{ panicCount }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">DV Alerts</div>
                    <div class="stat-card__value stat-card__value--purple">
                        {{ dvCount }}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__label">No Ack &gt;90s</div>
                    <div class="stat-card__value stat-card__value--orange">
                        {{ escalatedCount }}
                    </div>
                </div>
            </div>

            <div class="filter-bar">
                <div class="filter-bar__chips">
                    <button
                        v-for="f in ['all', 'panic', 'dv', 'guardian']"
                        :key="f"
                        @click="activeFilter = f"
                        class="chip"
                        :class="{ 'chip--active': activeFilter === f }"
                    >
                        {{
                            {
                                all: 'All',
                                panic: 'Panic/SOS',
                                dv: 'DV',
                                guardian: 'Guardian',
                            }[f]
                        }}
                    </button>
                </div>
                <span class="filter-bar__count"
                    >{{ filteredList.length }} active</span
                >
            </div>

            <div v-if="filteredList.length === 0" class="empty-state">
                <p class="empty-state__title">No active alerts</p>
                <p class="empty-state__sub">
                    Incoming alerts will appear here in real time
                </p>
            </div>

            <div v-else class="alert-grid">
                <AlertCard
                    v-for="alert in filteredList"
                    :key="alert.id"
                    :alert="alert"
                    @mute="toggleMute"
                    @call-log="logCallAttempt"
                    @resolve="handleResolve"
                />
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap');

.page-root {
    font-family: 'DM Sans', system-ui, sans-serif;
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

.conn-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    background: #f0fdf4;
    color: #16a34a;
}
.conn-badge--reconnecting {
    background: #fffbeb;
    color: #b45309;
}
.conn-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: currentColor;
}
.conn-badge--live .conn-dot {
    animation: conn-pulse 1.6s ease-in-out infinite;
}
@keyframes conn-pulse {
    0%,
    100% {
        opacity: 1;
    }
    50% {
        opacity: 0.3;
    }
}

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
    box-shadow:
        0 1px 3px rgba(0, 0, 0, 0.06),
        0 1px 2px rgba(0, 0, 0, 0.04);
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
.stat-card__value--purple {
    color: #7c3aed;
}
.stat-card__value--orange {
    color: #ea580c;
}

.filter-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.filter-bar__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.filter-bar__count {
    font-size: 12px;
    font-weight: 500;
    color: #94a3b8;
}
.chip {
    padding: 5px 14px;
    border-radius: 20px;
    border: 1px solid #e4e8ef;
    background: #ffffff;
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

.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 64px 24px;
    gap: 8px;
    background: #fff;
    border: 1px solid #e4e8ef;
    border-radius: 16px;
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

.alert-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 16px;
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
    .alert-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script setup lang="ts">
defineProps<{
    label: string;
    value: string | number;
    accent?: boolean;
}>();
</script>

<template>
    <div class="stat-card" :class="{ 'stat-card--accent': accent }">
        <div class="stat-card__label">{{ label }}</div>
        <div class="stat-card__value">{{ value }}</div>
    </div>
</template>

<style scoped>
.stat-card {
    background: #f8fafc;
    border: 1.5px solid #e4e8ef;
    border-radius: 10px;
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.stat-card__label {
    font-size: 11px;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.6px;
}
.stat-card__value {
    font-size: 22px;
    font-weight: 800;
    color: #1a2332;
    letter-spacing: -0.3px;
}
.stat-card--accent .stat-card__value {
    color: #ea580c;
}
</style>

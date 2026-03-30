<script setup lang="ts">
import { computed } from 'vue';

const params = new URLSearchParams(window.location.search);
const status = params.get('status'); // success | cancelled | error
const ref = params.get('ref');

const isSuccess = computed(() => status === 'success');
const isCancelled = computed(() => status === 'cancelled');
const isError = computed(() => status === 'error');
</script>

<template>
    <div class="ty-root">
        <div class="ty-card">
            <!-- SUCCESS -->
            <template v-if="isSuccess">
                <div class="ty-icon success">✓</div>
                <h1 class="ty-title">Payment Received!</h1>
                <p class="ty-sub">
                    Your subscription is being activated. You'll receive a
                    confirmation email shortly.
                </p>
                <p class="ty-ref">
                    Reference: <strong>{{ ref }}</strong>
                </p>
                <a href="/dashboard" class="ty-btn">Go to Dashboard →</a>
            </template>

            <!-- CANCELLED -->
            <template v-else-if="isCancelled">
                <div class="ty-icon cancelled">✕</div>
                <h1 class="ty-title">Payment Cancelled</h1>
                <p class="ty-sub">
                    You cancelled the payment. Your subscription has not been
                    activated. You can try again from your dashboard.
                </p>
                <a href="/dashboard" class="ty-btn secondary"
                    >Back to Dashboard</a
                >
            </template>

            <!-- ERROR -->
            <template v-else>
                <div class="ty-icon error">⚠</div>
                <h1 class="ty-title">Something went wrong</h1>
                <p class="ty-sub">
                    Your payment could not be processed. Please try again or
                    contact support if the problem persists.
                </p>
                <p class="ty-ref">
                    Reference: <strong>{{ ref }}</strong>
                </p>
                <a href="/dashboard" class="ty-btn secondary"
                    >Back to Dashboard</a
                >
            </template>
        </div>
    </div>
</template>

<style scoped>
.ty-root {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f9fafb;
    font-family: 'Segoe UI', sans-serif;
    padding: 24px;
}

.ty-card {
    background: #fff;
    border-radius: 20px;
    padding: 48px 40px;
    max-width: 480px;
    width: 100%;
    text-align: center;
    box-shadow: 0 4px 32px rgba(0, 0, 0, 0.08);
}

.ty-icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    font-size: 28px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 24px;
}

.ty-icon.success {
    background: rgba(22, 163, 74, 0.1);
    color: #16a34a;
}
.ty-icon.cancelled {
    background: rgba(249, 115, 22, 0.1);
    color: #f97316;
}
.ty-icon.error {
    background: rgba(220, 38, 38, 0.1);
    color: #dc2626;
}

.ty-title {
    font-size: 24px;
    font-weight: 800;
    color: #111;
    margin: 0 0 12px;
    letter-spacing: -0.5px;
}

.ty-sub {
    font-size: 14px;
    color: #666;
    line-height: 1.7;
    margin: 0 0 20px;
}

.ty-ref {
    font-size: 13px;
    color: #999;
    margin-bottom: 28px;
}

.ty-ref strong {
    color: #333;
    font-family: monospace;
}

.ty-btn {
    display: inline-block;
    padding: 13px 28px;
    background: #f97316;
    color: #fff;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 700;
    text-decoration: none;
    transition: all 0.2s;
    box-shadow: 0 4px 16px rgba(249, 115, 22, 0.3);
}

.ty-btn:hover {
    background: #ea580c;
    transform: translateY(-1px);
}

.ty-btn.secondary {
    background: #f5f5f5;
    color: #333;
    box-shadow: none;
}

.ty-btn.secondary:hover {
    background: #eee;
    transform: translateY(-1px);
}
</style>

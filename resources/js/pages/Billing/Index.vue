<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';

type Gateway = 'payfast' | 'ozow';

const subscription = ref<any>(null);
const isLoading = ref(true);
const isPaymentLoading = ref<Gateway | null>(null);
const isCancelling = ref(false);
const isUpgrading = ref(false);
const errorMessage = ref<string | null>(null);
const successMessage = ref<string | null>(null);
const showCancelConfirm = ref(false);
const showUpgradeModal = ref(false);
const selectedPlan = ref('');
const selectedCycle = ref('');

const plans = ['basic', 'standard', 'premium'];
const planPrices: Record<string, Record<string, number>> = {
    basic: { monthly: 499, annual: 415 },
    standard: { monthly: 999, annual: 829 },
    premium: { monthly: 1999, annual: 1659 },
};

const statusLabel: Record<string, string> = {
    trialing: 'Trial',
    active: 'Active',
    past_due: 'Past Due',
    cancelled: 'Cancelled',
};

const getHeaders = () => ({
    headers: { Authorization: `Bearer ${localStorage.getItem('token')}` },
});

const ucFirst = (str: string) =>
    str ? str.charAt(0).toUpperCase() + str.slice(1) : '';

const formatDate = (date: string) =>
    date
        ? new Date(date).toLocaleDateString('en-ZA', {
              day: 'numeric',
              month: 'long',
              year: 'numeric',
          })
        : '—';

const statusColour = computed(() => {
    const s = subscription.value?.status;
    if (s === 'active') return 'green';
    if (s === 'trialing') return 'orange';
    if (s === 'past_due') return 'red';
    return 'gray';
});

const daysLeftInTrial = computed(
    () => subscription.value?.days_left_in_trial ?? 14,
);

const flash = (msg: string, isError = false) => {
    if (isError) errorMessage.value = msg;
    else successMessage.value = msg;
    setTimeout(() => {
        errorMessage.value = null;
        successMessage.value = null;
    }, 5000);
};

onMounted(async () => {
    try {
        const { data } = await axios.get(
            `${import.meta.env.VITE_APP_URL}/api/subscriptions`,
            getHeaders(),
        );
        subscription.value = data.subscriptions.data[0] ?? null;
        if (subscription.value) {
            selectedPlan.value = subscription.value.plan ?? 'basic';
            selectedCycle.value = subscription.value.billing_cycle ?? 'monthly';
        }
    } catch (err: any) {
        if (err.response?.status === 401) {
            router.visit('/login');
            return;
        }
    } finally {
        isLoading.value = false;
    }
});

const initiatePayment = async (gateway: Gateway) => {
    try {
        isPaymentLoading.value = gateway;
        errorMessage.value = null;
        const { data } = await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/payments/initiate`,
            { gateway },
            getHeaders(),
        );
        window.location.href = data.redirect_url;
    } catch (err: any) {
        flash(
            err.response?.data?.message ??
                'Failed to initiate payment. Please try again.',
            true,
        );
        isPaymentLoading.value = null;
    }
};

const cancelSubscription = async () => {
    try {
        isCancelling.value = true;
        await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/subscriptions/cancel`,
            {},
            getHeaders(),
        );
        subscription.value.status = 'cancelled';
        showCancelConfirm.value = false;
        flash(
            'Subscription cancelled. You will retain access until the end of your billing period.',
        );
    } catch (err: any) {
        flash(
            err.response?.data?.message ?? 'Failed to cancel subscription.',
            true,
        );
    } finally {
        isCancelling.value = false;
    }
};

const changePlan = async () => {
    try {
        isUpgrading.value = true;
        await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/subscriptions/change`,
            {
                plan: selectedPlan.value,
                billing_cycle: selectedCycle.value,
            },
            getHeaders(),
        );
        subscription.value.plan = selectedPlan.value;
        subscription.value.billing_cycle = selectedCycle.value;
        showUpgradeModal.value = false;
        flash('Plan updated successfully.');
    } catch (err: any) {
        flash(err.response?.data?.message ?? 'Failed to update plan.', true);
    } finally {
        isUpgrading.value = false;
    }
};
</script>

<template>
    <Head title="Subscription" />
    <AppLayout>
        <div class="sub-root">
            <!-- LOADING -->
            <div v-if="isLoading" class="sub-loading">
                <div class="spinner"></div>
                <p>Loading subscription...</p>
            </div>

            <template v-else>
                <div class="sub-header">
                    <div>
                        <h1 class="sub-title">Subscription</h1>
                        <p class="sub-desc">
                            Manage your Echo Link plan and payments
                        </p>
                    </div>
                </div>

                <!-- FLASH MESSAGES -->
                <div v-if="successMessage" class="flash success">
                    ✓ {{ successMessage }}
                </div>
                <div v-if="errorMessage" class="flash error">
                    ⚠ {{ errorMessage }}
                </div>

                <!-- SUBSCRIPTION CARD -->
                <div v-if="subscription" class="card">
                    <div class="card-top">
                        <div>
                            <div class="plan-name">
                                {{ ucFirst(subscription.plan) }} Plan
                            </div>
                            <span :class="['status-badge', statusColour]">{{
                                statusLabel[subscription.status] ??
                                subscription.status
                            }}</span>
                        </div>
                        <div class="meta-row">
                            <div
                                class="meta-item"
                                v-if="subscription.billing_cycle"
                            >
                                <div class="meta-lbl">Billing</div>
                                <div class="meta-val">
                                    {{ ucFirst(subscription.billing_cycle) }}
                                </div>
                            </div>
                            <div class="meta-item">
                                <div class="meta-lbl">Price</div>
                                <div class="meta-val">
                                    {{ subscription.price_in_rands ?? '—' }}/{{
                                        subscription.billing_cycle === 'annual'
                                            ? 'yr'
                                            : 'mo'
                                    }}
                                </div>
                            </div>
                            <div
                                class="meta-item"
                                v-if="
                                    subscription.trial_ends_at &&
                                    subscription.status === 'trialing'
                                "
                            >
                                <div class="meta-lbl">Trial ends</div>
                                <div class="meta-val">
                                    {{ formatDate(subscription.trial_ends_at) }}
                                </div>
                            </div>
                            <div
                                class="meta-item"
                                v-if="
                                    subscription.current_period_end &&
                                    subscription.status === 'active'
                                "
                            >
                                <div class="meta-lbl">Renews</div>
                                <div class="meta-val">
                                    {{
                                        formatDate(
                                            subscription.current_period_end,
                                        )
                                    }}
                                </div>
                            </div>
                            <div
                                class="meta-item"
                                v-if="subscription.discount_percentage"
                            >
                                <div class="meta-lbl">Discount</div>
                                <div class="meta-val green">
                                    {{ subscription.discount_percentage }}% off
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- PLAN ACTIONS -->
                    <div
                        class="plan-actions"
                        v-if="subscription.status !== 'cancelled'"
                    >
                        <button
                            class="btn-secondary"
                            @click="showUpgradeModal = true"
                        >
                            Change Plan / Billing Cycle
                        </button>
                        <button
                            class="btn-danger-ghost"
                            @click="showCancelConfirm = true"
                        >
                            Cancel Subscription
                        </button>
                    </div>
                    <div v-else class="cancelled-note">
                        Your subscription has been cancelled.
                        <a href="#" @click.prevent="showUpgradeModal = true"
                            >Reactivate</a
                        >
                    </div>
                </div>

                <!-- TRIAL BANNER -->
                <div
                    v-if="subscription?.status === 'trialing'"
                    class="banner banner-orange"
                >
                    <span>⏳</span>
                    <div>
                        <div class="banner-title">
                            Your free trial is active —
                            {{ daysLeftInTrial }} days remaining
                        </div>
                        <div class="banner-desc">
                            Add a payment method before your trial ends to keep
                            access.
                        </div>
                    </div>
                </div>

                <!-- PAST DUE BANNER -->
                <div
                    v-if="subscription?.status === 'past_due'"
                    class="banner banner-red"
                >
                    <span>⚠️</span>
                    <div>
                        <div class="banner-title">Payment overdue</div>
                        <div class="banner-desc">
                            Your subscription has lapsed. Pay now to restore
                            access.
                        </div>
                    </div>
                </div>

                <!-- GATEWAY -->
                <div
                    v-if="subscription && subscription.status !== 'cancelled'"
                    class="card"
                >
                    <div class="card-section-title">Make a Payment</div>
                    <div class="gateway-grid">
                        <button
                            v-for="gw in [
                                {
                                    key: 'payfast',
                                    icon: '💳',
                                    name: 'PayFast',
                                    desc: 'Credit card, EFT, SnapScan & more',
                                },
                                {
                                    key: 'ozow',
                                    icon: '🏦',
                                    name: 'Ozow',
                                    desc: 'Instant EFT — no card needed',
                                },
                            ]"
                            :key="gw.key"
                            class="gateway-card"
                            :class="{ loading: isPaymentLoading === gw.key }"
                            :disabled="!!isPaymentLoading"
                            @click="initiatePayment(gw.key as Gateway)"
                        >
                            <div class="gc-name">
                                <span>{{ gw.icon }}</span> {{ gw.name }}
                            </div>
                            <div class="gc-desc">{{ gw.desc }}</div>
                            <div class="gc-action">
                                <span
                                    v-if="isPaymentLoading === gw.key"
                                    class="btn-spinner"
                                ></span>
                                <span v-else>Pay with {{ gw.name }} →</span>
                            </div>
                        </button>
                    </div>
                </div>

                <!-- NO SUBSCRIPTION -->
                <div v-if="!subscription" class="card empty-state">
                    <div class="empty-icon">📋</div>
                    <div class="empty-title">No active subscription</div>
                    <div class="empty-desc">
                        Contact support or register a new plan.
                    </div>
                </div>
            </template>

            <!-- CHANGE PLAN MODAL -->
            <div
                v-if="showUpgradeModal"
                class="modal-overlay"
                @click.self="showUpgradeModal = false"
            >
                <div class="modal">
                    <div class="modal-title">Change Plan</div>
                    <div class="modal-field">
                        <label class="modal-label">Plan</label>
                        <div class="plan-picker">
                            <button
                                v-for="p in plans"
                                :key="p"
                                :class="[
                                    'plan-pick-btn',
                                    { active: selectedPlan === p },
                                ]"
                                @click="selectedPlan = p"
                            >
                                <div class="ppb-name">{{ ucFirst(p) }}</div>
                                <div class="ppb-price">
                                    R{{ planPrices[p][selectedCycle] }}/{{
                                        selectedCycle === 'annual' ? 'yr' : 'mo'
                                    }}
                                </div>
                            </button>
                        </div>
                    </div>
                    <div class="modal-field">
                        <label class="modal-label">Billing Cycle</label>
                        <div class="cycle-toggle">
                            <button
                                :class="[
                                    'cycle-btn',
                                    { active: selectedCycle === 'monthly' },
                                ]"
                                @click="selectedCycle = 'monthly'"
                            >
                                Monthly
                            </button>
                            <button
                                :class="[
                                    'cycle-btn',
                                    { active: selectedCycle === 'annual' },
                                ]"
                                @click="selectedCycle = 'annual'"
                            >
                                Annual <em>Save 17%</em>
                            </button>
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button
                            class="btn-ghost"
                            @click="showUpgradeModal = false"
                        >
                            Cancel
                        </button>
                        <button
                            class="btn-primary"
                            :disabled="isUpgrading"
                            @click="changePlan"
                        >
                            <span
                                v-if="isUpgrading"
                                class="btn-spinner white"
                            ></span>
                            <span v-else>Confirm Change</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- CANCEL CONFIRM MODAL -->
            <div
                v-if="showCancelConfirm"
                class="modal-overlay"
                @click.self="showCancelConfirm = false"
            >
                <div class="modal">
                    <div class="modal-title">Cancel Subscription?</div>
                    <p class="modal-body">
                        You will retain access until the end of your current
                        billing period. This action cannot be undone.
                    </p>
                    <div class="modal-actions">
                        <button
                            class="btn-ghost"
                            @click="showCancelConfirm = false"
                        >
                            Keep Subscription
                        </button>
                        <button
                            class="btn-danger"
                            :disabled="isCancelling"
                            @click="cancelSubscription"
                        >
                            <span
                                v-if="isCancelling"
                                class="btn-spinner white"
                            ></span>
                            <span v-else>Yes, Cancel</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.sub-root {
    max-width: 860px;
    margin: 0 auto;
    padding: 40px 24px;
    font-family: 'Segoe UI', sans-serif;
}
.sub-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
    padding: 80px 0;
    color: #888;
}
.sub-header {
    margin-bottom: 28px;
}
.sub-title {
    font-size: 24px;
    font-weight: 800;
    color: #111;
    letter-spacing: -0.5px;
    margin: 0 0 4px;
}
.sub-desc {
    font-size: 14px;
    color: #888;
    margin: 0;
}

.flash {
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 16px;
}
.flash.success {
    background: #dcfce7;
    border: 1.5px solid #86efac;
    color: #16a34a;
}
.flash.error {
    background: #fef2f2;
    border: 1.5px solid #fecaca;
    color: #dc2626;
}

.card {
    background: #fff;
    border: 1.5px solid #e5e5e5;
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 20px;
}
.card-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 20px;
}
.plan-name {
    font-size: 18px;
    font-weight: 800;
    color: #111;
    margin-bottom: 8px;
}
.status-badge {
    display: inline-block;
    font-size: 11px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 100px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.status-badge.green {
    background: rgba(22, 163, 74, 0.1);
    color: #16a34a;
}
.status-badge.orange {
    background: rgba(249, 115, 22, 0.1);
    color: #f97316;
}
.status-badge.red {
    background: rgba(220, 38, 38, 0.1);
    color: #dc2626;
}
.status-badge.gray {
    background: #f5f5f5;
    color: #888;
}
.meta-row {
    display: flex;
    gap: 24px;
    flex-wrap: wrap;
}
.meta-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.meta-lbl {
    font-size: 11px;
    color: #999;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.meta-val {
    font-size: 14px;
    font-weight: 700;
    color: #111;
}
.meta-val.green {
    color: #16a34a;
}

.plan-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    padding-top: 20px;
    border-top: 1.5px solid #f0f0f0;
    flex-wrap: wrap;
}
.cancelled-note {
    padding-top: 16px;
    border-top: 1.5px solid #f0f0f0;
    font-size: 13px;
    color: #888;
}
.cancelled-note a {
    color: #f97316;
    text-decoration: none;
    font-weight: 600;
}

.banner {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 16px;
    border-radius: 12px;
    margin-bottom: 20px;
    font-size: 13px;
}
.banner-orange {
    background: rgba(249, 115, 22, 0.05);
    border: 1.5px solid rgba(249, 115, 22, 0.2);
}
.banner-red {
    background: rgba(220, 38, 38, 0.05);
    border: 1.5px solid rgba(220, 38, 38, 0.2);
}
.banner-title {
    font-weight: 700;
    color: #111;
    margin-bottom: 2px;
}
.banner-desc {
    color: #666;
    line-height: 1.5;
}

.card-section-title {
    font-size: 14px;
    font-weight: 700;
    color: #111;
    margin-bottom: 16px;
}
.gateway-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.gateway-card {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 18px;
    border: 1.5px solid #e5e5e5;
    border-radius: 14px;
    background: #fff;
    cursor: pointer;
    text-align: left;
    transition: all 0.2s;
    width: 100%;
}
.gateway-card:hover:not(:disabled) {
    border-color: #f97316;
    box-shadow: 0 4px 16px rgba(249, 115, 22, 0.1);
}
.gateway-card:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
.gateway-card.loading {
    border-color: #f97316;
    background: rgba(249, 115, 22, 0.02);
}
.gc-name {
    font-size: 15px;
    font-weight: 800;
    color: #111;
    display: flex;
    align-items: center;
    gap: 6px;
}
.gc-desc {
    font-size: 12px;
    color: #888;
    line-height: 1.4;
}
.gc-action {
    font-size: 13px;
    font-weight: 700;
    color: #f97316;
    margin-top: 4px;
    min-height: 20px;
    display: flex;
    align-items: center;
}

.empty-state {
    text-align: center;
    padding: 48px 24px;
}
.empty-icon {
    font-size: 40px;
    margin-bottom: 12px;
}
.empty-title {
    font-size: 16px;
    font-weight: 700;
    color: #111;
    margin-bottom: 6px;
}
.empty-desc {
    font-size: 13px;
    color: #888;
}

/* MODAL */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.4);
    z-index: 500;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal {
    background: #fff;
    border-radius: 20px;
    padding: 32px;
    width: 100%;
    max-width: 480px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
}
.modal-title {
    font-size: 18px;
    font-weight: 800;
    color: #111;
    margin-bottom: 20px;
}
.modal-body {
    font-size: 14px;
    color: #666;
    line-height: 1.6;
    margin-bottom: 24px;
}
.modal-field {
    margin-bottom: 20px;
}
.modal-label {
    font-size: 12px;
    font-weight: 700;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: block;
    margin-bottom: 10px;
}
.modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 24px;
}

.plan-picker {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
}
.plan-pick-btn {
    padding: 12px 8px;
    border: 1.5px solid #e5e5e5;
    border-radius: 12px;
    background: #fff;
    cursor: pointer;
    text-align: center;
    transition: all 0.2s;
}
.plan-pick-btn:hover {
    border-color: #f97316;
}
.plan-pick-btn.active {
    border-color: #f97316;
    background: rgba(249, 115, 22, 0.05);
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
}
.ppb-name {
    font-size: 13px;
    font-weight: 700;
    color: #111;
    margin-bottom: 4px;
}
.ppb-price {
    font-size: 12px;
    color: #f97316;
    font-weight: 600;
}

.cycle-toggle {
    display: flex;
    gap: 8px;
}
.cycle-btn {
    flex: 1;
    padding: 10px;
    border: 1.5px solid #e5e5e5;
    border-radius: 10px;
    background: #fff;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    color: #555;
    transition: all 0.2s;
    font-family: 'Segoe UI', sans-serif;
}
.cycle-btn:hover {
    border-color: #f97316;
    color: #f97316;
}
.cycle-btn.active {
    border-color: #f97316;
    background: rgba(249, 115, 22, 0.05);
    color: #f97316;
}
.cycle-btn em {
    font-style: normal;
    font-size: 10px;
    font-weight: 700;
    background: rgba(22, 163, 74, 0.1);
    color: #16a34a;
    padding: 1px 6px;
    border-radius: 100px;
    margin-left: 4px;
}

/* BUTTONS */
.btn-primary {
    padding: 10px 20px;
    background: #f97316;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 8px;
    font-family: 'Segoe UI', sans-serif;
}
.btn-primary:hover:not(:disabled) {
    background: #ea580c;
}
.btn-primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
.btn-ghost {
    padding: 10px 20px;
    background: #fff;
    color: #555;
    border: 1.5px solid #e5e5e5;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    font-family: 'Segoe UI', sans-serif;
}
.btn-ghost:hover {
    border-color: #ccc;
    color: #111;
}
.btn-secondary {
    padding: 9px 18px;
    background: #f5f5f5;
    color: #333;
    border: 1.5px solid #e5e5e5;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    font-family: 'Segoe UI', sans-serif;
}
.btn-secondary:hover {
    border-color: #f97316;
    color: #f97316;
    background: rgba(249, 115, 22, 0.04);
}
.btn-danger {
    padding: 10px 20px;
    background: #dc2626;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    font-family: 'Segoe UI', sans-serif;
}
.btn-danger:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
.btn-danger-ghost {
    padding: 9px 18px;
    background: transparent;
    color: #dc2626;
    border: 1.5px solid rgba(220, 38, 38, 0.3);
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    font-family: 'Segoe UI', sans-serif;
}
.btn-danger-ghost:hover {
    background: rgba(220, 38, 38, 0.05);
    border-color: #dc2626;
}

.spinner {
    width: 32px;
    height: 32px;
    border: 3px solid #f0f0f0;
    border-top-color: #f97316;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
}
.btn-spinner {
    width: 16px;
    height: 16px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
    display: inline-block;
}
.btn-spinner.white {
    border-top-color: #fff;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

@media (max-width: 640px) {
    .gateway-grid {
        grid-template-columns: 1fr;
    }
    .card-top {
        flex-direction: column;
    }
    .plan-picker {
        grid-template-columns: 1fr;
    }
    .cycle-toggle {
        flex-direction: column;
    }
}
</style>

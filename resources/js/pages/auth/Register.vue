<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';

// ── Types ──
type BillingCycle = 'monthly' | 'annual';

interface Plan {
    key: string;
    name: string;
    price: number;
    tag: string;
    popular?: boolean;
    features: string[];
}

// ── State ──
const organisationType = ref('');
const selectedPlan = ref('');
const billingCycle = ref<BillingCycle>('monthly');
const isProcessing = ref(false);
const registrationSuccess = ref(false);
const flashMessage = ref<string | null>(null);
const errors = ref<Record<string, string[]>>({});

const form = ref({
    name: '',
    phone: '',
    email: '',
    organisation_name: '',
    password: '',
    password_confirmation: '',
});

// ── Static Data ──
const benefits = [
    {
        icon: '📡',
        title: 'Instant PTT Communication',
        desc: 'Crystal-clear voice coordination across all your channels',
    },
    {
        icon: '🚨',
        title: 'Panic & Emergency Alerts',
        desc: 'One-tap panic with real-time GPS to all responders',
    },
    {
        icon: '💰',
        title: 'Earn from Your Community',
        desc: 'Watch groups earn 65% of every R80 household subscription',
    },
    {
        icon: '📊',
        title: 'Live Command Dashboard',
        desc: 'Full visibility across channels, personnel and emergencies',
    },
];

const estatePlans: Plan[] = [
    {
        key: 'basic',
        name: 'Basic',
        price: 499,
        tag: 'Up to 50 units',
        features: [
            '2 Channels',
            'Up to 20 Personnel',
            'Panic Alerts',
            'Basic Dashboard',
        ],
    },
    {
        key: 'standard',
        name: 'Standard',
        price: 999,
        tag: 'Up to 200 units',
        popular: true,
        features: [
            '10 Channels',
            'Up to 100 Personnel',
            'Panic Alerts',
            'Full Dashboard',
            'Analytics',
        ],
    },
    {
        key: 'premium',
        name: 'Premium',
        price: 1999,
        tag: 'Unlimited units',
        features: [
            'Unlimited Channels',
            'Unlimited Personnel',
            'Panic Alerts',
            'Full Dashboard',
            'Analytics',
            'Priority Support',
            'Custom Branding',
        ],
    },
];

// ── Helpers ──
const annualPrice = (monthlyPrice: number) => Math.round(monthlyPrice * 0.83);
const annualTotal = (monthlyPrice: number) =>
    Math.round(monthlyPrice * 0.83 * 12);

const selectType = (type: string) => {
    organisationType.value = type;
    selectedPlan.value = '';
};

const selectPlan = (plan: string) => {
    selectedPlan.value = plan;
};

const showMessage = (message: string) => {
    flashMessage.value = message;
    setTimeout(() => (flashMessage.value = null), 4000);
};

// ── Validation ──
const canSubmit = computed(() => {
    if (!organisationType.value) return false;
    if (organisationType.value === 'estate' && !selectedPlan.value)
        return false;
    return true;
});

// ── Submit ──
const handleSubmit = async () => {
    try {
        isProcessing.value = true;
        errors.value = {};

        await axios.post(`${import.meta.env.VITE_APP_URL}/api/register`, {
            ...form.value,
            organisation_type: organisationType.value,
            plan: selectedPlan.value || null,
            billing_cycle: billingCycle.value,
        });

        registrationSuccess.value = true;
        setTimeout(() => {
            router.visit('/login');
        }, 3000);
    } catch (err: any) {
        console.error('Registration error:', err.response ?? err);
        if (err.response?.status === 422) {
            errors.value = err.response.data.errors;
        } else {
            showMessage(
                err.response?.data?.message ??
                    'Registration failed. Please try again.',
            );
        }
    } finally {
        isProcessing.value = false;
    }
};
</script>

<template>
    <Head title="Register — Echo Link" />

    <div class="reg-root">
        <!-- ── LEFT PANEL ── -->
        <div class="reg-left">
            <div class="reg-left-inner">
                <a href="/" class="reg-logo">
                    <img
                        src="https://policy.jaroworkspace.com/echolink.png"
                        alt="Echo Link"
                    />
                    <span>Echo <em>Link</em></span>
                </a>

                <div class="reg-left-content">
                    <div class="reg-tagline">
                        Protecting communities,<br />one connection at a time.
                    </div>

                    <div class="reg-benefits">
                        <div
                            v-for="benefit in benefits"
                            :key="benefit.icon"
                            class="reg-benefit"
                        >
                            <div class="rb-icon">{{ benefit.icon }}</div>
                            <div>
                                <div class="rb-title">{{ benefit.title }}</div>
                                <div class="rb-desc">{{ benefit.desc }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="reg-trust">
                        <div class="rt-item">
                            <span>✓</span> 14-day free trial
                        </div>
                        <div class="rt-item">
                            <span>✓</span> No credit card needed
                        </div>
                        <div class="rt-item"><span>✓</span> SA hosted</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── RIGHT PANEL ── -->
        <div class="reg-right">
            <!-- SUCCESS SCREEN -->
            <div v-if="registrationSuccess" class="success-wrap">
                <div class="success-icon">🎉</div>
                <h2 class="success-title">You're all set!</h2>
                <p class="success-sub">
                    Your account has been created. We've sent a welcome email to
                    <strong>{{ form.email }}</strong> with everything you need
                    to get started.
                </p>
                <div class="success-steps">
                    <div class="ss-item done">
                        <span class="ss-check">✓</span>
                        <span>Account created</span>
                    </div>
                    <div class="ss-item done">
                        <span class="ss-check">✓</span>
                        <span>Welcome email sent</span>
                    </div>
                    <div class="ss-item pending">
                        <span class="ss-spinner"></span>
                        <span>Redirecting to your login</span>
                    </div>
                </div>
                <a href="/dashboard" class="success-cta">Go to Dashboard →</a>
            </div>

            <!-- REGISTRATION FORM -->
            <div v-else class="reg-form-wrap">
                <div class="reg-form-header">
                    <h1 class="reg-title">Create your account</h1>
                    <p class="reg-sub">
                        Get your team coordinating in under 5 minutes
                    </p>
                </div>

                <div v-if="flashMessage" class="flash-error">
                    {{ flashMessage }}
                </div>

                <form @submit.prevent="handleSubmit">
                    <!-- ORG TYPE -->
                    <div class="reg-field">
                        <label class="reg-label">I am registering as</label>
                        <div class="type-cards">
                            <button
                                type="button"
                                :class="[
                                    'type-card',
                                    { selected: organisationType === 'watch' },
                                ]"
                                @click="selectType('watch')"
                            >
                                <div class="tc-icon">🏘️</div>
                                <div class="tc-name">Neighbourhood Watch</div>
                                <div class="tc-desc">
                                    Watch Group / Individual
                                </div>
                                <div
                                    v-if="organisationType === 'watch'"
                                    class="tc-check"
                                >
                                    ✓
                                </div>
                            </button>
                            <button
                                type="button"
                                :class="[
                                    'type-card',
                                    { selected: organisationType === 'estate' },
                                ]"
                                @click="selectType('estate')"
                            >
                                <div class="tc-icon">🏢</div>
                                <div class="tc-name">Estate / Complex</div>
                                <div class="tc-desc">
                                    Gated Estate / HOA / Complex
                                </div>
                                <div
                                    v-if="organisationType === 'estate'"
                                    class="tc-check"
                                >
                                    ✓
                                </div>
                            </button>
                        </div>
                        <p v-if="errors.organisation_type" class="reg-error">
                            {{ errors.organisation_type[0] }}
                        </p>
                    </div>

                    <!-- WATCH BANNER -->
                    <div
                        v-if="organisationType === 'watch'"
                        class="watch-banner"
                    >
                        <div class="wb-left">
                            <div class="wb-title">
                                How it works for Watch Groups
                            </div>
                            <div class="wb-desc">
                                Households in your area pay
                                <strong>R80/month</strong>. Your watch group
                                automatically receives
                                <strong>65% (R52)</strong> per household every
                                month. No flat fee — you earn as you grow.
                            </div>
                        </div>
                        <div class="wb-badge">
                            <div class="wb-pct">65%</div>
                            <div class="wb-pct-lbl">yours</div>
                        </div>
                    </div>

                    <!-- ESTATE PLAN SELECTOR -->
                    <div
                        v-if="organisationType === 'estate'"
                        class="plan-section"
                    >
                        <label class="reg-label">
                            Choose your plan
                            <span class="plan-trial-note"
                                >· 14-day free trial</span
                            >
                        </label>

                        <div class="billing-toggle-wrap">
                            <button
                                type="button"
                                :class="[
                                    'billing-toggle-btn',
                                    { active: billingCycle === 'monthly' },
                                ]"
                                @click="billingCycle = 'monthly'"
                            >
                                Monthly
                            </button>
                            <button
                                type="button"
                                :class="[
                                    'billing-toggle-btn',
                                    { active: billingCycle === 'annual' },
                                ]"
                                @click="billingCycle = 'annual'"
                            >
                                Annual
                                <span class="billing-save-badge">Save 17%</span>
                            </button>
                        </div>

                        <div class="plan-cards">
                            <button
                                v-for="plan in estatePlans"
                                :key="plan.key"
                                type="button"
                                :class="[
                                    'plan-card',
                                    {
                                        selected: selectedPlan === plan.key,
                                        popular: plan.popular,
                                    },
                                ]"
                                @click="selectPlan(plan.key)"
                            >
                                <div
                                    v-if="plan.popular"
                                    class="plan-popular-tag"
                                >
                                    Most Popular
                                </div>
                                <div class="plan-card-header">
                                    <div class="plan-card-name">
                                        {{ plan.name }}
                                    </div>
                                    <div class="plan-card-tag">
                                        {{ plan.tag }}
                                    </div>
                                    <div class="plan-card-price">
                                        <span class="pcp-cur">R</span>
                                        <span class="pcp-amt">{{
                                            billingCycle === 'annual'
                                                ? annualPrice(plan.price)
                                                : plan.price
                                        }}</span>
                                        <span class="pcp-per">/mo</span>
                                    </div>
                                    <div
                                        v-if="billingCycle === 'annual'"
                                        class="pcp-original"
                                    >
                                        R{{ plan.price }}/mo billed as R{{
                                            annualTotal(plan.price)
                                        }}/yr
                                    </div>
                                </div>
                                <ul class="plan-card-feats">
                                    <li v-for="f in plan.features" :key="f">
                                        <span>✓</span> {{ f }}
                                    </li>
                                </ul>
                                <div
                                    v-if="selectedPlan === plan.key"
                                    class="plan-selected-badge"
                                >
                                    ✓ Selected
                                </div>
                            </button>
                        </div>

                        <p v-if="!selectedPlan" class="plan-hint">
                            Please select a plan to continue
                        </p>
                    </div>

                    <!-- FORM FIELDS -->
                    <div v-if="organisationType" class="form-reveal">
                        <div class="reg-row" style="margin-bottom: 18px">
                            <div class="reg-field">
                                <label class="reg-label" for="name"
                                    >Your Full Name</label
                                >
                                <input
                                    v-model="form.name"
                                    id="name"
                                    type="text"
                                    class="reg-input"
                                    placeholder="John Dlamini"
                                    required
                                    autofocus
                                    :tabindex="1"
                                    autocomplete="name"
                                />
                                <InputError
                                    :message="errors.name?.[0]"
                                    class="reg-error"
                                />
                            </div>
                            <div class="reg-field">
                                <label class="reg-label" for="phone"
                                    >Phone Number</label
                                >
                                <input
                                    v-model="form.phone"
                                    id="phone"
                                    type="tel"
                                    class="reg-input"
                                    placeholder="+27 82 000 0000"
                                    :tabindex="2"
                                    autocomplete="tel"
                                />
                                <InputError
                                    :message="errors.phone?.[0]"
                                    class="reg-error"
                                />
                            </div>
                        </div>

                        <div class="reg-field" style="margin-bottom: 18px">
                            <label class="reg-label" for="organisation_name"
                                >Organisation Name</label
                            >
                            <input
                                v-model="form.organisation_name"
                                id="organisation_name"
                                type="text"
                                class="reg-input"
                                :placeholder="
                                    organisationType === 'estate'
                                        ? 'Sunridge Estate'
                                        : 'Midrand North Watch'
                                "
                                required
                                :tabindex="3"
                            />
                            <InputError
                                :message="errors.organisation_name?.[0]"
                                class="reg-error"
                            />
                        </div>

                        <div class="reg-field" style="margin-bottom: 18px">
                            <label class="reg-label" for="email"
                                >Email Address</label
                            >
                            <input
                                v-model="form.email"
                                id="email"
                                type="email"
                                class="reg-input"
                                placeholder="you@example.com"
                                required
                                :tabindex="4"
                                autocomplete="email"
                            />
                            <InputError
                                :message="errors.email?.[0]"
                                class="reg-error"
                            />
                        </div>

                        <div class="reg-row" style="margin-bottom: 18px">
                            <div class="reg-field">
                                <label class="reg-label" for="password"
                                    >Password</label
                                >
                                <input
                                    v-model="form.password"
                                    id="password"
                                    type="password"
                                    class="reg-input"
                                    placeholder="Min. 8 characters"
                                    required
                                    :tabindex="5"
                                    autocomplete="new-password"
                                />
                                <InputError
                                    :message="errors.password?.[0]"
                                    class="reg-error"
                                />
                            </div>
                            <div class="reg-field">
                                <label
                                    class="reg-label"
                                    for="password_confirmation"
                                    >Confirm Password</label
                                >
                                <input
                                    v-model="form.password_confirmation"
                                    id="password_confirmation"
                                    type="password"
                                    class="reg-input"
                                    placeholder="Repeat password"
                                    required
                                    :tabindex="6"
                                    autocomplete="new-password"
                                />
                                <InputError
                                    :message="errors.password_confirmation?.[0]"
                                    class="reg-error"
                                />
                            </div>
                        </div>

                        <button
                            type="submit"
                            class="reg-submit"
                            :tabindex="7"
                            :disabled="isProcessing || !canSubmit"
                            data-test="register-user-button"
                        >
                            <span v-if="!isProcessing">Create Account →</span>
                            <span v-else class="reg-spinner"></span>
                        </button>

                        <p class="reg-login-link" style="margin-top: 16px">
                            Already have an account?
                            <a href="/login" :tabindex="8">Sign in →</a>
                        </p>
                    </div>

                    <!-- PROMPT -->
                    <div v-else class="type-prompt">
                        <span>👆</span> Select your organisation type above to
                        continue
                    </div>
                </form>

                <p class="reg-terms">
                    By registering you agree to our
                    <a href="https://policy.jaroworkspace.com" target="_blank"
                        >Privacy Policy</a
                    >.
                </p>
            </div>
        </div>
    </div>
</template>

<style scoped>
*,
*::before,
*::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

/* ── ROOT ── */
.reg-root {
    display: flex;
    align-items: flex-start;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #fff;
}

/* ── LEFT PANEL ── */
.reg-left {
    width: 460px;
    flex-shrink: 0;
    background: #1c2333;
    display: flex;
    flex-direction: column;
    position: sticky;
    top: 0;
    height: 100vh;
    overflow: hidden;
}
.reg-left::before {
    content: '';
    position: absolute;
    width: 500px;
    height: 500px;
    border-radius: 50%;
    background: radial-gradient(
        ellipse,
        rgba(249, 115, 22, 0.15) 0%,
        transparent 70%
    );
    bottom: -100px;
    left: -100px;
    pointer-events: none;
}
.reg-left::after {
    content: '';
    position: absolute;
    width: 300px;
    height: 300px;
    border-radius: 50%;
    background: radial-gradient(
        ellipse,
        rgba(249, 115, 22, 0.08) 0%,
        transparent 70%
    );
    top: -50px;
    right: -80px;
    pointer-events: none;
}
.reg-left-inner {
    position: relative;
    z-index: 1;
    padding: 40px;
    display: flex;
    flex-direction: column;
    height: 100%;
}
.reg-logo {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    margin-bottom: 56px;
    flex-shrink: 0;
}
.reg-logo img {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    object-fit: cover;
}
.reg-logo span {
    font-size: 19px;
    font-weight: 800;
    color: #fff;
    letter-spacing: -0.5px;
}
.reg-logo em {
    font-style: normal;
    color: #f97316;
}
.reg-left-content {
    flex: 1;
}
.reg-tagline {
    font-size: 26px;
    font-weight: 700;
    color: #fff;
    line-height: 1.3;
    letter-spacing: -0.5px;
    margin-bottom: 40px;
}
.reg-benefits {
    display: flex;
    flex-direction: column;
    gap: 24px;
    margin-bottom: 40px;
}
.reg-benefit {
    display: flex;
    align-items: flex-start;
    gap: 14px;
}
.rb-icon {
    font-size: 20px;
    flex-shrink: 0;
    margin-top: 2px;
}
.rb-title {
    font-size: 14px;
    font-weight: 700;
    color: #e0eaff;
    margin-bottom: 2px;
}
.rb-desc {
    font-size: 12px;
    color: #5a7099;
    line-height: 1.5;
}
.reg-trust {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding-top: 32px;
    border-top: 1px solid #2d3d5a;
}
.rt-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: #5a7099;
    font-weight: 500;
}
.rt-item span {
    color: #f97316;
    font-weight: 700;
}

/* ── RIGHT PANEL ── */
.reg-right {
    flex: 1;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 48px 32px;
    overflow-y: auto;
    min-height: 100vh;
}
.reg-form-wrap {
    width: 100%;
    max-width: 560px;
}
.reg-form-header {
    margin-bottom: 32px;
}
.reg-title {
    font-size: 28px;
    font-weight: 800;
    color: #111;
    letter-spacing: -1px;
    margin: 0 0 6px 0;
}
.reg-sub {
    font-size: 14px;
    color: #888;
    margin: 0;
}

/* ── FLASH ERROR ── */
.flash-error {
    background: #fef2f2;
    border: 1.5px solid #fca5a5;
    color: #dc2626;
    font-size: 13px;
    font-weight: 600;
    padding: 12px 16px;
    border-radius: 10px;
    margin-bottom: 20px;
}

/* ── SUCCESS SCREEN ── */
.success-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 48px 32px;
    max-width: 440px;
    margin: auto;
    animation: fadeSlide 0.4s ease;
}
.success-icon {
    font-size: 56px;
    margin-bottom: 20px;
}
.success-title {
    font-size: 28px;
    font-weight: 800;
    color: #111;
    letter-spacing: -1px;
    margin-bottom: 12px;
}
.success-sub {
    font-size: 14px;
    color: #666;
    line-height: 1.7;
    margin-bottom: 32px;
}
.success-sub strong {
    color: #111;
    font-weight: 600;
}
.success-steps {
    display: flex;
    flex-direction: column;
    gap: 12px;
    width: 100%;
    background: #fafafa;
    border: 1.5px solid #e5e5e5;
    border-radius: 14px;
    padding: 20px 24px;
    margin-bottom: 28px;
    text-align: left;
}
.ss-item {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    font-weight: 500;
    color: #888;
}
.ss-item.done {
    color: #111;
}
.ss-check {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #16a34a;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.ss-spinner {
    width: 22px;
    height: 22px;
    border: 2px solid #e5e5e5;
    border-top-color: #f97316;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    flex-shrink: 0;
    display: inline-block;
}
.success-cta {
    display: inline-block;
    padding: 13px 32px;
    background: #f97316;
    color: #fff;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 700;
    text-decoration: none;
    box-shadow: 0 4px 20px rgba(249, 115, 22, 0.3);
    transition: all 0.2s;
}
.success-cta:hover {
    background: #ea580c;
    transform: translateY(-1px);
}

/* ── FORM LAYOUT ── */
.reg-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
.reg-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.reg-label {
    font-size: 13px;
    font-weight: 600;
    color: #333;
}
.reg-input {
    width: 100%;
    padding: 11px 14px;
    border: 1.5px solid #e5e5e5;
    border-radius: 10px;
    font-size: 14px;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    color: #111;
    background: #fff;
    transition: all 0.15s;
    outline: none;
}
.reg-input::placeholder {
    color: #bbb;
}
.reg-input:focus {
    border-color: #f97316;
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
}
.reg-error {
    font-size: 12px;
    color: #dc2626;
    margin-top: 2px;
}

/* ── TYPE CARDS ── */
.type-cards {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 15px;
}
.type-card {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
    padding: 16px;
    border: 1.5px solid #e5e5e5;
    border-radius: 14px;
    background: #fff;
    cursor: pointer;
    transition: all 0.2s;
    text-align: left;
    width: 100%;
}
.type-card:hover {
    border-color: #f97316;
    background: rgba(249, 115, 22, 0.02);
}
.type-card.selected {
    border-color: #f97316;
    background: rgba(249, 115, 22, 0.05);
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
}
.tc-icon {
    font-size: 22px;
    margin-bottom: 4px;
}
.tc-name {
    font-size: 13px;
    font-weight: 700;
    color: #111;
}
.tc-desc {
    font-size: 11px;
    color: #888;
}
.tc-check {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #f97316;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* ── WATCH BANNER ── */
.watch-banner {
    display: flex;
    align-items: center;
    gap: 16px;
    background: rgba(249, 115, 22, 0.05);
    border: 1.5px solid rgba(249, 115, 22, 0.2);
    border-radius: 14px;
    padding: 16px 20px;
    animation: fadeSlide 0.25s ease;
    margin-bottom: 20px;
}
.wb-left {
    flex: 1;
}
.wb-title {
    font-size: 13px;
    font-weight: 700;
    color: #111;
    margin-bottom: 6px;
}
.wb-desc {
    font-size: 12px;
    color: #666;
    line-height: 1.6;
}
.wb-desc strong {
    color: #f97316;
}
.wb-badge {
    text-align: center;
    flex-shrink: 0;
    background: #f97316;
    border-radius: 12px;
    padding: 10px 16px;
}
.wb-pct {
    font-size: 22px;
    font-weight: 800;
    color: #fff;
    line-height: 1;
}
.wb-pct-lbl {
    font-size: 10px;
    color: rgba(255, 255, 255, 0.8);
    font-weight: 600;
    letter-spacing: 0.5px;
    margin-top: 2px;
}

/* ── BILLING TOGGLE ── */
.billing-toggle-wrap {
    display: flex;
    align-items: center;
    background: #f5f5f5;
    border-radius: 12px;
    padding: 4px;
    gap: 4px;
    width: fit-content;
    margin-bottom: 4px;
}
.billing-toggle-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 20px;
    border: none;
    border-radius: 9px;
    font-size: 13px;
    font-weight: 600;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    color: #888;
    background: transparent;
    cursor: pointer;
    transition: all 0.2s;
}
.billing-toggle-btn.active {
    background: #fff;
    color: #111;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
}
.billing-save-badge {
    background: #16a34a;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 100px;
    letter-spacing: 0.3px;
}

/* ── PLAN CARDS ── */
.plan-section {
    display: flex;
    flex-direction: column;
    gap: 10px;
    animation: fadeSlide 0.25s ease;
}
.plan-trial-note {
    font-size: 11px;
    font-weight: 500;
    color: #16a34a;
}
.plan-cards {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
}
.plan-card {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 14px;
    border: 1.5px solid #e5e5e5;
    border-radius: 14px;
    background: #fff;
    cursor: pointer;
    transition: all 0.2s;
    text-align: left;
    width: 100%;
}
.plan-card:hover {
    border-color: #f97316;
    box-shadow: 0 4px 16px rgba(249, 115, 22, 0.1);
}
.plan-card.selected {
    border-color: #f97316;
    background: rgba(249, 115, 22, 0.04);
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.12);
}
.plan-card.popular {
    border-color: #f97316;
}
.plan-popular-tag {
    position: absolute;
    top: -11px;
    left: 50%;
    transform: translateX(-50%);
    background: #f97316;
    color: #fff;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    padding: 3px 10px;
    border-radius: 100px;
    white-space: nowrap;
}
.plan-card-name {
    font-size: 14px;
    font-weight: 800;
    color: #111;
    margin-bottom: 2px;
}
.plan-card-tag {
    font-size: 10px;
    color: #999;
    margin-bottom: 8px;
}
.plan-card-price {
    display: flex;
    align-items: baseline;
    gap: 2px;
    margin-bottom: 4px;
}
.pcp-cur {
    font-size: 12px;
    font-weight: 600;
    color: #999;
    align-self: flex-start;
    margin-top: 4px;
}
.pcp-amt {
    font-size: 26px;
    font-weight: 800;
    color: #111;
    letter-spacing: -1px;
}
.pcp-per {
    font-size: 11px;
    color: #999;
}
.pcp-original {
    font-size: 10px;
    color: #999;
    margin-top: 2px;
}
.plan-card-feats {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
    border-top: 1px solid #f0f0f0;
    padding-top: 8px;
}
.plan-card-feats li {
    font-size: 11px;
    color: #666;
    display: flex;
    align-items: center;
    gap: 5px;
}
.plan-card-feats li span {
    color: #f97316;
    font-weight: 700;
    font-size: 10px;
}
.plan-selected-badge {
    background: #f97316;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 8px;
    text-align: center;
    margin-top: 4px;
}
.plan-hint {
    font-size: 12px;
    color: #dc2626;
}

/* ── SUBMIT ── */
.reg-submit {
    width: 100%;
    padding: 14px;
    background: #f97316;
    color: #fff;
    border: none;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 700;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    cursor: pointer;
    transition: all 0.2s;
    box-shadow: 0 4px 20px rgba(249, 115, 22, 0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 50px;
    margin-top: 4px;
}
.reg-submit:hover:not(:disabled) {
    background: #ea580c;
    transform: translateY(-1px);
    box-shadow: 0 8px 28px rgba(249, 115, 22, 0.4);
}
.reg-submit:disabled {
    opacity: 0.55;
    cursor: not-allowed;
    transform: none;
}
.reg-spinner {
    width: 20px;
    height: 20px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
    display: inline-block;
}

/* ── MISC ── */
.form-reveal {
    display: flex;
    flex-direction: column;
    animation: fadeSlide 0.3s ease;
    margin-top: 25px;
}
.type-prompt {
    text-align: center;
    padding: 20px;
    font-size: 14px;
    color: #aaa;
    background: #fafafa;
    border: 1.5px dashed #e5e5e5;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.reg-login-link {
    text-align: center;
    font-size: 13px;
    color: #888;
    margin: 0;
}
.reg-login-link a {
    color: #f97316;
    text-decoration: none;
    font-weight: 600;
}
.reg-login-link a:hover {
    text-decoration: underline;
}
.reg-terms {
    text-align: center;
    font-size: 12px;
    color: #bbb;
    margin-top: 12px;
}
.reg-terms a {
    color: #aaa;
    text-decoration: underline;
}

/* ── ANIMATIONS ── */
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
@keyframes fadeSlide {
    from {
        opacity: 0;
        transform: translateY(-8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* ── RESPONSIVE ── */
@media (max-width: 900px) {
    .reg-root {
        flex-direction: column;
        width: 100%;
    }
    .reg-left {
        width: 100%;
        min-height: auto;
        position: relative;
        height: auto;
    }
    .reg-left-inner {
        padding: 32px 24px;
    }
    .reg-tagline {
        font-size: 20px;
        margin-bottom: 24px;
    }
    .reg-benefits {
        gap: 16px;
        margin-bottom: 24px;
    }
    .reg-trust {
        flex-direction: row;
        gap: 20px;
        padding-top: 20px;
    }
    .reg-right {
        width: 100%;
        padding: 32px 20px;
        min-height: auto;
        align-items: flex-start;
    }
    .reg-form-wrap {
        max-width: 100%;
    }
    .plan-cards {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 560px) {
    .reg-root {
        overflow-x: hidden;
    }
    .reg-row {
        grid-template-columns: 1fr;
    }
    .type-cards {
        grid-template-columns: 1fr;
    }
    .reg-trust {
        flex-direction: column;
        gap: 8px;
    }
    .reg-right {
        padding: 24px 16px;
    }
    .reg-left-inner {
        padding: 24px 16px;
    }
    .watch-banner {
        flex-direction: column;
        gap: 12px;
    }
    .wb-badge {
        align-self: flex-start;
    }
}
</style>

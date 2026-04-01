<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';

// ── Toggle self-registration ───────────────────────────────────────────────────
// Set to true when you're ready to open registration to the public
const selfRegistrationOpen = ref(false);

// ── State ──────────────────────────────────────────────────────────────────────
const organisationType = ref('');
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

// ── Billing split by org type ──────────────────────────────────────────────────
const splits = {
    watch: {
        client: 52,
        platform: 28,
        pct: 65,
        label: 'your watch group earns',
    },
    estate: { client: 30, platform: 50, pct: 37.5, label: 'your estate earns' },
};

const split = computed(
    () => splits[organisationType.value as keyof typeof splits] ?? null,
);

// ── Left panel benefits (shared) ───────────────────────────────────────────────
const benefits = [
    {
        icon: '📡',
        title: 'Instant PTT Communication',
        desc: 'Crystal-clear push-to-talk across all channels — no hardware needed.',
    },
    {
        icon: '🚨',
        title: 'Panic & Emergency Alerts',
        desc: 'One-tap panic with live GPS to all responders, instantly.',
    },
    {
        icon: '💰',
        title: 'Earn from Your Community',
        desc: 'Watch groups earn 65% · Estates earn 37.5% of every R80 unit subscription.',
    },
    {
        icon: '📊',
        title: 'Live Command Dashboard',
        desc: 'Full visibility across channels, personnel and emergencies.',
    },
];

// ── Helpers ────────────────────────────────────────────────────────────────────
const selectType = (type: string) => {
    organisationType.value = type;
};

const showMessage = (msg: string) => {
    flashMessage.value = msg;
    setTimeout(() => (flashMessage.value = null), 4000);
};

const canSubmit = computed(() => !!organisationType.value);

// ── Submit ─────────────────────────────────────────────────────────────────────
const handleSubmit = async () => {
    try {
        isProcessing.value = true;
        errors.value = {};
        await axios.post(`${import.meta.env.VITE_APP_URL}/api/register`, {
            ...form.value,
            organisation_type: organisationType.value,
            plan: null,
            billing_cycle: 'monthly',
        });
        registrationSuccess.value = true;
        setTimeout(() => {
            router.visit('/login');
        }, 3000);
    } catch (err: any) {
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
        <!-- ══════════════════════════════════════════════════════════════ -->
        <!-- LEFT PANEL                                                     -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="reg-left">
            <div class="reg-left-inner">
                <a href="/" class="reg-logo">
                    <img
                        src="https://policy.jaroworkspace.com/echolink.png"
                        alt="Echo Link"
                    />
                    <span>Echo <em>Link</em></span>
                </a>

                <div class="reg-left-body">
                    <p class="reg-tagline">
                        Protecting communities,<br />one connection at a time.
                    </p>

                    <div class="reg-benefits">
                        <div v-for="b in benefits" :key="b.icon" class="rb">
                            <div class="rb-icon">{{ b.icon }}</div>
                            <div>
                                <div class="rb-title">{{ b.title }}</div>
                                <div class="rb-desc">{{ b.desc }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- Dynamic split card — updates on org type selection -->
                    <Transition name="slide">
                        <div
                            v-if="split && selfRegistrationOpen"
                            class="split-card"
                        >
                            <div class="split-card-label">
                                {{
                                    organisationType === 'watch'
                                        ? '🏘️ Neighbourhood Watch'
                                        : '🏢 Estate / Complex'
                                }}
                                · Revenue split
                            </div>
                            <div class="split-row">
                                <div class="split-item split-yours">
                                    <div class="split-pct">
                                        {{ split.pct }}%
                                    </div>
                                    <div class="split-sub">
                                        {{ split.label }}
                                    </div>
                                    <div class="split-rand">
                                        R{{ split.client }}/unit
                                    </div>
                                </div>
                                <div class="split-divider">+</div>
                                <div class="split-item split-echo">
                                    <div class="split-pct">
                                        {{ 100 - split.pct }}%
                                    </div>
                                    <div class="split-sub">Echo Link fee</div>
                                    <div class="split-rand">
                                        R{{ split.platform }}/unit
                                    </div>
                                </div>
                            </div>
                            <div class="split-note">
                                R80/unit/month · Paid out 1st of each month
                            </div>
                        </div>
                        <div
                            v-else-if="!selfRegistrationOpen"
                            class="split-card split-card-contact"
                        >
                            <div class="split-card-label">
                                🏘️ Watch Groups · 🏢 Estates
                            </div>
                            <div class="split-contact-amounts">
                                <div class="sca-item">
                                    <div class="sca-pct">65%</div>
                                    <div class="sca-label">
                                        Watch group earns
                                    </div>
                                    <div class="sca-rand">R52/household</div>
                                </div>
                                <div class="split-divider">·</div>
                                <div class="sca-item">
                                    <div class="sca-pct">37.5%</div>
                                    <div class="sca-label">Estate earns</div>
                                    <div class="sca-rand">R30/unit</div>
                                </div>
                            </div>
                            <div class="split-note">
                                R80/unit/month · Paid out 1st of each month
                            </div>
                        </div>
                        <div v-else class="split-placeholder">
                            <div class="sp-text">
                                ← Select your organisation type to see your
                                earnings split
                            </div>
                        </div>
                    </Transition>
                </div>

                <div class="reg-trust">
                    <div class="rt-item"><span>✓</span> 14-day free trial</div>
                    <div class="rt-item">
                        <span>✓</span> No credit card needed
                    </div>
                    <div class="rt-item"><span>✓</span> SA hosted</div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!-- RIGHT PANEL                                                    -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="reg-right">
            <!-- ── CONTACT US (registration closed) ── -->
            <div v-if="!selfRegistrationOpen" class="contact-wrap">
                <div class="contact-icon">🚀</div>
                <h1 class="contact-title">Ready to join Echo Link?</h1>
                <p class="contact-sub">
                    We're currently onboarding watch groups and estates directly
                    to ensure every organisation on the platform is verified and
                    ready to protect their community.<br /><br />
                    Get in touch and we'll have your account set up within
                    <strong>24 hours</strong>.
                </p>
                <div class="contact-cards">
                    <a
                        href="mailto:jaroworkspace0@gmail.com"
                        class="contact-card"
                    >
                        <span class="contact-card-icon">✉️</span>
                        <div>
                            <div class="contact-card-title">Email us</div>
                            <div class="contact-card-val">
                                jaroworkspace0@gmail.com
                            </div>
                        </div>
                    </a>
                    <a
                        href="https://wa.me/27000000000"
                        target="_blank"
                        class="contact-card contact-card-green"
                        style="display: none"
                    >
                        <span class="contact-card-icon">💬</span>
                        <div>
                            <div class="contact-card-title">WhatsApp</div>
                            <div class="contact-card-val">
                                Chat with us directly
                            </div>
                        </div>
                    </a>
                </div>
                <p class="contact-note">
                    Already have an account? <a href="/login">Sign in →</a>
                </p>
            </div>

            <!-- ── SELF REGISTRATION (hidden until selfRegistrationOpen = true) ── -->
            <template v-else>
                <!-- SUCCESS STATE -->
                <div v-if="registrationSuccess" class="success-wrap">
                    <div class="success-icon">🎉</div>
                    <h2 class="success-title">You're all set!</h2>
                    <p class="success-sub">
                        Your account has been created. We've sent a welcome
                        email to
                        <strong>{{ form.email }}</strong
                        >.
                    </p>
                    <div class="success-steps">
                        <div class="ss-item done">
                            <span class="ss-check">✓</span> Account created
                        </div>
                        <div class="ss-item done">
                            <span class="ss-check">✓</span> Welcome email sent
                        </div>
                        <div class="ss-item pending">
                            <span class="ss-spin"></span> Redirecting to login…
                        </div>
                    </div>
                    <a href="/login" class="btn-primary">Go to Login →</a>
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
                        ⚠ {{ flashMessage }}
                    </div>

                    <form @submit.prevent="handleSubmit" class="reg-form">
                        <!-- STEP 1: ORG TYPE -->
                        <div class="form-section">
                            <div class="form-section-label">
                                I am registering as
                            </div>
                            <div class="type-cards">
                                <button
                                    type="button"
                                    :class="[
                                        'type-card',
                                        {
                                            selected:
                                                organisationType === 'watch',
                                        },
                                    ]"
                                    @click="selectType('watch')"
                                >
                                    <div
                                        class="tc-check-ring"
                                        v-if="organisationType === 'watch'"
                                    >
                                        ✓
                                    </div>
                                    <div class="tc-icon">🏘️</div>
                                    <div class="tc-name">
                                        Neighbourhood Watch
                                    </div>
                                    <div class="tc-desc">
                                        Watch group · CPF · Individual
                                    </div>
                                    <div class="tc-earn">
                                        Earn R52/household/month
                                    </div>
                                </button>
                                <button
                                    type="button"
                                    :class="[
                                        'type-card',
                                        {
                                            selected:
                                                organisationType === 'estate',
                                        },
                                    ]"
                                    @click="selectType('estate')"
                                >
                                    <div
                                        class="tc-check-ring"
                                        v-if="organisationType === 'estate'"
                                    >
                                        ✓
                                    </div>
                                    <div class="tc-icon">🏢</div>
                                    <div class="tc-name">Estate / Complex</div>
                                    <div class="tc-desc">
                                        HOA · Gated estate · Complex
                                    </div>
                                    <div class="tc-earn">
                                        Earn R30/unit/month
                                    </div>
                                </button>
                            </div>
                            <p
                                v-if="errors.organisation_type"
                                class="field-error"
                            >
                                {{ errors.organisation_type[0] }}
                            </p>
                        </div>

                        <!-- STEP 2: FORM FIELDS (revealed after type selected) -->
                        <Transition name="reveal">
                            <div v-if="organisationType" class="form-fields">
                                <div class="form-section">
                                    <div class="form-section-label">
                                        Your details
                                    </div>
                                    <div class="reg-row">
                                        <div class="reg-field">
                                            <label class="reg-label" for="name"
                                                >Full Name</label
                                            >
                                            <input
                                                v-model="form.name"
                                                id="name"
                                                type="text"
                                                class="reg-input"
                                                placeholder="John Dlamini"
                                                required
                                                autocomplete="name"
                                            />
                                            <InputError
                                                :message="errors.name?.[0]"
                                                class="field-error"
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
                                                autocomplete="tel"
                                            />
                                            <InputError
                                                :message="errors.phone?.[0]"
                                                class="field-error"
                                            />
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <div class="form-section-label">
                                        Organisation
                                    </div>
                                    <div class="reg-field">
                                        <label
                                            class="reg-label"
                                            for="organisation_name"
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
                                        />
                                        <InputError
                                            :message="
                                                errors.organisation_name?.[0]
                                            "
                                            class="field-error"
                                        />
                                    </div>
                                </div>

                                <div class="form-section">
                                    <div class="form-section-label">
                                        Login credentials
                                    </div>
                                    <div
                                        class="reg-field"
                                        style="margin-bottom: 14px"
                                    >
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
                                            autocomplete="email"
                                        />
                                        <InputError
                                            :message="errors.email?.[0]"
                                            class="field-error"
                                        />
                                    </div>
                                    <div class="reg-row">
                                        <div class="reg-field">
                                            <label
                                                class="reg-label"
                                                for="password"
                                                >Password</label
                                            >
                                            <input
                                                v-model="form.password"
                                                id="password"
                                                type="password"
                                                class="reg-input"
                                                placeholder="Min. 8 characters"
                                                required
                                                autocomplete="new-password"
                                            />
                                            <InputError
                                                :message="errors.password?.[0]"
                                                class="field-error"
                                            />
                                        </div>
                                        <div class="reg-field">
                                            <label
                                                class="reg-label"
                                                for="password_confirmation"
                                                >Confirm Password</label
                                            >
                                            <input
                                                v-model="
                                                    form.password_confirmation
                                                "
                                                id="password_confirmation"
                                                type="password"
                                                class="reg-input"
                                                placeholder="Repeat password"
                                                required
                                                autocomplete="new-password"
                                            />
                                            <InputError
                                                :message="
                                                    errors
                                                        .password_confirmation?.[0]
                                                "
                                                class="field-error"
                                            />
                                        </div>
                                    </div>
                                </div>

                                <button
                                    type="submit"
                                    class="reg-submit"
                                    :disabled="isProcessing || !canSubmit"
                                >
                                    <span v-if="!isProcessing"
                                        >Create Account →</span
                                    >
                                    <span v-else class="reg-spinner"></span>
                                </button>

                                <p class="reg-login-link">
                                    Already have an account?
                                    <a href="/login">Sign in →</a>
                                </p>
                            </div>

                            <div v-else class="type-prompt">
                                <span>👆</span> Select your organisation type
                                above to continue
                            </div>
                        </Transition>
                    </form>

                    <p class="reg-terms">
                        By registering you agree to our
                        <a
                            href="https://policy.jaroworkspace.com"
                            target="_blank"
                            >Privacy Policy</a
                        >.
                    </p>
                </div>
            </template>
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

/* ── ROOT ─────────────────────────────────────────────────────────────── */
.reg-root {
    display: flex;
    align-items: flex-start;
    min-height: 100vh;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #fff;
}

/* ── LEFT PANEL ───────────────────────────────────────────────────────── */
.reg-left {
    width: 460px;
    flex-shrink: 0;
    background: #1c2333;
    position: sticky;
    top: 0;
    height: 100vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
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
    height: 100%;
    display: flex;
    flex-direction: column;
}
.reg-logo {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    margin-bottom: 44px;
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
.reg-left-body {
    flex: 1;
    overflow: hidden;
}
.reg-tagline {
    font-size: 22px;
    font-weight: 700;
    color: #fff;
    line-height: 1.35;
    letter-spacing: -0.5px;
    margin-bottom: 32px;
}

.reg-benefits {
    display: flex;
    flex-direction: column;
    gap: 20px;
    margin-bottom: 28px;
}
.rb {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}
.rb-icon {
    font-size: 18px;
    flex-shrink: 0;
    margin-top: 2px;
}
.rb-title {
    font-size: 13px;
    font-weight: 700;
    color: #e0eaff;
    margin-bottom: 2px;
}
.rb-desc {
    font-size: 12px;
    color: #5a7099;
    line-height: 1.5;
}

/* Split card */
.split-card {
    background: rgba(249, 115, 22, 0.1);
    border: 1.5px solid rgba(249, 115, 22, 0.25);
    border-radius: 16px;
    padding: 18px 20px;
}
.split-card-contact {
    background: rgba(255, 255, 255, 0.04);
    border-color: rgba(255, 255, 255, 0.1);
}
.split-card-label {
    font-size: 11px;
    font-weight: 700;
    color: #f97316;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 14px;
}
.split-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}
.split-contact-amounts {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}
.split-item {
    flex: 1;
    text-align: center;
    padding: 12px;
    border-radius: 12px;
}
.split-yours {
    background: rgba(249, 115, 22, 0.15);
    border: 1px solid rgba(249, 115, 22, 0.3);
}
.split-echo {
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.1);
}
.split-pct {
    font-size: 26px;
    font-weight: 800;
    color: #fff;
    line-height: 1;
    margin-bottom: 3px;
}
.split-yours .split-pct {
    color: #f97316;
}
.split-sub {
    font-size: 10px;
    color: #7890b8;
    margin-bottom: 3px;
}
.split-rand {
    font-size: 12px;
    font-weight: 700;
    color: #c8d8f0;
}
.split-divider {
    font-size: 18px;
    color: #2d3d5a;
    flex-shrink: 0;
}
.split-note {
    font-size: 11px;
    color: #4a5e7a;
    text-align: center;
}
.sca-item {
    flex: 1;
    text-align: center;
}
.sca-pct {
    font-size: 22px;
    font-weight: 800;
    color: #f97316;
    line-height: 1;
    margin-bottom: 3px;
}
.sca-label {
    font-size: 10px;
    color: #7890b8;
    margin-bottom: 2px;
}
.sca-rand {
    font-size: 11px;
    font-weight: 700;
    color: #c8d8f0;
}
.split-placeholder {
    background: rgba(255, 255, 255, 0.03);
    border: 1.5px dashed #2d3d5a;
    border-radius: 16px;
    padding: 18px 20px;
}
.sp-text {
    font-size: 12px;
    color: #4a5e7a;
    text-align: center;
    line-height: 1.6;
}

.reg-trust {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding-top: 20px;
    border-top: 1px solid #2d3d5a;
    flex-shrink: 0;
    margin-top: 20px;
}
.rt-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #4a5e7a;
    font-weight: 500;
}
.rt-item span {
    color: #f97316;
    font-weight: 700;
}

/* ── RIGHT PANEL ──────────────────────────────────────────────────────── */
.reg-right {
    flex: 1;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 52px 40px;
    overflow-y: auto;
    min-height: 100vh;
}

/* ── CONTACT WRAP ─────────────────────────────────────────────────────── */
.contact-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 48px 0;
    max-width: 460px;
    width: 100%;
}
.contact-icon {
    font-size: 56px;
    margin-bottom: 20px;
}
.contact-title {
    font-size: 28px;
    font-weight: 800;
    color: #111;
    letter-spacing: -1px;
    margin-bottom: 12px;
}
.contact-sub {
    font-size: 15px;
    color: #666;
    line-height: 1.75;
    margin-bottom: 36px;
}
.contact-sub strong {
    color: #111;
    font-weight: 700;
}
.contact-cards {
    display: flex;
    flex-direction: column;
    gap: 12px;
    width: 100%;
    margin-bottom: 28px;
}
.contact-card {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 18px 20px;
    border-radius: 14px;
    border: 1.5px solid #e5e5e5;
    background: #fff;
    text-decoration: none;
    transition: all 0.2s;
    text-align: left;
}
.contact-card:hover {
    border-color: #f97316;
    box-shadow: 0 4px 16px rgba(249, 115, 22, 0.08);
    transform: translateY(-1px);
}
.contact-card-green {
    border-color: #bbf7d0;
}
.contact-card-green:hover {
    border-color: #16a34a;
    box-shadow: 0 4px 16px rgba(22, 163, 74, 0.08);
}
.contact-card-icon {
    font-size: 26px;
    flex-shrink: 0;
}
.contact-card-title {
    font-size: 11px;
    font-weight: 600;
    color: #999;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 3px;
}
.contact-card-val {
    font-size: 14px;
    font-weight: 700;
    color: #111;
}
.contact-note {
    font-size: 13px;
    color: #888;
}
.contact-note a {
    color: #f97316;
    text-decoration: none;
    font-weight: 600;
}
.contact-note a:hover {
    text-decoration: underline;
}

/* ── FORM WRAP ────────────────────────────────────────────────────────── */
.reg-form-wrap {
    width: 100%;
    max-width: 540px;
}
.reg-form-header {
    margin-bottom: 36px;
}
.reg-title {
    font-size: 28px;
    font-weight: 800;
    color: #111;
    letter-spacing: -1px;
    margin-bottom: 6px;
}
.reg-sub {
    font-size: 14px;
    color: #888;
}
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
.reg-form {
    display: flex;
    flex-direction: column;
    gap: 28px;
}
.form-section {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.form-section-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: #bbb;
}

.type-cards {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.type-card {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 3px;
    padding: 18px;
    border: 1.5px solid #e5e5e5;
    border-radius: 16px;
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
    background: rgba(249, 115, 22, 0.04);
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
}
.tc-check-ring {
    position: absolute;
    top: 12px;
    right: 12px;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #f97316;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
}
.tc-icon {
    font-size: 24px;
    margin-bottom: 6px;
}
.tc-name {
    font-size: 14px;
    font-weight: 700;
    color: #111;
}
.tc-desc {
    font-size: 11px;
    color: #888;
}
.tc-earn {
    font-size: 11px;
    font-weight: 700;
    color: #f97316;
    margin-top: 6px;
    background: rgba(249, 115, 22, 0.08);
    padding: 3px 8px;
    border-radius: 100px;
}

.form-fields {
    display: flex;
    flex-direction: column;
    gap: 24px;
}
.reg-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
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
.field-error {
    font-size: 12px;
    color: #dc2626;
}

.reg-submit {
    width: 100%;
    padding: 14px;
    margin-top: 4px;
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

.reg-login-link {
    text-align: center;
    font-size: 13px;
    color: #888;
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
    margin-top: 16px;
}
.reg-terms a {
    color: #aaa;
    text-decoration: underline;
}
.type-prompt {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 20px;
    font-size: 14px;
    color: #aaa;
    background: #fafafa;
    border: 1.5px dashed #e5e5e5;
    border-radius: 12px;
}

/* ── SUCCESS ──────────────────────────────────────────────────────────── */
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
.ss-spin {
    width: 22px;
    height: 22px;
    border: 2px solid #e5e5e5;
    border-top-color: #f97316;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    flex-shrink: 0;
    display: inline-block;
}
.btn-primary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
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
.btn-primary:hover {
    background: #ea580c;
    transform: translateY(-1px);
}

/* ── ANIMATIONS ───────────────────────────────────────────────────────── */
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
.slide-enter-active,
.slide-leave-active {
    transition: all 0.3s ease;
}
.slide-enter-from {
    opacity: 0;
    transform: translateY(-10px);
}
.slide-leave-to {
    opacity: 0;
    transform: translateY(10px);
}
.reveal-enter-active {
    transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
}
.reveal-enter-from {
    opacity: 0;
    transform: translateY(-12px);
}

/* ── RESPONSIVE ───────────────────────────────────────────────────────── */
@media (max-width: 900px) {
    .reg-root {
        flex-direction: column;
    }
    .reg-left {
        width: 100%;
        position: relative;
        height: auto;
    }
    .reg-left-inner {
        padding: 32px 24px;
    }
    .reg-tagline {
        font-size: 18px;
        margin-bottom: 20px;
    }
    .reg-benefits {
        gap: 14px;
        margin-bottom: 20px;
    }
    .reg-trust {
        flex-direction: row;
        gap: 20px;
        padding-top: 16px;
    }
    .reg-right {
        padding: 32px 20px;
        min-height: auto;
        align-items: flex-start;
    }
}
@media (max-width: 560px) {
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
    .split-row {
        flex-direction: column;
    }
    .split-contact-amounts {
        flex-direction: column;
    }
    .split-divider {
        transform: rotate(90deg);
    }
}
</style>

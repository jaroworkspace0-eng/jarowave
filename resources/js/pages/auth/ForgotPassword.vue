<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { email } from '@/routes/password';
import { Form, Head } from '@inertiajs/vue3';

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head title="Forgot Password — Echo Link" />

    <div class="fp-root">
        <!-- LEFT PANEL -->
        <div class="fp-left">
            <div class="fp-left-inner">
                <a href="/" class="fp-logo">
                    <img
                        src="https://policy.jaroworkspace.com/echolink.png"
                        alt="Echo Link"
                    />
                    <span>Echo <em>Link</em></span>
                </a>

                <div class="fp-left-body">
                    <div class="fp-tagline">
                        Forgot your password?<br />No problem at all.
                    </div>

                    <div class="fp-steps">
                        <div class="fp-step">
                            <div class="fps-num">01</div>
                            <div>
                                <div class="fps-title">Enter your email</div>
                                <div class="fps-desc">
                                    The one you registered your Echo Link
                                    account with
                                </div>
                            </div>
                        </div>
                        <div class="fp-step">
                            <div class="fps-num">02</div>
                            <div>
                                <div class="fps-title">Check your inbox</div>
                                <div class="fps-desc">
                                    We'll send a secure reset link within a few
                                    seconds
                                </div>
                            </div>
                        </div>
                        <div class="fp-step">
                            <div class="fps-num">03</div>
                            <div>
                                <div class="fps-title">Set a new password</div>
                                <div class="fps-desc">
                                    Click the link and create a strong new
                                    password
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="fp-trust">
                    <div class="ft-item">
                        <span>✓</span> Reset link expires in 60 minutes
                    </div>
                    <div class="ft-item">
                        <span>✓</span> Secure &amp; encrypted
                    </div>
                    <div class="ft-item"><span>✓</span> SA hosted</div>
                </div>
            </div>
        </div>

        <!-- RIGHT PANEL -->
        <div class="fp-right">
            <div class="fp-form-wrap">
                <div class="fp-icon">🔐</div>

                <div class="fp-form-header">
                    <h1 class="fp-title">Reset your password</h1>
                    <p class="fp-sub">
                        Enter your email address and we'll send you a link to
                        reset your password.
                    </p>
                </div>

                <!-- Success status -->
                <div v-if="status" class="fp-status">
                    <span class="fp-status-ico">✓</span>
                    {{ status }}
                </div>

                <Form
                    v-bind="email.form()"
                    v-slot="{ errors, processing }"
                    class="fp-form"
                >
                    <div class="fp-field">
                        <label class="fp-label" for="email"
                            >Email Address</label
                        >
                        <input
                            id="email"
                            type="email"
                            class="fp-input"
                            name="email"
                            placeholder="you@example.com"
                            autofocus
                            autocomplete="off"
                        />
                        <InputError :message="errors.email" class="fp-error" />
                    </div>

                    <button
                        type="submit"
                        class="fp-submit"
                        :disabled="processing"
                        data-test="email-password-reset-link-button"
                    >
                        <span v-if="!processing">Send Reset Link →</span>
                        <span v-else class="fp-spinner"></span>
                    </button>
                </Form>

                <div class="fp-back">
                    Remembered your password?
                    <a href="/login">Sign in →</a>
                </div>

                <p class="fp-footer-note">
                    Echo Link Admin ·
                    <a href="https://policy.jaroworkspace.com" target="_blank"
                        >Privacy Policy</a
                    >
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

.fp-root {
    display: flex;
    min-height: 100vh;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #fff;
}

/* LEFT */
.fp-left {
    width: 440px;
    flex-shrink: 0;
    background: #1c2333;
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.fp-left::before {
    content: '';
    position: absolute;
    width: 480px;
    height: 480px;
    border-radius: 50%;
    background: radial-gradient(
        ellipse,
        rgba(249, 115, 22, 0.15) 0%,
        transparent 70%
    );
    bottom: -120px;
    left: -120px;
    pointer-events: none;
}

.fp-left::after {
    content: '';
    position: absolute;
    width: 280px;
    height: 280px;
    border-radius: 50%;
    background: radial-gradient(
        ellipse,
        rgba(249, 115, 22, 0.07) 0%,
        transparent 70%
    );
    top: -60px;
    right: -80px;
    pointer-events: none;
}

.fp-left-inner {
    position: relative;
    z-index: 1;
    padding: 40px;
    display: flex;
    flex-direction: column;
    height: 100%;
}

.fp-logo {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    margin-bottom: 44px;
    flex-shrink: 0;
}

.fp-logo img {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    object-fit: cover;
}
.fp-logo span {
    font-size: 19px;
    font-weight: 800;
    color: #fff;
    letter-spacing: -0.5px;
}
.fp-logo em {
    font-style: normal;
    color: #f97316;
}

.fp-left-body {
    flex: 1;
}

.fp-tagline {
    font-size: 24px;
    font-weight: 700;
    color: #fff;
    line-height: 1.35;
    letter-spacing: -0.5px;
    margin-bottom: 40px;
}

.fp-steps {
    display: flex;
    flex-direction: column;
    gap: 28px;
}

.fp-step {
    display: flex;
    align-items: flex-start;
    gap: 16px;
}

.fps-num {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: rgba(249, 115, 22, 0.12);
    border: 1px solid rgba(249, 115, 22, 0.25);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 800;
    color: #f97316;
    flex-shrink: 0;
    letter-spacing: 0.5px;
}

.fps-title {
    font-size: 14px;
    font-weight: 700;
    color: #e0eaff;
    margin-bottom: 4px;
}
.fps-desc {
    font-size: 12px;
    color: #4a5e7a;
    line-height: 1.5;
}

.fp-trust {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding-top: 32px;
    border-top: 1px solid #2d3d5a;
    flex-shrink: 0;
    margin-top: 40px;
}

.ft-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #4a5e7a;
    font-weight: 500;
}
.ft-item span {
    color: #f97316;
    font-weight: 700;
}

/* RIGHT */
.fp-right {
    flex: 1;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 48px 32px;
    min-height: 100vh;
}

.fp-form-wrap {
    width: 100%;
    max-width: 400px;
}

.fp-icon {
    font-size: 44px;
    margin-bottom: 20px;
    display: block;
    text-align: center;
}

.fp-form-header {
    margin-bottom: 28px;
    text-align: center;
}

.fp-title {
    font-size: 26px;
    font-weight: 800;
    color: #111;
    letter-spacing: -1px;
    margin-bottom: 10px;
}
.fp-sub {
    font-size: 14px;
    color: #888;
    line-height: 1.6;
}

/* Status */
.fp-status {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 12px;
    padding: 14px 16px;
    font-size: 13px;
    color: #16a34a;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 24px;
}

.fp-status-ico {
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

.fp-form {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.fp-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.fp-label {
    font-size: 13px;
    font-weight: 600;
    color: #333;
}

.fp-input {
    width: 100%;
    padding: 12px 14px;
    border: 1.5px solid #e5e5e5;
    border-radius: 10px;
    font-size: 14px;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    color: #111;
    background: #fff;
    transition: all 0.15s;
    outline: none;
    box-sizing: border-box;
}

.fp-input::placeholder {
    color: #bbb;
}
.fp-input:focus {
    border-color: #f97316;
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
}

.fp-error {
    font-size: 12px;
    color: #dc2626;
}

.fp-submit {
    width: 100%;
    padding: 13px;
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
    min-height: 48px;
    margin-top: 4px;
}

.fp-submit:hover:not(:disabled) {
    background: #ea580c;
    transform: translateY(-1px);
    box-shadow: 0 8px 28px rgba(249, 115, 22, 0.4);
}

.fp-submit:disabled {
    opacity: 0.55;
    cursor: not-allowed;
    transform: none;
}

.fp-spinner {
    width: 18px;
    height: 18px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: fpspin 0.7s linear infinite;
    display: inline-block;
}

@keyframes fpspin {
    to {
        transform: rotate(360deg);
    }
}

.fp-back {
    text-align: center;
    font-size: 13px;
    color: #888;
    margin-top: 20px;
}

.fp-back a {
    color: #f97316;
    text-decoration: none;
    font-weight: 600;
}
.fp-back a:hover {
    text-decoration: underline;
}

.fp-footer-note {
    text-align: center;
    font-size: 12px;
    color: #ccc;
    margin-top: 28px;
}

.fp-footer-note a {
    color: #bbb;
    text-decoration: underline;
}

/* RESPONSIVE */
@media (max-width: 860px) {
    .fp-root {
        flex-direction: column;
    }
    .fp-left {
        width: 100%;
    }
    .fp-left-inner {
        padding: 28px 24px;
    }
    .fp-tagline {
        font-size: 18px;
        margin-bottom: 28px;
    }
    .fp-steps {
        gap: 20px;
    }
    .fp-trust {
        flex-direction: row;
        flex-wrap: wrap;
        gap: 16px;
        padding-top: 16px;
        margin-top: 24px;
    }
    .fp-right {
        padding: 40px 20px;
        min-height: auto;
    }
}
</style>

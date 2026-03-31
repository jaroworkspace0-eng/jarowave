<script setup lang="ts">
import axios from 'axios';
import { ref } from 'vue';

const email = ref('');
const password = ref('');
const remember = ref(false);
const processing = ref(false);
const error = ref<string | null>(null);
const showPassword = ref(false);

async function login() {
    processing.value = true;
    error.value = null;

    try {
        const { data } = await axios.post(
            `${import.meta.env.VITE_APP_URL}/api/login`,
            { email: email.value, password: password.value, source: 'web' },
        );

        const token = data.token;
        localStorage.setItem('token', token);
        axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;

        const userRes = await axios.get('/api/user');
        localStorage.setItem('user', JSON.stringify(userRes.data));

        window.location.href = '/dashboard';
    } catch (err: any) {
        error.value =
            err.response?.data?.message ||
            'Invalid credentials. Please try again.';
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <div class="login-root">
        <!-- LEFT PANEL -->
        <div class="login-left">
            <div class="login-left-inner">
                <a href="/" class="login-logo">
                    <img
                        src="https://policy.jaroworkspace.com/echolink.png"
                        alt="Echo Link"
                    />
                    <span>Echo <em>Link</em></span>
                </a>

                <div class="login-left-body">
                    <div class="login-tagline">
                        Command your community<br />from one dashboard.
                    </div>

                    <div class="login-stats">
                        <div class="ls-item">
                            <div class="ls-val">500+</div>
                            <div class="ls-lbl">Personnel</div>
                        </div>
                        <div class="ls-sep"></div>
                        <div class="ls-item">
                            <div class="ls-val">99.9%</div>
                            <div class="ls-lbl">Uptime</div>
                        </div>
                        <div class="ls-sep"></div>
                        <div class="ls-item">
                            <div class="ls-val">24/7</div>
                            <div class="ls-lbl">Always On</div>
                        </div>
                    </div>

                    <div class="login-mockup">
                        <div class="lm-topbar">
                            <span class="lmd r"></span>
                            <span class="lmd y"></span>
                            <span class="lmd g"></span>
                            <span class="lm-label">JaroWave · Admin</span>
                        </div>

                        <div class="lm-stat-row">
                            <div class="lm-stat green">
                                <div class="lms-val">12</div>
                                <div class="lms-lbl">Online</div>
                            </div>
                            <div class="lm-stat orange">
                                <div class="lms-val">3</div>
                                <div class="lms-lbl">Channels</div>
                            </div>
                            <div class="lm-stat red">
                                <div class="lms-val">1</div>
                                <div class="lms-lbl">Alert</div>
                            </div>
                        </div>

                        <div class="lm-channels">
                            <div class="lm-ch lm-ch-active">
                                <span class="lmchd green"></span>
                                <div class="lmch-info">
                                    <div class="lmchn">Alpha Zone</div>
                                    <div class="lmchs">Transmitting</div>
                                </div>
                                <div class="lm-wave">
                                    <span
                                        v-for="i in 5"
                                        :key="i"
                                        :style="`--i:${i}`"
                                    ></span>
                                </div>
                            </div>
                            <div class="lm-ch">
                                <span class="lmchd orange"></span>
                                <div class="lmch-info">
                                    <div class="lmchn">Gate Security</div>
                                    <div class="lmchs">Standby</div>
                                </div>
                                <span class="lmch-cnt">3</span>
                            </div>
                            <div class="lm-ch">
                                <span class="lmchd gray"></span>
                                <div class="lmch-info">
                                    <div class="lmchn">Patrol B</div>
                                    <div class="lmchs">Idle</div>
                                </div>
                                <span class="lmch-cnt">4</span>
                            </div>
                        </div>

                        <div class="lm-alert">
                            <span class="lm-alert-ico">🚨</span>
                            <div>
                                <div class="lma-title">
                                    Panic Alert · Gate 3
                                </div>
                                <div class="lma-sub">
                                    Responder en route · 2 min ETA
                                </div>
                            </div>
                            <div class="lma-pulse"></div>
                        </div>
                    </div>
                </div>

                <div class="login-trust">
                    <div class="lt-item">
                        <span>✓</span> SA hosted &amp; secured
                    </div>
                    <div class="lt-item">
                        <span>✓</span> Token-authenticated
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT PANEL -->
        <div class="login-right">
            <div class="login-form-wrap">
                <div class="login-form-header">
                    <h1 class="login-title">Dashboard Sign In</h1>
                    <p class="login-sub">Access your command dashboard</p>
                </div>

                <form @submit.prevent="login" class="login-form">
                    <div v-if="error" class="login-error-banner">
                        <span>⚠</span> {{ error }}
                    </div>

                    <div class="login-field">
                        <label class="login-label" for="email"
                            >Email Address</label
                        >
                        <input
                            id="email"
                            type="text"
                            class="login-input"
                            v-model="email"
                            placeholder="admin@example.com"
                            required
                            autofocus
                            autocomplete="email"
                        />
                    </div>

                    <div class="login-field">
                        <div class="login-label-row">
                            <label class="login-label" for="password"
                                >Password</label
                            >
                            <a href="/forgot-password" class="login-forgot"
                                >Forgot password?</a
                            >
                        </div>
                        <div class="login-input-wrap">
                            <input
                                id="password"
                                :type="showPassword ? 'text' : 'password'"
                                class="login-input login-input-pw"
                                v-model="password"
                                placeholder="Your password"
                                required
                                autocomplete="current-password"
                            />
                            <button
                                type="button"
                                class="pw-toggle"
                                @click="showPassword = !showPassword"
                                tabindex="-1"
                            >
                                {{ showPassword ? '🙈' : '👁️' }}
                            </button>
                        </div>
                    </div>

                    <div class="login-remember">
                        <label class="remember-label">
                            <div
                                class="remember-track"
                                :class="{ on: remember }"
                                @click="remember = !remember"
                            >
                                <div class="remember-thumb"></div>
                            </div>
                            <span>Keep me signed in</span>
                        </label>
                    </div>

                    <button
                        type="submit"
                        class="login-submit"
                        :disabled="processing"
                    >
                        <span v-if="!processing">Sign In →</span>
                        <span v-else class="login-spinner"></span>
                    </button>

                    <p class="login-register-link">
                        Don't have an account?
                        <a href="/register">Create one free →</a>
                    </p>
                </form>

                <p class="login-footer-note">
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

.login-root {
    display: flex;
    min-height: 100vh;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #fff;
}

/* LEFT */
.login-left {
    width: 440px;
    flex-shrink: 0;
    background: #1c2333;
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.login-left::before {
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

.login-left::after {
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

.login-left-inner {
    position: relative;
    z-index: 1;
    padding: 40px;
    display: flex;
    flex-direction: column;
    height: 100%;
}

.login-logo {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    margin-bottom: 44px;
    flex-shrink: 0;
}

.login-logo img {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    object-fit: cover;
}
.login-logo span {
    font-size: 19px;
    font-weight: 800;
    color: #fff;
    letter-spacing: -0.5px;
}
.login-logo em {
    font-style: normal;
    color: #f97316;
}

.login-left-body {
    flex: 1;
}

.login-tagline {
    font-size: 24px;
    font-weight: 700;
    color: #fff;
    line-height: 1.35;
    letter-spacing: -0.5px;
    margin-bottom: 24px;
}

.login-stats {
    display: flex;
    align-items: center;
    background: #243047;
    border: 1px solid #2d3d5a;
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 18px;
}

.ls-item {
    text-align: center;
    flex: 1;
}
.ls-val {
    font-size: 18px;
    font-weight: 800;
    color: #f97316;
    margin-bottom: 2px;
}
.ls-lbl {
    font-size: 10px;
    color: #4a5e7a;
    font-weight: 600;
}
.ls-sep {
    width: 1px;
    height: 28px;
    background: #2d3d5a;
}

.login-mockup {
    background: #243047;
    border: 1px solid #2d3d5a;
    border-radius: 14px;
    padding: 14px;
}

.lm-topbar {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid #2d3d5a;
}

.lmd {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}
.lmd.r {
    background: #ff5f57;
}
.lmd.y {
    background: #febc2e;
}
.lmd.g {
    background: #28c840;
}
.lm-label {
    margin-left: auto;
    font-size: 10px;
    color: #4a5e7a;
}

.lm-stat-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
    margin-bottom: 10px;
}

.lm-stat {
    border-radius: 8px;
    padding: 7px 6px;
    text-align: center;
}
.lm-stat.green {
    background: rgba(34, 197, 94, 0.08);
    border: 1px solid rgba(34, 197, 94, 0.2);
}
.lm-stat.orange {
    background: rgba(249, 115, 22, 0.08);
    border: 1px solid rgba(249, 115, 22, 0.2);
}
.lm-stat.red {
    background: rgba(220, 38, 38, 0.08);
    border: 1px solid rgba(220, 38, 38, 0.2);
}

.lms-val {
    font-size: 15px;
    font-weight: 800;
}
.lm-stat.green .lms-val {
    color: #22c55e;
}
.lm-stat.orange .lms-val {
    color: #f97316;
}
.lm-stat.red .lms-val {
    color: #f87171;
}
.lms-lbl {
    font-size: 9px;
    color: #4a5e7a;
    font-weight: 600;
}

.lm-channels {
    display: flex;
    flex-direction: column;
    gap: 5px;
    margin-bottom: 10px;
}

.lm-ch {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 6px 8px;
    background: #1c2333;
    border: 1px solid #2d3d5a;
    border-radius: 8px;
}

.lm-ch-active {
    background: rgba(249, 115, 22, 0.07);
    border-color: rgba(249, 115, 22, 0.2);
}

.lmchd {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
}
.lmchd.green {
    background: #22c55e;
}
.lmchd.orange {
    background: #f97316;
}
.lmchd.gray {
    background: #2d3d5a;
}

.lmchn {
    font-size: 11px;
    font-weight: 600;
    color: #c8d8f0;
}
.lmchs {
    font-size: 9px;
    color: #4a5e7a;
}
.lmch-cnt {
    margin-left: auto;
    font-size: 10px;
    color: #4a5e7a;
    font-weight: 600;
}

.lm-wave {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 2px;
    height: 12px;
}
.lm-wave span {
    width: 2px;
    background: #f97316;
    border-radius: 1px;
    animation: lmwv 1.2s ease-in-out infinite;
    animation-delay: calc(var(--i) * 0.1s);
}

@keyframes lmwv {
    0%,
    100% {
        height: 2px;
        opacity: 0.3;
    }
    50% {
        height: 10px;
        opacity: 1;
    }
}

.lm-alert {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(220, 38, 38, 0.08);
    border: 1px solid rgba(220, 38, 38, 0.18);
    border-radius: 8px;
    padding: 8px 10px;
}

.lm-alert-ico {
    font-size: 13px;
    flex-shrink: 0;
}
.lma-title {
    font-size: 11px;
    font-weight: 700;
    color: #f87171;
}
.lma-sub {
    font-size: 9px;
    color: #4a5e7a;
}

.lma-pulse {
    margin-left: auto;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #dc2626;
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.2);
    animation: lmpulse 1.5s ease-in-out infinite;
    flex-shrink: 0;
}

@keyframes lmpulse {
    0%,
    100% {
        box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.2);
    }
    50% {
        box-shadow: 0 0 0 6px rgba(220, 38, 38, 0.06);
    }
}

.login-trust {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding-top: 18px;
    border-top: 1px solid #2d3d5a;
    flex-shrink: 0;
}

.lt-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #4a5e7a;
    font-weight: 500;
}
.lt-item span {
    color: #f97316;
    font-weight: 700;
}

/* RIGHT */
.login-right {
    flex: 1;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 32px;
}

.login-form-wrap {
    width: 100%;
    max-width: 400px;
}

.login-form-header {
    margin-bottom: 32px;
}

.login-title {
    font-size: 26px;
    font-weight: 800;
    color: #111;
    letter-spacing: -1px;
    margin-bottom: 6px;
}
.login-sub {
    font-size: 14px;
    color: #888;
}

.login-form {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.login-error-banner {
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 10px;
    padding: 11px 14px;
    font-size: 13px;
    color: #dc2626;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 8px;
}

.login-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.login-label {
    font-size: 13px;
    font-weight: 600;
    color: #333;
}

.login-label-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.login-forgot {
    font-size: 12px;
    font-weight: 600;
    color: #f97316;
    text-decoration: none;
    transition: color 0.15s;
}

.login-forgot:hover {
    color: #ea580c;
    text-decoration: underline;
}

.login-register-link {
    text-align: center;
    font-size: 13px;
    color: #888;
    margin: 0;
}

.login-register-link a {
    color: #f97316;
    text-decoration: none;
    font-weight: 600;
}

.login-register-link a:hover {
    text-decoration: underline;
}

.login-input {
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

.login-input::placeholder {
    color: #bbb;
}
.login-input:focus {
    border-color: #f97316;
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
}

.login-input-wrap {
    position: relative;
}
.login-input-pw {
    padding-right: 44px;
}

.pw-toggle {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    cursor: pointer;
    font-size: 14px;
    padding: 0;
    opacity: 0.5;
    transition: opacity 0.15s;
}

.pw-toggle:hover {
    opacity: 1;
}

.login-remember {
    display: flex;
    align-items: center;
}

.remember-label {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    font-size: 13px;
    color: #666;
    font-weight: 500;
    user-select: none;
}

.remember-track {
    width: 38px;
    height: 21px;
    border-radius: 100px;
    background: #e5e5e5;
    position: relative;
    transition: background 0.2s;
    cursor: pointer;
    flex-shrink: 0;
}

.remember-track.on {
    background: #f97316;
}

.remember-thumb {
    width: 15px;
    height: 15px;
    border-radius: 50%;
    background: #fff;
    position: absolute;
    top: 3px;
    left: 3px;
    transition: transform 0.2s;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.2);
}

.remember-track.on .remember-thumb {
    transform: translateX(17px);
}

.login-submit {
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

.login-submit:hover:not(:disabled) {
    background: #ea580c;
    transform: translateY(-1px);
    box-shadow: 0 8px 28px rgba(249, 115, 22, 0.4);
}

.login-submit:disabled {
    opacity: 0.55;
    cursor: not-allowed;
    transform: none;
}

.login-spinner {
    width: 18px;
    height: 18px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: lspin 0.7s linear infinite;
    display: inline-block;
}

@keyframes lspin {
    to {
        transform: rotate(360deg);
    }
}

.login-footer-note {
    text-align: center;
    font-size: 12px;
    color: #ccc;
    margin-top: 28px;
}

.login-footer-note a {
    color: #bbb;
    text-decoration: underline;
}

/* RESPONSIVE */
@media (max-width: 860px) {
    .login-root {
        flex-direction: column;
    }
    .login-left {
        width: 100%;
    }
    .login-left-inner {
        padding: 28px 24px;
    }
    .login-tagline {
        font-size: 18px;
        margin-bottom: 16px;
    }
    .login-mockup {
        display: none;
    }
    .login-stats {
        margin-bottom: 16px;
    }
    .login-trust {
        flex-direction: row;
        flex-wrap: wrap;
        gap: 16px;
        padding-top: 14px;
    }
    .login-right {
        padding: 32px 20px;
    }
}
</style>

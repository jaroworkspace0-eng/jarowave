<script setup lang="ts">
import PasswordController from '@/actions/App/Http/Controllers/Settings/PasswordController';
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { edit } from '@/routes/user-password';
import { Form, Head } from '@inertiajs/vue3';

import { type BreadcrumbItem } from '@/types';

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Password settings',
        href: edit().url,
    },
];
</script>

<template>
    <AppLayout>
        <Head title="Password settings" />

        <SettingsLayout>
            <div class="settings-card">
                <div class="settings-block__header">
                    <div class="settings-block__eyebrow">Account</div>
                    <h2 class="settings-block__title">Update password</h2>
                    <p class="settings-block__sub">
                        Ensure your account is using a long, random password to
                        stay secure
                    </p>
                </div>

                <Form
                    v-bind="PasswordController.update.form()"
                    :options="{
                        preserveScroll: true,
                    }"
                    reset-on-success
                    :reset-on-error="[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]"
                    class="settings-form"
                    v-slot="{ errors, processing, recentlySuccessful }"
                >
                    <div class="field">
                        <label for="current_password" class="field__label"
                            >Current password</label
                        >
                        <input
                            id="current_password"
                            name="current_password"
                            type="password"
                            class="field__input"
                            autocomplete="current-password"
                            placeholder="Current password"
                        />
                        <InputError
                            class="mt-2"
                            :message="errors.current_password"
                        />
                    </div>

                    <div class="field">
                        <label for="password" class="field__label"
                            >New password</label
                        >
                        <input
                            id="password"
                            name="password"
                            type="password"
                            class="field__input"
                            autocomplete="new-password"
                            placeholder="New password"
                        />
                        <InputError class="mt-2" :message="errors.password" />
                    </div>

                    <div class="field">
                        <label for="password_confirmation" class="field__label"
                            >Confirm password</label
                        >
                        <input
                            id="password_confirmation"
                            name="password_confirmation"
                            type="password"
                            class="field__input"
                            autocomplete="new-password"
                            placeholder="Confirm password"
                        />
                        <InputError
                            class="mt-2"
                            :message="errors.password_confirmation"
                        />
                    </div>

                    <div class="settings-form__actions">
                        <button
                            type="submit"
                            class="btn-primary"
                            :disabled="processing"
                            data-test="update-password-button"
                        >
                            Save password
                        </button>

                        <Transition
                            enter-active-class="transition ease-in-out"
                            enter-from-class="opacity-0"
                            leave-active-class="transition ease-in-out"
                            leave-to-class="opacity-0"
                        >
                            <p v-show="recentlySuccessful" class="saved-text">
                                Saved.
                            </p>
                        </Transition>
                    </div>
                </Form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap');

.settings-block__header {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.settings-block__eyebrow {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: #ea580c;
}
.settings-block__title {
    font-size: 18px;
    font-weight: 700;
    color: #1a2332;
    margin: 2px 0 0;
    letter-spacing: -0.2px;
}
.settings-block__sub {
    font-size: 13px;
    color: #64748b;
    margin: 4px 0 0;
}

.settings-card {
    background: #ffffff;
    border: 1px solid #e4e8ef;
    border-radius: 16px;
    box-shadow:
        0 1px 3px rgba(0, 0, 0, 0.06),
        0 1px 2px rgba(0, 0, 0, 0.04);
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 22px;
    font-family: 'DM Sans', system-ui, sans-serif;
}

.settings-form {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.field__label {
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    letter-spacing: 0.3px;
}
.field__input {
    width: 100%;
    box-sizing: border-box;
    background: #f8fafc;
    border: 1.5px solid #e4e8ef;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 14px;
    font-family: inherit;
    color: #1a2332;
    outline: none;
    transition:
        border-color 0.15s,
        background 0.15s;
}
.field__input:focus {
    border-color: #ea580c;
    background: #fff;
}

.settings-form__actions {
    display: flex;
    align-items: center;
    gap: 14px;
}

.btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #ea580c;
    color: #ffffff;
    border: none;
    border-radius: 12px;
    padding: 10px 22px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.18s;
    box-shadow: 0 2px 8px rgba(234, 88, 12, 0.3);
    white-space: nowrap;
    font-family: 'DM Sans', system-ui, sans-serif;
}
.btn-primary:hover:not(:disabled) {
    background: #c2410c;
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(234, 88, 12, 0.35);
}
.btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

.saved-text {
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
    margin: 0;
}
</style>

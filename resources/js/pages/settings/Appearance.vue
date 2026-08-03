<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import AppearanceTabs from '@/components/AppearanceTabs.vue';
import { type BreadcrumbItem } from '@/types';

import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { edit } from '@/routes/appearance';

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Appearance settings',
        href: edit().url,
    },
];
</script>

<template>
    <AppLayout>
        <Head title="Appearance settings" />

        <SettingsLayout>
            <div class="settings-card">
                <div class="settings-block__header">
                    <div class="settings-block__eyebrow">Account</div>
                    <h2 class="settings-block__title">Appearance settings</h2>
                    <p class="settings-block__sub">
                        Update your account's appearance settings
                    </p>
                </div>

                <AppearanceTabs />
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
</style>

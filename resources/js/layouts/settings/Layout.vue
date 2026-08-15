<script setup lang="ts">
import { toUrl, urlIsActive } from '@/lib/utils';
import { edit as editAddress } from '@/routes/address';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editPassword } from '@/routes/user-password';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: editProfile(),
    },
    {
        title: 'Address',
        href: editAddress(),
    },
    {
        title: 'Password',
        href: editPassword(),
    },
    // {
    //     title: 'Two-Factor Auth',
    //     href: show(),
    // },
    {
        title: 'Appearance',
        href: editAppearance(),
    },
];
const currentPath = typeof window !== undefined ? window.location.pathname : '';
</script>

<template>
    <div class="page-root">
        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="page-header__left">
                <div class="page-header__eyebrow">Account</div>
                <h1 class="page-header__title">Settings</h1>
                <p class="page-header__sub">
                    Manage your profile and account settings
                </p>
            </div>
        </div>

        <!-- SETTINGS LAYOUT -->
        <div class="settings-layout">
            <aside class="settings-nav">
                <Link
                    v-for="item in sidebarNavItems"
                    :key="toUrl(item.href)"
                    :href="item.href"
                    class="settings-nav__item"
                    :class="{
                        'settings-nav__item--active': urlIsActive(
                            item.href,
                            currentPath,
                        ),
                    }"
                >
                    <component
                        :is="item.icon"
                        v-if="item.icon"
                        :size="15"
                        stroke-width="2"
                    />
                    {{ item.title }}
                </Link>
            </aside>

            <div class="settings-content">
                <slot />
            </div>
        </div>
    </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap');

.page-root {
    --c-bg: #f4f6f9;
    --c-surface: #ffffff;
    --c-border: #e4e8ef;
    --c-text: #1a2332;
    --c-muted: #64748b;
    --c-faint: #94a3b8;
    --c-primary: #ea580c;
    --c-primary-h: #c2410c;
    font-family: 'DM Sans', system-ui, sans-serif;
    padding: 28px 32px;
    display: flex;
    flex-direction: column;
    gap: 20px;
    min-height: 100%;
    background: #f4f6f9;
}

/* PAGE HEADER */
.page-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
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
.page-header__sub {
    font-size: 13px;
    color: #64748b;
    margin: 4px 0 0;
}

/* LAYOUT */
.settings-layout {
    display: flex;
    align-items: flex-start;
    gap: 32px;
    flex-wrap: wrap;
}

/* SIDE NAV */
.settings-nav {
    width: 100%;
    max-width: 192px;
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex-shrink: 0;
}
.settings-nav__item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 14px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    color: #475569;
    text-decoration: none;
    transition: all 0.15s;
}
.settings-nav__item:hover {
    background: #eef1f6;
    color: #1a2332;
}
.settings-nav__item--active {
    background: #ffffff;
    color: #1a2332;
    border: 1px solid #e4e8ef;
    box-shadow:
        0 1px 3px rgba(0, 0, 0, 0.06),
        0 1px 2px rgba(0, 0, 0, 0.04);
}

/* CONTENT */
.settings-content {
    flex: 1;
    min-width: 0;
    max-width: 640px;
    display: flex;
    flex-direction: column;
    gap: 24px;
}

/* RESPONSIVE */
@media (max-width: 900px) {
    .settings-layout {
        flex-direction: column;
    }
    .settings-nav {
        max-width: none;
        flex-direction: row;
        flex-wrap: wrap;
        gap: 6px;
    }
}
@media (max-width: 640px) {
    .page-root {
        padding: 16px;
    }
}
</style>

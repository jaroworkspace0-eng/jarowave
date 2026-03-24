<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { useAuthStore } from '@/stores/auth';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3'; // ← back to Inertia Link
import {
    ArrowsUpFromLineIcon,
    Briefcase,
    Building,
    HomeIcon,
    Megaphone,
} from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

const auth = useAuthStore();

const mainNavItems: NavItem[] = [
    { title: 'Dashboard', href: dashboard(), icon: HomeIcon },
    ...(auth.user?.role === 'admin'
        ? [{ title: 'Clients', href: '/clients', icon: Building }]
        : []),

    {
        title: 'Channels',
        href: '/channels',
        icon: ArrowsUpFromLineIcon,
    },
    { title: 'Personnels', href: '/employees', icon: Briefcase },
    ...(auth.user?.role === 'admin'
        ? [{ title: 'Announcements', href: '/announcements', icon: Megaphone }]
        : []),
];

const footerNavItems: NavItem[] = [];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <!-- ← Inertia Link uses href not to -->
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>

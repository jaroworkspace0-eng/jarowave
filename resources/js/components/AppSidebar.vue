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
    Building2,
    DollarSign,
    FileText,
    HomeIcon,
    Megaphone,
    Newspaper,
    Radio,
    RadioIcon,
    Shield, // ← add this
    Trash2,
} from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

const auth = useAuthStore();

const mainNavItems: NavItem[] = [
    ...(auth.user?.role === 'client' || auth.user?.role === 'admin'
        ? [
              { title: 'Dashboard', href: dashboard(), icon: HomeIcon },
              { title: 'Personnels', href: '/employees', icon: Briefcase },
          ]
        : []),

    ...(auth.user?.role === 'estate_billing'
        ? [
              {
                  title: 'Dashboard',
                  href: '/estate/dashboard',
                  icon: HomeIcon,
              },
              {
                  title: 'Invoices',
                  href: '/estate/invoices',
                  icon: FileText,
              },
          ]
        : []),

    ...(auth.user?.role === 'admin'
        ? [
              { title: 'Clients', href: '/clients', icon: Building },
              {
                  title: 'Channels',
                  href: '/channels',
                  icon: RadioIcon,
              },
          ]
        : []),

    // Estate users see Subscription + Invoices
    // ...(auth.user?.organisation_type === 'estate'
    //     ? [
    //           { title: 'Subscription', href: '/billing', icon: MonitorCheck },
    //           { title: 'Invoices', href: '/invoices', icon: Paperclip },
    //       ]
    //     : []),

    // Watch groups see Payouts
    ...(auth.user?.role === 'client'
        ? [{ title: 'Payouts', href: '/payouts', icon: DollarSign }]
        : []),

    ...(auth.user?.role === 'admin'
        ? [
              {
                  title: 'Process Payouts',
                  href: '/admin/process-payouts',
                  icon: ArrowsUpFromLineIcon,
              },
              {
                  title: 'Gate Guard Payouts',
                  href: '/admin/gate-guard-payouts',
                  icon: Shield, // or whatever icon fits
              },
              {
                  title: 'Estate Payments',
                  href: '/admin/estate-payments',
                  icon: Building2,
              },
              {
                  title: 'Incident Reports',
                  href: '/admin/incident-reports',
                  icon: Newspaper,
              },
              {
                  title: 'Guardian Reports',
                  href: '/guardian-reports',
                  icon: Newspaper,
              },

              {
                  title: 'Deletion Requests',
                  href: '/deletion-requests',
                  icon: Trash2,
              },
          ]
        : []),
    ...(auth.user?.role !== 'estate_billing'
        ? [
              {
                  title: 'Announcements',
                  href: '/announcements',
                  icon: Megaphone,
              },
              {
                  title: 'DV Recordings',
                  href: '/dv-recordings',
                  icon: Radio,
              },
          ]
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

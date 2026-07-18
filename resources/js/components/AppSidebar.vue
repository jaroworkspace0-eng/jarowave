<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { useAuthStore } from '@/stores/auth';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowsUpFromLineIcon,
    Briefcase,
    Building,
    Building2,
    ClipboardList,
    DollarSign,
    FileText,
    HomeIcon,
    Megaphone,
    Newspaper,
    Radio,
    RadioIcon,
    Shield,
    Ticket as TicketIcon,
    Trash2,
} from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

const auth = useAuthStore();

type NavGroup = {
    label: string;
    items: NavItem[];
};

const adminGroups: NavGroup[] = [
    {
        label: 'Overview',
        items: [
            { title: 'Dashboard', href: dashboard(), icon: HomeIcon },
            { title: 'Channels', href: '/channels', icon: RadioIcon },
        ],
    },
    {
        label: 'Live Monitoring',
        items: [
            { title: 'Live Alerts', href: '/live-alerts', icon: AlertTriangle },
        ],
    },
    {
        label: 'Operations',
        items: [
            { title: 'Clients', href: '/clients', icon: Building },
            { title: 'Personnels', href: '/employees', icon: Briefcase },
        ],
    },
    {
        label: 'Finance',
        items: [
            {
                title: 'Process Payouts',
                href: '/admin/process-payouts',
                icon: ArrowsUpFromLineIcon,
            },
            {
                title: 'Gate Guard Payouts',
                href: '/admin/gate-guard-payouts',
                icon: Shield,
            },
            {
                title: 'Estate Payments',
                href: '/admin/estate-payments',
                icon: Building2,
            },
        ],
    },
    {
        label: 'Reports',
        items: [
            {
                title: 'Incident Reports',
                href: '/admin/incident-reports',
                icon: Newspaper,
            },
            {
                title: 'Guardian Reports',
                href: '/guardian-reports',
                icon: ClipboardList,
            },
        ],
    },
    {
        label: 'Community',
        items: [
            { title: 'Announcements', href: '/announcements', icon: Megaphone },
            { title: 'DV Recordings', href: '/dv-recordings', icon: Radio },
        ],
    },
    {
        label: 'System',
        items: [
            {
                title: 'Deletion Requests',
                href: '/deletion-requests',
                icon: Trash2,
            },
        ],
    },
    {
        label: 'Support',
        items: [
            {
                title: 'Platform Tickets',
                href: '/admin/platform-tickets',
                icon: TicketIcon,
            },
        ],
    },
];

const clientGroups: NavGroup[] = [
    {
        label: 'Overview',
        items: [{ title: 'Dashboard', href: dashboard(), icon: HomeIcon }],
    },
    {
        label: 'Live Monitoring',
        items: [
            { title: 'Live Alerts', href: '/live-alerts', icon: AlertTriangle },
        ],
    },
    {
        label: 'Team',
        items: [{ title: 'Personnels', href: '/employees', icon: Briefcase }],
    },
    {
        label: 'Finance',
        items: [{ title: 'Payouts', href: '/payouts', icon: DollarSign }],
    },
    {
        label: 'Community',
        items: [
            { title: 'Announcements', href: '/announcements', icon: Megaphone },
            { title: 'DV Recordings', href: '/dv-recordings', icon: Radio },
        ],
    },
];

const estateBillingGroups: NavGroup[] = [
    {
        label: 'Overview',
        items: [
            { title: 'Dashboard', href: '/estate/dashboard', icon: HomeIcon },
        ],
    },
    {
        label: 'Live Monitoring',
        items: [
            { title: 'Live Alerts', href: '/live-alerts', icon: AlertTriangle },
        ],
    },
    {
        label: 'Finance',
        items: [
            { title: 'Invoices', href: '/estate/invoices', icon: FileText },
        ],
    },
    {
        label: 'Support',
        items: [
            { title: 'Tickets', href: '/estate/tickets', icon: TicketIcon },
        ],
    },
];

const navGroups = computed<NavGroup[]>(() => {
    if (auth.user?.role === 'admin') return adminGroups;
    if (auth.user?.role === 'estate_billing') return estateBillingGroups;
    if (auth.user?.role === 'client') return clientGroups;
    return [];
});

const footerNavItems: NavItem[] = [];
</script>

<script lang="ts">
import { computed } from 'vue';
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <template v-for="group in navGroups" :key="group.label">
                <SidebarGroup>
                    <SidebarGroupLabel>{{ group.label }}</SidebarGroupLabel>
                    <SidebarGroupContent>
                        <NavMain :items="group.items" />
                    </SidebarGroupContent>
                </SidebarGroup>
            </template>
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>

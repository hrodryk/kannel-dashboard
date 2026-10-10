<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ActivitySquare, AlertTriangle, Inbox, LayoutGrid } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as kannelEndpointsIndex } from '@/routes/kannel/endpoints';
import { index as kannelAlertRulesIndex } from '@/routes/kannel/alert-rules';
import { index as kannelStoreStatusIndex } from '@/routes/kannel/store-status';
import type { NavItem } from '@/types';

const page = usePage<{ props: { can_manage_kannel?: boolean | null } }>();

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
    ];

    if (page.props.can_manage_kannel) {
        items.push(
            {
                title: 'Kannel endpoints',
                href: kannelEndpointsIndex(),
                icon: ActivitySquare,
            },
            {
                title: 'Store Status (Fila)',
                href: kannelStoreStatusIndex(),
                icon: Inbox,
            },
            {
                title: 'Alert rules',
                href: kannelAlertRulesIndex(),
                icon: AlertTriangle,
            },
        );
    }

    return items;
});
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
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>

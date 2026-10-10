import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { BookOpen, ChefHat, Clock, CloudAlert, Computer, Folder, LayoutGrid, ReceiptText, ShoppingBag, Undo2, Users } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        url: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'Back Office',
        url: '/back-office',
        icon: Computer,
    },
    {
        title: 'Employee Management',
        url: '/employee-management',
        icon: Users,
    },
    {
        title: 'Shifts',
        url: '/shifts',
        icon: Clock,
    },
    {
        title: 'Tickets',
        url: '/tickets',
        icon: ReceiptText,
    },
    {
        title: 'Items Sold',
        url: '/sales',
        icon: ShoppingBag,
    },
    {
        title: 'Refunds',
        url: '/refunds',
        icon: Undo2,
    },
    {
        title: 'Kitchen Orders',
        url: '/kitchen-orders',
        icon: ChefHat,
    },
    {
        title: 'Sync Review',
        url: '/sync-issues',
        icon: CloudAlert,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        url: 'https://github.com/laravel/react-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        url: 'https://laravel.com/docs/starter-kits',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const { pendingRefunds, unreviewedSyncIssues } = usePage<SharedData>().props;
    const badges: Record<string, number | null | undefined> = { '/refunds': pendingRefunds, '/sync-issues': unreviewedSyncIssues };
    const items = mainNavItems.map((item) => (item.url in badges ? { ...item, badge: badges[item.url] } : item));

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

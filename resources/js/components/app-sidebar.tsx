import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { BookOpen, ChefHat, Clock, Computer, Folder, LayoutGrid, ReceiptText, ShoppingBag, Undo2, Users } from 'lucide-react';
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
    const { pendingRefunds } = usePage<SharedData>().props;
    const items = mainNavItems.map((item) => (item.url === '/refunds' ? { ...item, badge: pendingRefunds } : item));

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

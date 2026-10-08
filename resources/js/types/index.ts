import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    /** A count shown at the end of the item, e.g. pending refunds; hidden when empty or zero. */
    badge?: number | null;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    flash: { error?: string | null; success?: string | null };
    /** Refunds waiting for a passcode on the POS - set for managers/admins only. */
    pendingRefunds?: number | null;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    username: string;
    avatar?: string;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}

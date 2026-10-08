import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePoll } from '@inertiajs/react';
import { Check, ChefHat, ExternalLink } from 'lucide-react';
import { useEffect, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Back Office', href: route('back-office') },
    { title: 'Kitchen Orders', href: route('kitchen-orders.index') },
];

type KitchenItem = {
    ticket_item_id: number;
    item_name: string;
    quantity: number;
    notes: string | null;
    modifiers: { name: string }[];
};

type KitchenOrder = {
    ticket_id: number;
    order_number: string;
    customer_name: string;
    order_type: 'dine_in' | 'takeout';
    created_at: string;
    items: KitchenItem[];
};

/** How long an order may wait before its card turns amber, then red. */
const WARN_AFTER_MINUTES = 10;
const LATE_AFTER_MINUTES = 20;

function minutesWaiting(createdAt: string, now: number): number {
    return Math.max(0, Math.floor((now - new Date(createdAt).getTime()) / 60000));
}

function waitingClass(minutes: number): string {
    if (minutes >= LATE_AFTER_MINUTES) return 'bg-red-600 text-white';
    if (minutes >= WARN_AFTER_MINUTES) return 'bg-amber-500 text-white';

    return 'bg-muted text-muted-foreground';
}

export default function KitchenOrdersPage({ orders }: { orders: KitchenOrder[] }) {
    // Reverb isn't wired into the back office, so refresh the cards every few seconds instead.
    usePoll(5000, { only: ['orders'] });

    const [now, setNow] = useState(() => Date.now());
    const [bumping, setBumping] = useState<string | null>(null);

    useEffect(() => {
        const timer = setInterval(() => setNow(Date.now()), 15000);

        return () => clearInterval(timer);
    }, []);

    function bump(key: string, url: string) {
        if (bumping) return;

        setBumping(key);
        router.patch(url, {}, { preserveScroll: true, onFinish: () => setBumping(null) });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Kitchen Orders" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="border-sidebar-border/70 dark:border-sidebar-border flex items-center gap-3 rounded-xl border p-5">
                    <ChefHat className="text-muted-foreground size-5 shrink-0" />
                    <div>
                        <h2 className="font-semibold">Kitchen Orders</h2>
                        <p className="text-muted-foreground text-xs">
                            What the kitchen display shows right now, oldest first, refreshed every 5 seconds. Tap an item to bump it, or the customer
                            name to bump the whole order.
                        </p>
                    </div>
                </div>

                {orders.length === 0 ? (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground flex flex-1 items-center justify-center rounded-xl border border-dashed py-16 text-sm">
                        No orders in the kitchen.
                    </div>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                        {orders.map((order) => {
                            const minutes = minutesWaiting(order.created_at, now);
                            const ticketKey = `ticket-${order.ticket_id}`;

                            return (
                                <div
                                    key={order.ticket_id}
                                    className={`border-sidebar-border/70 dark:border-sidebar-border flex flex-col rounded-xl border ${
                                        bumping === ticketKey ? 'opacity-50' : ''
                                    }`}
                                >
                                    <div className="flex items-start justify-between gap-2 border-b px-4 py-3">
                                        <button
                                            type="button"
                                            onClick={() => bump(ticketKey, route('kitchen-orders.complete', order.ticket_id))}
                                            disabled={bumping !== null}
                                            title="Bump the whole order"
                                            className="hover:text-primary min-w-0 text-left"
                                        >
                                            <div className="truncate text-lg font-semibold">{order.customer_name}</div>
                                            <div className="text-muted-foreground text-xs">
                                                {order.order_number} · {order.order_type === 'dine_in' ? 'Dine in' : 'Takeout'}
                                            </div>
                                        </button>
                                        <div className="flex shrink-0 items-center gap-2">
                                            <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${waitingClass(minutes)}`}>
                                                {minutes} min
                                            </span>
                                            <Link
                                                href={route('tickets.show', order.ticket_id)}
                                                title="Open the ticket"
                                                className="text-muted-foreground hover:text-foreground"
                                            >
                                                <ExternalLink className="size-4" />
                                            </Link>
                                        </div>
                                    </div>
                                    <ul className="divide-y">
                                        {order.items.map((item) => {
                                            const itemKey = `item-${item.ticket_item_id}`;

                                            return (
                                                <li key={item.ticket_item_id}>
                                                    <button
                                                        type="button"
                                                        onClick={() => bump(itemKey, route('kitchen-orders.items.complete', item.ticket_item_id))}
                                                        disabled={bumping !== null}
                                                        title="Bump this item"
                                                        className={`hover:bg-muted group flex w-full items-start gap-3 px-4 py-2.5 text-left ${
                                                            bumping === itemKey ? 'opacity-50' : ''
                                                        }`}
                                                    >
                                                        <span className="w-8 shrink-0 font-semibold">{item.quantity}×</span>
                                                        <span className="min-w-0 flex-1">
                                                            <span className="font-medium">{item.item_name}</span>
                                                            {item.modifiers.length > 0 && (
                                                                <span className="text-muted-foreground block text-xs">
                                                                    {item.modifiers.map((modifier) => modifier.name).join(', ')}
                                                                </span>
                                                            )}
                                                            {item.notes && <span className="block text-xs text-amber-600 italic">{item.notes}</span>}
                                                        </span>
                                                        <Check className="text-muted-foreground size-4 shrink-0 opacity-0 group-hover:opacity-100" />
                                                    </button>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

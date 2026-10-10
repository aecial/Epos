import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, CloudOff, ReceiptText, Search, X } from 'lucide-react';
import { type FormEvent, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Back Office', href: route('back-office') },
    { title: 'Tickets', href: route('tickets.index') },
];

type TicketStatus = 'open' | 'paid' | 'cancelled' | 'merged';

type TicketRow = {
    id: number;
    shift_id: number;
    order_number: string;
    customer_name: string;
    order_type: 'dine_in' | 'takeout';
    status: TicketStatus;
    terminal_id: string;
    created_at: string;
    ended_at: string | null;
    created_by: string | null;
    total: number;
    items_count: number;
    payment_methods: ('cash' | 'gcash')[];
    created_offline: boolean;
    offline_label: string | null;
};

type Filters = {
    status?: TicketStatus;
    shift_id?: number | string;
    payment_method?: 'cash' | 'gcash';
    offline?: '1';
    date_from?: string;
    date_to?: string;
    search?: string;
};

type Pagination = { current_page: number; last_page: number; total: number };

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

/** Taken on a phone while the server was down; `label` is the number printed on its slips. */
function OfflineBadge({ label }: { label: string | null }) {
    return (
        <span className="inline-flex items-center gap-1 rounded-full border border-slate-400/60 px-2 py-0.5 text-xs font-medium text-slate-600 dark:text-slate-300">
            <CloudOff className="size-3" />
            Offline{label ? ` · ${label}` : ''}
        </span>
    );
}

function peso(value: number | null): string {
    return value === null ? '—' : `₱${value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

const statusClass: Record<TicketStatus, string> = {
    open: 'bg-blue-600',
    paid: 'bg-green-600',
    cancelled: 'bg-red-600',
    merged: 'bg-gray-500',
};

const paymentLabel: Record<'cash' | 'gcash', string> = { cash: 'Cash', gcash: 'GCash' };

const pagerLinkClass = 'hover:bg-muted inline-flex items-center gap-1 rounded-md border px-3 py-1.5 text-sm font-medium';

/** Drops empty values so the URL only carries the filters that are actually set. */
function cleanFilters(filters: Filters): Filters {
    return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== undefined && value !== '')) as Filters;
}

export default function TicketManagementPage({ tickets, filters, pagination }: { tickets: TicketRow[]; filters: Filters; pagination: Pagination }) {
    const [search, setSearch] = useState(filters.search ?? '');

    function applyFilters(changes: Filters) {
        router.get(
            route('tickets.index'),
            { ...cleanFilters({ ...filters, ...changes }) },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function submitSearch(event: FormEvent) {
        event.preventDefault();
        applyFilters({ search: search.trim() });
    }

    const hasFilters = Object.keys(cleanFilters(filters)).length > 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tickets" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <form onSubmit={submitSearch} className="flex flex-wrap items-end gap-3">
                    <label className="flex min-w-56 flex-1 flex-col gap-1 text-xs font-medium">
                        Search
                        <div className="relative">
                            <Search className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                            <input
                                type="search"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Order number, customer or receipt number, then Enter"
                                className="field pl-9"
                            />
                        </div>
                    </label>
                    <label className="flex flex-col gap-1 text-xs font-medium">
                        Status
                        <select
                            value={filters.status ?? ''}
                            onChange={(event) => applyFilters({ status: (event.target.value || undefined) as TicketStatus | undefined })}
                            className="field"
                        >
                            <option value="">All</option>
                            <option value="open">Open</option>
                            <option value="paid">Paid</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="merged">Merged</option>
                        </select>
                    </label>
                    <label className="flex flex-col gap-1 text-xs font-medium">
                        Payment
                        <select
                            value={filters.payment_method ?? ''}
                            onChange={(event) => applyFilters({ payment_method: (event.target.value || undefined) as Filters['payment_method'] })}
                            className="field"
                        >
                            <option value="">Any</option>
                            <option value="cash">Cash</option>
                            <option value="gcash">GCash</option>
                        </select>
                    </label>
                    <label className="flex flex-col gap-1 text-xs font-medium">
                        Taken
                        <select
                            value={filters.offline ?? ''}
                            onChange={(event) => applyFilters({ offline: (event.target.value || undefined) as Filters['offline'] })}
                            className="field"
                        >
                            <option value="">Online or offline</option>
                            <option value="1">Offline only</option>
                        </select>
                    </label>
                    <label className="flex flex-col gap-1 text-xs font-medium">
                        Opened from
                        <input
                            type="date"
                            value={filters.date_from ?? ''}
                            onChange={(event) => applyFilters({ date_from: event.target.value })}
                            className="field"
                        />
                    </label>
                    <label className="flex flex-col gap-1 text-xs font-medium">
                        Opened to
                        <input
                            type="date"
                            value={filters.date_to ?? ''}
                            onChange={(event) => applyFilters({ date_to: event.target.value })}
                            className="field"
                        />
                    </label>
                    {hasFilters && (
                        <Link
                            href={route('tickets.index')}
                            onClick={() => setSearch('')}
                            className="hover:bg-muted inline-flex items-center gap-1 rounded-md border px-3 py-2 text-sm font-medium"
                        >
                            <X className="size-4" />
                            Clear filters
                        </Link>
                    )}
                </form>

                <div className="border-sidebar-border/70 dark:border-sidebar-border relative max-h-[calc(100vh-14rem)] min-h-0 flex-1 overflow-auto rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead colSpan={9}>
                                    <div className="flex items-center gap-3 p-5">
                                        <ReceiptText className="text-muted-foreground size-5 shrink-0" />
                                        <div>
                                            <h2 className="font-semibold">Tickets</h2>
                                            <p className="text-muted-foreground text-xs">
                                                Every ticket from every terminal, newest first. Click one for its lines, payments and refunds.
                                                {filters.shift_id && (
                                                    <span className="ml-2 inline-flex items-center gap-1 rounded-full border px-2 py-0.5">
                                                        Shift #{filters.shift_id}
                                                        <button
                                                            type="button"
                                                            aria-label="Remove shift filter"
                                                            onClick={() => applyFilters({ shift_id: undefined })}
                                                        >
                                                            <X className="size-3" />
                                                        </button>
                                                    </span>
                                                )}
                                            </p>
                                        </div>
                                    </div>
                                </TableHead>
                            </TableRow>
                            <TableRow>
                                <TableHead>Order</TableHead>
                                <TableHead>Customer</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Opened</TableHead>
                                <TableHead>Closed</TableHead>
                                <TableHead>Cashier</TableHead>
                                <TableHead>Terminal</TableHead>
                                <TableHead>Payment</TableHead>
                                <TableHead className="text-right">Total</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {tickets.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={9} className="text-muted-foreground py-8 text-center text-sm">
                                        {hasFilters ? 'No tickets match these filters.' : 'No tickets yet.'}
                                    </TableCell>
                                </TableRow>
                            )}
                            {tickets.map((ticket) => (
                                <TableRow key={ticket.id} className="cursor-pointer" onClick={() => router.visit(route('tickets.show', ticket.id))}>
                                    <TableCell className="font-medium">
                                        <Link href={route('tickets.show', ticket.id)} onClick={(event) => event.stopPropagation()}>
                                            {ticket.order_number}
                                        </Link>
                                        {ticket.created_offline && (
                                            <div className="mt-1">
                                                <OfflineBadge label={ticket.offline_label} />
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <div>{ticket.customer_name}</div>
                                        <div className="text-muted-foreground text-xs">
                                            {ticket.order_type === 'dine_in' ? 'Dine in' : 'Takeout'} · {ticket.items_count}{' '}
                                            {ticket.items_count === 1 ? 'item' : 'items'}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <span
                                            className={`rounded-full px-3 py-1 text-xs font-medium text-white capitalize ${statusClass[ticket.status]}`}
                                        >
                                            {ticket.status}
                                        </span>
                                    </TableCell>
                                    <TableCell>{formatDate(ticket.created_at)}</TableCell>
                                    <TableCell>{formatDate(ticket.ended_at)}</TableCell>
                                    <TableCell>{ticket.created_by ?? '—'}</TableCell>
                                    <TableCell>{ticket.terminal_id}</TableCell>
                                    <TableCell>
                                        {ticket.payment_methods.length === 0
                                            ? '—'
                                            : ticket.payment_methods.map((method) => paymentLabel[method]).join(' + ')}
                                    </TableCell>
                                    <TableCell className="text-right">{peso(ticket.total)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
                {pagination.last_page > 1 && (
                    <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">
                            Page {pagination.current_page} of {pagination.last_page} · {pagination.total} tickets
                        </span>
                        <div className="flex gap-2">
                            {pagination.current_page > 1 && (
                                <Link
                                    href={route('tickets.index', { ...cleanFilters(filters), page: pagination.current_page - 1 })}
                                    className={pagerLinkClass}
                                >
                                    <ChevronLeft className="size-4" />
                                    Previous
                                </Link>
                            )}
                            {pagination.current_page < pagination.last_page && (
                                <Link
                                    href={route('tickets.index', { ...cleanFilters(filters), page: pagination.current_page + 1 })}
                                    className={pagerLinkClass}
                                >
                                    Next
                                    <ChevronRight className="size-4" />
                                </Link>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

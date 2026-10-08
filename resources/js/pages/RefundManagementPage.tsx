import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Hourglass, Search, Undo2, Users, X } from 'lucide-react';
import { type FormEvent, type ReactNode, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Back Office', href: route('back-office') },
    { title: 'Refunds', href: route('refunds.index') },
];

type RefundStatus = 'pending' | 'approved' | 'rejected';

type RefundRow = {
    id: number;
    status: RefundStatus;
    amount: number;
    payment_method: 'cash' | 'gcash' | null;
    receipt_number: string | null;
    reason: string | null;
    requested_at: string;
    requested_by: string | null;
    decided_at: string | null;
    decided_by: string | null;
    ticket: { id: number; order_number: string; customer_name: string } | null;
    items: { item_name: string | null; quantity: number; amount: number }[];
};

type Summary = {
    approved_count: number;
    approved_total: number;
    approved_cash: number;
    approved_gcash: number;
    rejected_count: number;
    rejected_total: number;
    pending_count: number;
    pending_total: number;
    by_requester: { name: string; requested: number; approved: number; approved_total: number; rejected: number; pending: number }[];
    by_decider: { name: string; approved: number; approved_total: number; rejected: number }[];
};

type Filters = {
    status?: RefundStatus;
    payment_method?: 'cash' | 'gcash';
    date_from?: string;
    date_to?: string;
    search?: string;
};

type Pagination = { current_page: number; last_page: number; total: number };

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

function peso(value: number): string {
    return `₱${value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/** "12 min", "3 h 5 min", "2 d 4 h" since the refund was requested. */
function waitingFor(requestedAt: string): string {
    const minutes = Math.max(0, Math.floor((Date.now() - new Date(requestedAt).getTime()) / 60000));

    if (minutes < 60) return `${minutes} min`;
    if (minutes < 60 * 24) return `${Math.floor(minutes / 60)} h ${minutes % 60} min`;

    return `${Math.floor(minutes / (60 * 24))} d ${Math.floor((minutes % (60 * 24)) / 60)} h`;
}

function itemsLine(refund: RefundRow): string {
    return refund.items.map((item) => `${item.quantity}× ${item.item_name ?? '—'}`).join(', ') || '—';
}

function cleanFilters(filters: Filters): Filters {
    return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== undefined && value !== '')) as Filters;
}

const panelClass = 'border-sidebar-border/70 dark:border-sidebar-border rounded-xl border';
const pagerLinkClass = 'hover:bg-muted inline-flex items-center gap-1 rounded-md border px-3 py-1.5 text-sm font-medium';
const paymentLabel: Record<'cash' | 'gcash', string> = { cash: 'Cash', gcash: 'GCash' };
const statusClass: Record<RefundStatus, string> = { pending: 'bg-amber-500', approved: 'bg-green-600', rejected: 'bg-red-600' };

function StatusBadge({ status }: { status: RefundStatus }) {
    return <span className={`rounded-full px-3 py-1 text-xs font-medium text-white capitalize ${statusClass[status]}`}>{status}</span>;
}

function TicketLink({ refund }: { refund: RefundRow }) {
    if (!refund.ticket) return <>—</>;

    return (
        <Link href={route('tickets.show', refund.ticket.id)} className="font-medium underline underline-offset-2">
            {refund.ticket.order_number} · {refund.ticket.customer_name}
        </Link>
    );
}

function SummaryCard({ label, value, hint }: { label: string; value: ReactNode; hint?: string }) {
    return (
        <div className={`${panelClass} p-4`}>
            <div className="text-muted-foreground text-xs">{label}</div>
            <div className="mt-1 text-xl font-semibold">{value}</div>
            {hint && <div className="text-muted-foreground mt-0.5 text-xs">{hint}</div>}
        </div>
    );
}

export default function RefundManagementPage({
    pending,
    refunds,
    summary,
    filters,
    pagination,
}: {
    pending: RefundRow[];
    refunds: RefundRow[];
    summary: Summary;
    filters: Filters;
    pagination: Pagination;
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const hasFilters = Object.keys(cleanFilters(filters)).length > 0;

    function applyFilters(changes: Filters) {
        router.get(
            route('refunds.index'),
            { ...cleanFilters({ ...filters, ...changes }) },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function submitSearch(event: FormEvent) {
        event.preventDefault();
        applyFilters({ search: search.trim() });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Refunds" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className={`${panelClass} flex items-center gap-3 p-5`}>
                    <Undo2 className="text-muted-foreground size-5 shrink-0" />
                    <div>
                        <h2 className="font-semibold">Refunds</h2>
                        <p className="text-muted-foreground text-xs">
                            Every refund from every shift. Refunds are approved or rejected on a POS terminal with a manager passcode — this page only
                            shows them.
                        </p>
                    </div>
                </div>

                {pending.length > 0 && (
                    <div className="rounded-xl border border-amber-500/50">
                        <div className="flex items-center gap-2 border-b border-amber-500/30 bg-amber-500/10 px-5 py-3">
                            <Hourglass className="size-4 text-amber-600" />
                            <h3 className="font-semibold">
                                Waiting for approval ({pending.length}) · {peso(pending.reduce((sum, refund) => sum + refund.amount, 0))}
                            </h3>
                            <span className="text-muted-foreground ml-auto text-xs">Approve or reject on a POS terminal</span>
                        </div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Waiting</TableHead>
                                    <TableHead>Ticket</TableHead>
                                    <TableHead>Items</TableHead>
                                    <TableHead>Reason</TableHead>
                                    <TableHead>Requested by</TableHead>
                                    <TableHead>Method</TableHead>
                                    <TableHead className="text-right">Amount</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {pending.map((refund) => (
                                    <TableRow key={refund.id}>
                                        <TableCell className="font-medium text-amber-700 dark:text-amber-400">
                                            {waitingFor(refund.requested_at)}
                                        </TableCell>
                                        <TableCell>
                                            <TicketLink refund={refund} />
                                        </TableCell>
                                        <TableCell className="text-xs">{itemsLine(refund)}</TableCell>
                                        <TableCell>{refund.reason ?? '—'}</TableCell>
                                        <TableCell>{refund.requested_by ?? '—'}</TableCell>
                                        <TableCell>{refund.payment_method ? paymentLabel[refund.payment_method] : '—'}</TableCell>
                                        <TableCell className="text-right font-medium">{peso(refund.amount)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

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
                            onChange={(event) => applyFilters({ status: (event.target.value || undefined) as RefundStatus | undefined })}
                            className="field"
                        >
                            <option value="">All</option>
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </label>
                    <label className="flex flex-col gap-1 text-xs font-medium">
                        Method
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
                        Requested from
                        <input
                            type="date"
                            value={filters.date_from ?? ''}
                            onChange={(event) => applyFilters({ date_from: event.target.value })}
                            className="field"
                        />
                    </label>
                    <label className="flex flex-col gap-1 text-xs font-medium">
                        Requested to
                        <input
                            type="date"
                            value={filters.date_to ?? ''}
                            onChange={(event) => applyFilters({ date_to: event.target.value })}
                            className="field"
                        />
                    </label>
                    {hasFilters && (
                        <Link
                            href={route('refunds.index')}
                            onClick={() => setSearch('')}
                            className="hover:bg-muted inline-flex items-center gap-1 rounded-md border px-3 py-2 text-sm font-medium"
                        >
                            <X className="size-4" />
                            Clear filters
                        </Link>
                    )}
                </form>

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <SummaryCard
                        label="Approved"
                        value={peso(summary.approved_total)}
                        hint={`${summary.approved_count} refunds · cash ${peso(summary.approved_cash)} · GCash ${peso(summary.approved_gcash)}`}
                    />
                    <SummaryCard label="Rejected" value={summary.rejected_count} hint={`${peso(summary.rejected_total)} asked for`} />
                    <SummaryCard label="Pending" value={summary.pending_count} hint={`${peso(summary.pending_total)} waiting`} />
                    <SummaryCard
                        label="Requests"
                        value={summary.approved_count + summary.rejected_count + summary.pending_count}
                        hint={hasFilters ? 'matching these filters (any status)' : 'all time'}
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <div className={panelClass}>
                        <div className="flex items-center gap-2 border-b px-5 py-3">
                            <Users className="text-muted-foreground size-4" />
                            <h3 className="font-semibold">Requested by</h3>
                        </div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Staff</TableHead>
                                    <TableHead className="text-right">Requests</TableHead>
                                    <TableHead className="text-right">Approved</TableHead>
                                    <TableHead className="text-right">Rejected</TableHead>
                                    <TableHead className="text-right">Pending</TableHead>
                                    <TableHead className="text-right">Refunded</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {summary.by_requester.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-muted-foreground py-6 text-center text-sm">
                                            No refund requests.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {summary.by_requester.map((person) => (
                                    <TableRow key={person.name}>
                                        <TableCell className="font-medium">{person.name}</TableCell>
                                        <TableCell className="text-right">{person.requested}</TableCell>
                                        <TableCell className="text-right">{person.approved}</TableCell>
                                        <TableCell className={`text-right ${person.rejected > 0 ? 'font-medium text-red-600' : ''}`}>
                                            {person.rejected}
                                        </TableCell>
                                        <TableCell className="text-right">{person.pending}</TableCell>
                                        <TableCell className="text-right">{peso(person.approved_total)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <div className={panelClass}>
                        <div className="flex items-center gap-2 border-b px-5 py-3">
                            <Users className="text-muted-foreground size-4" />
                            <h3 className="font-semibold">Decided by</h3>
                        </div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Manager / admin</TableHead>
                                    <TableHead className="text-right">Approved</TableHead>
                                    <TableHead className="text-right">Rejected</TableHead>
                                    <TableHead className="text-right">Refunded</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {summary.by_decider.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={4} className="text-muted-foreground py-6 text-center text-sm">
                                            No decided refunds.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {summary.by_decider.map((person) => (
                                    <TableRow key={person.name}>
                                        <TableCell className="font-medium">{person.name}</TableCell>
                                        <TableCell className="text-right">{person.approved}</TableCell>
                                        <TableCell className="text-right">{person.rejected}</TableCell>
                                        <TableCell className="text-right">{peso(person.approved_total)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </div>

                <div className={`${panelClass} overflow-auto`}>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Requested</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Ticket</TableHead>
                                <TableHead>Items</TableHead>
                                <TableHead>Reason</TableHead>
                                <TableHead>Method</TableHead>
                                <TableHead>Requested by</TableHead>
                                <TableHead>Decided</TableHead>
                                <TableHead className="text-right">Amount</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {refunds.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={9} className="text-muted-foreground py-8 text-center text-sm">
                                        {hasFilters ? 'No refunds match these filters.' : 'No refunds yet.'}
                                    </TableCell>
                                </TableRow>
                            )}
                            {refunds.map((refund) => (
                                <TableRow key={refund.id}>
                                    <TableCell>{formatDate(refund.requested_at)}</TableCell>
                                    <TableCell>
                                        <StatusBadge status={refund.status} />
                                    </TableCell>
                                    <TableCell>
                                        <TicketLink refund={refund} />
                                        {refund.receipt_number && <div className="text-muted-foreground text-xs">{refund.receipt_number}</div>}
                                    </TableCell>
                                    <TableCell className="text-xs">{itemsLine(refund)}</TableCell>
                                    <TableCell>{refund.reason ?? '—'}</TableCell>
                                    <TableCell>{refund.payment_method ? paymentLabel[refund.payment_method] : '—'}</TableCell>
                                    <TableCell>{refund.requested_by ?? '—'}</TableCell>
                                    <TableCell>
                                        {refund.decided_by ? (
                                            <>
                                                {refund.decided_by}
                                                <div className="text-muted-foreground text-xs">{formatDate(refund.decided_at)}</div>
                                            </>
                                        ) : (
                                            '—'
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right font-medium">{peso(refund.amount)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
                {pagination.last_page > 1 && (
                    <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">
                            Page {pagination.current_page} of {pagination.last_page} · {pagination.total} refunds
                        </span>
                        <div className="flex gap-2">
                            {pagination.current_page > 1 && (
                                <Link
                                    href={route('refunds.index', { ...cleanFilters(filters), page: pagination.current_page - 1 })}
                                    className={pagerLinkClass}
                                >
                                    <ChevronLeft className="size-4" />
                                    Previous
                                </Link>
                            )}
                            {pagination.current_page < pagination.last_page && (
                                <Link
                                    href={route('refunds.index', { ...cleanFilters(filters), page: pagination.current_page + 1 })}
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

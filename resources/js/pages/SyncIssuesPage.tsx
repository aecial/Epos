import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Check, ChevronLeft, ChevronRight, CloudAlert } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Back Office', href: route('back-office') },
    { title: 'Sync Review', href: route('sync-issues.index') },
];

type IssueType =
    | 'stock_short'
    | 'item_unavailable'
    | 'required_choice_missing'
    | 'price_changed'
    | 'charge_mismatch'
    | 'possible_double_payment'
    | 'offline_void'
    | 'starting_cash_conflict'
    | 'old_shift_joined'
    | 'clock_wrong'
    | 'synced_after_close'
    | 'receipt_number_taken'
    | 'rejected_action';

type Issue = {
    id: number;
    type: IssueType;
    message: string;
    details: Record<string, unknown> | null;
    created_at: string;
    device: { code: string; name: string } | null;
    user: string | null;
    shift: { id: number; opened_at: string } | null;
    ticket: { id: number; order_number: string; customer_name: string; offline_label: string | null } | null;
    reviewed_at: string | null;
    reviewed_by: string | null;
};

type Filters = { status: 'unreviewed' | 'reviewed' | 'all'; type: IssueType | null };

type Pagination = { current_page: number; last_page: number; total: number };

/** What each kind of note means, and what a manager usually does about it. */
const typeInfo: Record<IssueType, { label: string; tone: string; action: string }> = {
    stock_short: { label: 'Stock below zero', tone: 'bg-amber-500', action: 'Recount the stock and correct it on the item or raw material.' },
    item_unavailable: {
        label: 'Sold while off the menu',
        tone: 'bg-amber-500',
        action: 'The phone had an older menu. Check the item is really out, or turn it back on.',
    },
    required_choice_missing: {
        label: 'Required choice missing',
        tone: 'bg-amber-500',
        action: 'Ask the cashier which option was served (e.g. the size).',
    },
    price_changed: { label: 'Price differed', tone: 'bg-amber-500', action: 'The phone had an older menu; the price it charged was kept.' },
    charge_mismatch: { label: 'Payment differed', tone: 'bg-amber-500', action: 'The gap was booked as a discount. Check with the cashier.' },
    possible_double_payment: {
        label: 'Possible double payment',
        tone: 'bg-red-600',
        action: 'The ticket was already settled. Refund the customer on the POS if they paid twice.',
    },
    offline_void: { label: 'Removed without passcode', tone: 'bg-red-600', action: 'Check the reason with the cashier.' },
    starting_cash_conflict: {
        label: 'Starting cash differed',
        tone: 'bg-amber-500',
        action: 'Two phones started the shift with different drawer counts.',
    },
    old_shift_joined: { label: 'Old shift still open', tone: 'bg-amber-500', action: 'A shift from an earlier day was never closed. Close it.' },
    clock_wrong: { label: 'Phone clock wrong', tone: 'bg-slate-500', action: "Fix the phone's date and time." },
    synced_after_close: { label: 'After shift close', tone: 'bg-amber-500', action: "These sales aren't in the shift's closing totals." },
    receipt_number_taken: { label: 'Receipt number reused', tone: 'bg-slate-500', action: 'The server gave the payment a new receipt number.' },
    rejected_action: { label: "Couldn't be applied", tone: 'bg-red-600', action: 'Something done offline was refused. Redo it by hand if needed.' },
};

const panelClass = 'border-sidebar-border/70 dark:border-sidebar-border rounded-xl border';
const pagerLinkClass = 'hover:bg-muted inline-flex items-center gap-1 rounded-md border px-3 py-1.5 text-sm font-medium';

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

export default function SyncIssuesPage({
    issues,
    counts,
    filters,
    pagination,
}: {
    issues: Issue[];
    counts: Partial<Record<IssueType, number>>;
    filters: Filters;
    pagination: Pagination;
}) {
    const waiting = Object.values(counts).reduce((sum, count) => sum + (count ?? 0), 0);
    const query = { status: filters.status, ...(filters.type ? { type: filters.type } : {}) };

    function applyFilters(changes: Partial<Filters>) {
        const next = { ...filters, ...changes };
        router.get(
            route('sync-issues.index'),
            { status: next.status, ...(next.type ? { type: next.type } : {}) },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function markReviewed(issue: Issue) {
        router.patch(route('sync-issues.review', issue.id), {}, { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sync Review" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className={`${panelClass} flex items-center gap-3 p-5`}>
                    <CloudAlert className="text-muted-foreground size-5 shrink-0" />
                    <div>
                        <h2 className="font-semibold">Sync Review</h2>
                        <p className="text-muted-foreground text-xs">
                            Sales and changes made on a phone while the server was down, which the server accepted although something didn't add up.
                            The sale is already recorded — check each note, sort it out, then mark it reviewed.
                        </p>
                    </div>
                </div>

                {waiting > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {(Object.entries(counts) as [IssueType, number][]).map(([type, count]) => (
                            <button
                                key={type}
                                type="button"
                                onClick={() => applyFilters({ status: 'unreviewed', type: filters.type === type ? null : type })}
                                className={`inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium ${filters.type === type ? 'bg-muted' : 'hover:bg-muted'}`}
                            >
                                <span className={`size-2 rounded-full ${typeInfo[type].tone}`} />
                                {typeInfo[type].label} · {count}
                            </button>
                        ))}
                    </div>
                )}

                <div className="flex flex-wrap items-end gap-3">
                    <label className="flex flex-col gap-1 text-xs font-medium">
                        Show
                        <select
                            value={filters.status}
                            onChange={(event) => applyFilters({ status: event.target.value as Filters['status'] })}
                            className="field"
                        >
                            <option value="unreviewed">Needs review</option>
                            <option value="reviewed">Reviewed</option>
                            <option value="all">All</option>
                        </select>
                    </label>
                    <label className="flex flex-col gap-1 text-xs font-medium">
                        Kind
                        <select
                            value={filters.type ?? ''}
                            onChange={(event) => applyFilters({ type: (event.target.value || null) as IssueType | null })}
                            className="field"
                        >
                            <option value="">Any</option>
                            {(Object.keys(typeInfo) as IssueType[]).map((type) => (
                                <option key={type} value={type}>
                                    {typeInfo[type].label}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>

                <div className={`${panelClass} overflow-auto`}>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Synced</TableHead>
                                <TableHead>Kind</TableHead>
                                <TableHead>What happened</TableHead>
                                <TableHead>Ticket</TableHead>
                                <TableHead>Phone</TableHead>
                                <TableHead className="text-right">Review</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {issues.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={6} className="text-muted-foreground py-8 text-center text-sm">
                                        {filters.status === 'unreviewed' ? 'Nothing to review.' : 'No sync notes match these filters.'}
                                    </TableCell>
                                </TableRow>
                            )}
                            {issues.map((issue) => (
                                <TableRow key={issue.id}>
                                    <TableCell className="whitespace-nowrap">{formatDate(issue.created_at)}</TableCell>
                                    <TableCell>
                                        <span
                                            className={`rounded-full px-3 py-1 text-xs font-medium whitespace-nowrap text-white ${typeInfo[issue.type].tone}`}
                                        >
                                            {typeInfo[issue.type].label}
                                        </span>
                                    </TableCell>
                                    <TableCell className="max-w-md">
                                        <div>{issue.message}</div>
                                        <div className="text-muted-foreground mt-0.5 text-xs">{typeInfo[issue.type].action}</div>
                                    </TableCell>
                                    <TableCell>
                                        {issue.ticket ? (
                                            <>
                                                <Link
                                                    href={route('tickets.show', issue.ticket.id)}
                                                    className="font-medium underline underline-offset-2"
                                                >
                                                    {issue.ticket.order_number} · {issue.ticket.customer_name}
                                                </Link>
                                                {issue.ticket.offline_label && (
                                                    <div className="text-muted-foreground text-xs">Printed as {issue.ticket.offline_label}</div>
                                                )}
                                            </>
                                        ) : issue.shift ? (
                                            <Link href={route('shifts.show', issue.shift.id)} className="underline underline-offset-2">
                                                Shift of {new Date(issue.shift.opened_at).toLocaleDateString()}
                                            </Link>
                                        ) : (
                                            '—'
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {issue.device?.code ?? '—'}
                                        {issue.user && <div className="text-muted-foreground text-xs">{issue.user}</div>}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {issue.reviewed_at ? (
                                            <div className="text-xs">
                                                <span className="inline-flex items-center gap-1 text-green-700 dark:text-green-400">
                                                    <Check className="size-3" />
                                                    {issue.reviewed_by ?? 'Reviewed'}
                                                </span>
                                                <div className="text-muted-foreground">{formatDate(issue.reviewed_at)}</div>
                                            </div>
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={() => markReviewed(issue)}
                                                className="hover:bg-muted rounded-md border px-3 py-1.5 text-xs font-medium whitespace-nowrap"
                                            >
                                                Mark reviewed
                                            </button>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
                {pagination.last_page > 1 && (
                    <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">
                            Page {pagination.current_page} of {pagination.last_page} · {pagination.total} notes
                        </span>
                        <div className="flex gap-2">
                            {pagination.current_page > 1 && (
                                <Link href={route('sync-issues.index', { ...query, page: pagination.current_page - 1 })} className={pagerLinkClass}>
                                    <ChevronLeft className="size-4" />
                                    Previous
                                </Link>
                            )}
                            {pagination.current_page < pagination.last_page && (
                                <Link href={route('sync-issues.index', { ...query, page: pagination.current_page + 1 })} className={pagerLinkClass}>
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

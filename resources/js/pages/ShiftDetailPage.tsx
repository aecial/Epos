import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { Banknote, Clock, Printer, ReceiptText, Undo2 } from 'lucide-react';
import { type ReactNode } from 'react';

type Shift = {
    id: number;
    status: 'open' | 'closed';
    opened_at: string;
    closed_at: string | null;
    opened_by: string | null;
    closed_by: string | null;
    starting_cash: number;
    total_revenue: number | null;
    total_cash: number | null;
    total_gcash: number | null;
    total_additions: number | null;
    total_expenses: number | null;
    total_refunds: number | null;
    total_cash_refunds: number | null;
    expected_cash: number | null;
    closing_cash: number | null;
    discrepancy: number | null;
};

type Transaction = {
    id: number;
    type: 'expense' | 'addition';
    amount: number;
    reason: string;
    created_at: string;
    created_by: string | null;
};

type Refund = {
    id: number;
    amount: number;
    status: 'pending' | 'approved' | 'rejected';
    payment_method: 'cash' | 'gcash' | null;
    reason: string | null;
    requested_at: string;
    requested_by: string | null;
    approved_by: string | null;
};

type TicketCounts = { open: number; paid: number; cancelled: number; merged: number };

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

function peso(value: number | null): string {
    return value === null ? '—' : `₱${value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

const panelClass = 'border-sidebar-border/70 dark:border-sidebar-border rounded-xl border';

const refundStatusClass: Record<Refund['status'], string> = {
    pending: 'bg-amber-500',
    approved: 'bg-green-600',
    rejected: 'bg-red-600',
};

function PanelTitle({ icon, children }: { icon: ReactNode; children: ReactNode }) {
    return (
        <div className="flex items-center gap-2 border-b px-5 py-3">
            {icon}
            <h3 className="font-semibold">{children}</h3>
        </div>
    );
}

function LedgerRow({ label, value, sign, emphasis = false }: { label: string; value: number | null; sign?: '+' | '−'; emphasis?: boolean }) {
    return (
        <div className={`flex items-center justify-between px-5 py-2 text-sm ${emphasis ? 'border-t font-semibold' : ''}`}>
            <span className="text-muted-foreground">
                {sign && <span className="mr-2 inline-block w-3 text-center">{sign}</span>}
                {label}
            </span>
            <span>{peso(value)}</span>
        </div>
    );
}

export default function ShiftDetailPage({
    shift,
    transactions,
    refunds,
    ticketCounts,
}: {
    shift: Shift;
    transactions: Transaction[];
    refunds: Refund[];
    ticketCounts: TicketCounts;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Back Office', href: route('back-office') },
        { title: 'Shifts', href: route('shifts.index') },
        { title: `Shift #${shift.id}`, href: route('shifts.show', shift.id) },
    ];

    const isOpen = shift.status === 'open';
    const discrepancy = shift.discrepancy;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Shift #${shift.id}`} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className={`${panelClass} flex flex-wrap items-center justify-between gap-4 p-5`}>
                    <div className="flex items-center gap-3">
                        <Clock className="text-muted-foreground size-5 shrink-0" />
                        <div>
                            <div className="flex items-center gap-2">
                                <h2 className="font-semibold">Shift #{shift.id}</h2>
                                <span
                                    className={`rounded-full px-3 py-1 text-xs font-medium text-white capitalize ${isOpen ? 'bg-green-600' : 'bg-gray-500'}`}
                                >
                                    {shift.status}
                                </span>
                            </div>
                            <p className="text-muted-foreground text-xs">
                                Opened {formatDate(shift.opened_at)} by {shift.opened_by ?? '—'}
                                {' · '}
                                {isOpen ? 'Still open — totals are live' : `Closed ${formatDate(shift.closed_at)} by ${shift.closed_by ?? '—'}`}
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="hover:bg-muted inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium print:hidden"
                    >
                        <Printer className="size-4" />
                        Print report
                    </button>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <div className={panelClass}>
                        <PanelTitle icon={<Banknote className="text-muted-foreground size-4" />}>Cash drawer</PanelTitle>
                        <div className="py-2">
                            <LedgerRow label="Starting cash" value={shift.starting_cash} />
                            <LedgerRow label="Cash sales" value={shift.total_cash} sign="+" />
                            <LedgerRow label="Cash additions" value={shift.total_additions} sign="+" />
                            <LedgerRow label="Expenses" value={shift.total_expenses} sign="−" />
                            <LedgerRow label="Cash refunds" value={shift.total_cash_refunds} sign="−" />
                            <LedgerRow label="Expected cash" value={shift.expected_cash} emphasis />
                            {!isOpen && (
                                <>
                                    <LedgerRow label="Counted cash" value={shift.closing_cash} />
                                    <div className="flex items-center justify-between px-5 py-2 text-sm font-semibold">
                                        <span>Discrepancy</span>
                                        <span
                                            className={
                                                discrepancy === null || discrepancy === 0 ? '' : discrepancy < 0 ? 'text-red-600' : 'text-green-600'
                                            }
                                        >
                                            {peso(discrepancy)}
                                            {discrepancy !== null && discrepancy !== 0 && (discrepancy < 0 ? ' short' : ' over')}
                                        </span>
                                    </div>
                                </>
                            )}
                        </div>
                    </div>

                    <div className={panelClass}>
                        <PanelTitle icon={<ReceiptText className="text-muted-foreground size-4" />}>Sales</PanelTitle>
                        <div className="py-2">
                            <LedgerRow label="Revenue (paid tickets)" value={shift.total_revenue} />
                            <LedgerRow label="Cash" value={shift.total_cash} />
                            <LedgerRow label="GCash" value={shift.total_gcash} />
                            <LedgerRow label="Refunds (all methods)" value={shift.total_refunds} />
                        </div>
                        <div className="grid grid-cols-4 border-t text-center">
                            {(['paid', 'open', 'cancelled', 'merged'] as const).map((status) => (
                                <div key={status} className="px-2 py-3">
                                    <div className="text-lg font-semibold">{ticketCounts[status]}</div>
                                    <div className="text-muted-foreground text-xs capitalize">{status}</div>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>

                <div className={panelClass}>
                    <PanelTitle icon={<Banknote className="text-muted-foreground size-4" />}>Expenses & cash additions</PanelTitle>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Time</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Reason</TableHead>
                                <TableHead>By</TableHead>
                                <TableHead className="text-right">Amount</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {transactions.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={5} className="text-muted-foreground py-6 text-center text-sm">
                                        No expenses or cash additions in this shift.
                                    </TableCell>
                                </TableRow>
                            )}
                            {transactions.map((transaction) => (
                                <TableRow key={transaction.id}>
                                    <TableCell>{formatDate(transaction.created_at)}</TableCell>
                                    <TableCell>
                                        <span
                                            className={`rounded-full px-3 py-1 text-xs font-medium text-white capitalize ${
                                                transaction.type === 'addition' ? 'bg-green-600' : 'bg-red-600'
                                            }`}
                                        >
                                            {transaction.type}
                                        </span>
                                    </TableCell>
                                    <TableCell>{transaction.reason}</TableCell>
                                    <TableCell>{transaction.created_by ?? '—'}</TableCell>
                                    <TableCell className="text-right">
                                        {transaction.type === 'expense' ? '−' : '+'}
                                        {peso(transaction.amount)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <div className={panelClass}>
                    <PanelTitle icon={<Undo2 className="text-muted-foreground size-4" />}>Refunds</PanelTitle>
                    {!isOpen && refunds.length > 0 && (
                        <p className="text-muted-foreground px-5 pt-3 text-xs">
                            A refund approved after the shift closed is listed here but isn't part of the closing totals above.
                        </p>
                    )}
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Requested</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Method</TableHead>
                                <TableHead>Reason</TableHead>
                                <TableHead>Requested by</TableHead>
                                <TableHead>Decided by</TableHead>
                                <TableHead className="text-right">Amount</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {refunds.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={7} className="text-muted-foreground py-6 text-center text-sm">
                                        No refunds in this shift.
                                    </TableCell>
                                </TableRow>
                            )}
                            {refunds.map((refund) => (
                                <TableRow key={refund.id}>
                                    <TableCell>{formatDate(refund.requested_at)}</TableCell>
                                    <TableCell>
                                        <span
                                            className={`rounded-full px-3 py-1 text-xs font-medium text-white capitalize ${refundStatusClass[refund.status]}`}
                                        >
                                            {refund.status}
                                        </span>
                                    </TableCell>
                                    <TableCell className="capitalize">{refund.payment_method ?? '—'}</TableCell>
                                    <TableCell>{refund.reason ?? '—'}</TableCell>
                                    <TableCell>{refund.requested_by ?? '—'}</TableCell>
                                    <TableCell>{refund.approved_by ?? '—'}</TableCell>
                                    <TableCell className="text-right">{peso(refund.amount)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}

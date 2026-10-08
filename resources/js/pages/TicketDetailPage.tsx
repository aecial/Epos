import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { CreditCard, GitMerge, ReceiptText, ScrollText, Undo2, UtensilsCrossed } from 'lucide-react';
import { type ReactNode } from 'react';

type TicketStatus = 'open' | 'paid' | 'cancelled' | 'merged';

type TicketLink = { id: number; order_number: string; customer_name: string };

type Ticket = {
    id: number;
    shift_id: number;
    shift_opened_at: string | null;
    order_number: string;
    customer_name: string;
    order_type: 'dine_in' | 'takeout';
    status: TicketStatus;
    terminal_id: string;
    created_at: string;
    ended_at: string | null;
    created_by: string | null;
    subtotal: number;
    discount_amount: number;
    discount_percent: number;
    total: number;
    notes: string | null;
    cancelled_by: string | null;
    merged_by: string | null;
    merged_into: TicketLink | null;
    merged_from: TicketLink[];
};

type Line = {
    id: number;
    item_name: string;
    line_type: 'item' | 'fee' | 'custom';
    quantity: number;
    unit_price: number;
    line_total: number;
    notes: string | null;
    modifiers: { name: string; price: number }[];
    merged_from_order_number: string | null;
    voided_at: string | null;
    voided_by: string | null;
    voided_requested_by: string | null;
};

type Charge = {
    id: number;
    payment_method: 'cash' | 'gcash';
    status: 'pending' | 'paid' | 'voided';
    amount: number;
    tendered_amount: number | null;
    change_due: number | null;
    payment_reference: string | null;
    paid_at: string | null;
    created_by: string | null;
    receipt_id: number | null;
    receipt_number: string | null;
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
    items: { item_name: string | null; quantity: number; amount: number }[];
};

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

function peso(value: number | null): string {
    return value === null ? '—' : `₱${value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

const panelClass = 'border-sidebar-border/70 dark:border-sidebar-border rounded-xl border';

const statusClass: Record<TicketStatus, string> = {
    open: 'bg-blue-600',
    paid: 'bg-green-600',
    cancelled: 'bg-red-600',
    merged: 'bg-gray-500',
};

const refundStatusClass: Record<Refund['status'], string> = {
    pending: 'bg-amber-500',
    approved: 'bg-green-600',
    rejected: 'bg-red-600',
};

const paymentLabel: Record<'cash' | 'gcash', string> = { cash: 'Cash', gcash: 'GCash' };

function PanelTitle({ icon, children }: { icon: ReactNode; children: ReactNode }) {
    return (
        <div className="flex items-center gap-2 border-b px-5 py-3">
            {icon}
            <h3 className="font-semibold">{children}</h3>
        </div>
    );
}

function TicketLinks({ tickets }: { tickets: TicketLink[] }) {
    return (
        <>
            {tickets.map((linked, index) => (
                <span key={linked.id}>
                    {index > 0 && ', '}
                    <Link href={route('tickets.show', linked.id)} className="font-medium underline underline-offset-2">
                        {linked.order_number}
                    </Link>{' '}
                    ({linked.customer_name})
                </span>
            ))}
        </>
    );
}

/** One payment's details in reading order: cash shows tendered/change, GCash its reference. */
function paymentDetails(charge: Charge): string[] {
    const details: string[] = [];

    if (charge.payment_method === 'cash' && charge.tendered_amount !== null) {
        details.push(`tendered ${peso(charge.tendered_amount)}, change ${peso(charge.change_due ?? 0)}`);
    }
    if (charge.payment_reference) {
        details.push(`ref ${charge.payment_reference}`);
    }
    if (charge.receipt_number) {
        details.push(charge.receipt_number);
    }
    if (charge.created_by) {
        details.push(`by ${charge.created_by}`);
    }
    if (charge.paid_at) {
        details.push(new Date(charge.paid_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }));
    }

    return details;
}

function endedLine(ticket: Ticket): string {
    switch (ticket.status) {
        case 'open':
            return 'Still open';
        case 'paid':
            return `Paid ${formatDate(ticket.ended_at)}`;
        case 'cancelled':
            return `Cancelled ${formatDate(ticket.ended_at)} by ${ticket.cancelled_by ?? '—'}`;
        case 'merged':
            return `Merged ${formatDate(ticket.ended_at)} by ${ticket.merged_by ?? '—'}`;
    }
}

export default function TicketDetailPage({
    ticket,
    items,
    charges,
    refunds,
}: {
    ticket: Ticket;
    items: Line[];
    charges: Charge[];
    refunds: Refund[];
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Back Office', href: route('back-office') },
        { title: 'Tickets', href: route('tickets.index') },
        { title: `Order ${ticket.order_number}`, href: route('tickets.show', ticket.id) },
    ];

    const discount = Math.round((ticket.subtotal - ticket.total) * 100) / 100;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Order ${ticket.order_number}`} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className={`${panelClass} flex flex-wrap items-center justify-between gap-4 p-5`}>
                    <div className="flex items-center gap-3">
                        <ReceiptText className="text-muted-foreground size-5 shrink-0" />
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="font-semibold">
                                    Order {ticket.order_number} · {ticket.customer_name}
                                </h2>
                                <span className={`rounded-full px-3 py-1 text-xs font-medium text-white capitalize ${statusClass[ticket.status]}`}>
                                    {ticket.status}
                                </span>
                                <span className="rounded-full border px-3 py-1 text-xs font-medium">
                                    {ticket.order_type === 'dine_in' ? 'Dine in' : 'Takeout'}
                                </span>
                            </div>
                            <p className="text-muted-foreground text-xs">
                                Opened {formatDate(ticket.created_at)} by {ticket.created_by ?? '—'} on {ticket.terminal_id}
                                {' · '}
                                {endedLine(ticket)}
                            </p>
                        </div>
                    </div>
                    <Link
                        href={route('shifts.show', ticket.shift_id)}
                        className="hover:bg-muted inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium"
                    >
                        Shift #{ticket.shift_id}
                        {ticket.shift_opened_at && <span className="text-muted-foreground font-normal">· {formatDate(ticket.shift_opened_at)}</span>}
                    </Link>
                </div>

                {(ticket.merged_into || ticket.merged_from.length > 0) && (
                    <div className={`${panelClass} flex items-start gap-2 p-5 text-sm`}>
                        <GitMerge className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                        <div className="space-y-1">
                            {ticket.merged_into && (
                                <p>
                                    Merged into <TicketLinks tickets={[ticket.merged_into]} /> — its lines and money moved there, so this ticket now
                                    shows ₱0.00.
                                </p>
                            )}
                            {ticket.merged_from.length > 0 && (
                                <p>
                                    Merged from <TicketLinks tickets={ticket.merged_from} />.
                                </p>
                            )}
                        </div>
                    </div>
                )}

                <div className={panelClass}>
                    <PanelTitle icon={<UtensilsCrossed className="text-muted-foreground size-4" />}>Items</PanelTitle>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-16">Qty</TableHead>
                                <TableHead>Item</TableHead>
                                <TableHead className="text-right">Unit price</TableHead>
                                <TableHead className="text-right">Line total</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {items.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={4} className="text-muted-foreground py-6 text-center text-sm">
                                        {ticket.merged_into ? 'Every line moved to the ticket this was merged into.' : 'No items on this ticket.'}
                                    </TableCell>
                                </TableRow>
                            )}
                            {items.map((line) => {
                                const isVoided = line.voided_at !== null;

                                return (
                                    <TableRow key={line.id} className={isVoided ? 'text-muted-foreground' : ''}>
                                        <TableCell className={`align-top ${isVoided ? 'line-through' : ''}`}>{line.quantity}×</TableCell>
                                        <TableCell className="align-top">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className={`font-medium ${isVoided ? 'line-through' : ''}`}>{line.item_name}</span>
                                                {line.line_type !== 'item' && (
                                                    <span className="rounded-full border px-2 py-0.5 text-xs capitalize">{line.line_type}</span>
                                                )}
                                                {line.merged_from_order_number && (
                                                    <span className="rounded-full border px-2 py-0.5 text-xs">
                                                        from {line.merged_from_order_number}
                                                    </span>
                                                )}
                                            </div>
                                            {line.modifiers.length > 0 && (
                                                <div className="text-muted-foreground text-xs">
                                                    {line.modifiers
                                                        .map((modifier) =>
                                                            modifier.price > 0 ? `${modifier.name} (+${peso(modifier.price)})` : modifier.name,
                                                        )
                                                        .join(', ')}
                                                </div>
                                            )}
                                            {line.notes && <div className="text-muted-foreground text-xs italic">Kitchen note: {line.notes}</div>}
                                            {isVoided && (
                                                <div className="text-xs text-red-600">
                                                    Voided {formatDate(line.voided_at)} · approved by {line.voided_by ?? '—'}, requested by{' '}
                                                    {line.voided_requested_by ?? '—'}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className={`text-right align-top ${isVoided ? 'line-through' : ''}`}>
                                            {peso(line.unit_price)}
                                        </TableCell>
                                        <TableCell className={`text-right align-top ${isVoided ? 'line-through' : ''}`}>
                                            {peso(line.line_total)}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                    <div className="ml-auto max-w-xs space-y-1 border-t px-5 py-3 text-sm">
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Subtotal</span>
                            <span>{peso(ticket.subtotal)}</span>
                        </div>
                        {discount > 0 && (
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">
                                    Discount{ticket.discount_percent > 0 ? ` (${ticket.discount_percent}%)` : ''}
                                </span>
                                <span>−{peso(discount)}</span>
                            </div>
                        )}
                        <div className="flex justify-between font-semibold">
                            <span>Total</span>
                            <span>{peso(ticket.total)}</span>
                        </div>
                    </div>
                    {ticket.notes && (
                        <p className="text-muted-foreground border-t px-5 py-3 text-xs whitespace-pre-line">Ticket notes: {ticket.notes}</p>
                    )}
                </div>

                {charges.length > 0 && (
                    <div className={panelClass}>
                        <PanelTitle icon={<CreditCard className="text-muted-foreground size-4" />}>Payments</PanelTitle>
                        <ul className="divide-y">
                            {charges.map((charge) => (
                                <li key={charge.id} className="flex flex-wrap items-baseline gap-x-2 gap-y-1 px-5 py-3 text-sm">
                                    <span className="font-semibold">
                                        {paymentLabel[charge.payment_method]} {peso(charge.amount)}
                                    </span>
                                    {charge.status !== 'paid' && <span className="text-muted-foreground text-xs capitalize">({charge.status})</span>}
                                    <span className="text-muted-foreground">
                                        {paymentDetails(charge)
                                            .map((detail) => ` · ${detail}`)
                                            .join('')}
                                    </span>
                                    {charge.receipt_id && (
                                        <Link
                                            href={route('receipts.show', charge.receipt_id)}
                                            className="hover:bg-muted ml-auto inline-flex items-center gap-1 rounded-md border px-2.5 py-1 text-xs font-medium"
                                        >
                                            <ScrollText className="size-3.5" />
                                            View receipt
                                        </Link>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {refunds.length > 0 && (
                    <div className={panelClass}>
                        <PanelTitle icon={<Undo2 className="text-muted-foreground size-4" />}>Refunds</PanelTitle>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Requested</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Method</TableHead>
                                    <TableHead>Items</TableHead>
                                    <TableHead>Reason</TableHead>
                                    <TableHead>Requested by</TableHead>
                                    <TableHead>Decided by</TableHead>
                                    <TableHead className="text-right">Amount</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
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
                                        <TableCell>{refund.payment_method ? paymentLabel[refund.payment_method] : '—'}</TableCell>
                                        <TableCell className="text-xs">
                                            {refund.items.map((item) => `${item.quantity}× ${item.item_name ?? '—'}`).join(', ') || '—'}
                                        </TableCell>
                                        <TableCell>{refund.reason ?? '—'}</TableCell>
                                        <TableCell>{refund.requested_by ?? '—'}</TableCell>
                                        <TableCell>{refund.approved_by ?? '—'}</TableCell>
                                        <TableCell className="text-right">{peso(refund.amount)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

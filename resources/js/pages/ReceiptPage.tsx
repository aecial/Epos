import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { History, Printer } from 'lucide-react';
import { useState } from 'react';

type ReceiptLine = {
    name: string;
    line_type: 'item' | 'fee' | 'custom';
    quantity: number;
    unit_price: number;
    modifiers: { name: string; price: number }[];
    line_total: number;
};

/** The receipt exactly as it was issued - ReceiptService stores this payload once and never changes it. */
type ReceiptPayload = {
    receipt_number: string;
    issued_at: string;
    order: {
        order_number: string;
        customer_name: string;
        order_type: 'dine_in' | 'takeout';
        terminal_id: string;
        merged_from: { order_number: string; customer_name: string }[];
    };
    cashier: string;
    items: ReceiptLine[];
    subtotal: number;
    discount: number;
    total: number;
    payment: {
        method: 'cash' | 'gcash';
        amount: number;
        tendered_amount: number | null;
        change_due: number | null;
        reference: string | null;
    };
};

type Receipt = {
    id: number;
    ticket_id: number;
    receipt_number: string;
    issued_at: string;
    issued_by: string | null;
    payload: ReceiptPayload;
};

type ReceiptPrintLog = { id: number; is_reprint: boolean; printed_at: string; printed_by: string | null };

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

function peso(value: number | null): string {
    return value === null ? '—' : `₱${value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

const paymentLabel: Record<'cash' | 'gcash', string> = { cash: 'Cash', gcash: 'GCash' };

function Row({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className={`flex justify-between gap-4 ${strong ? 'font-bold' : ''}`}>
            <span>{label}</span>
            <span>{value}</span>
        </div>
    );
}

function Lines({ lines }: { lines: ReceiptLine[] }) {
    return (
        <>
            {lines.map((line, index) => (
                <div key={index}>
                    <Row label={`${line.quantity}× ${line.name}`} value={peso(line.line_total)} />
                    {line.modifiers.map((modifier) => (
                        <div key={modifier.name} className="pl-5 text-xs">
                            + {modifier.name}
                            {modifier.price > 0 && ` (${peso(modifier.price)})`}
                        </div>
                    ))}
                    {line.quantity > 1 && <div className="pl-5 text-xs">@ {peso(line.unit_price)}</div>}
                </div>
            ))}
        </>
    );
}

export default function ReceiptPage({ receipt, prints }: { receipt: Receipt; prints: ReceiptPrintLog[] }) {
    const payload = receipt.payload;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Back Office', href: route('back-office') },
        { title: 'Tickets', href: route('tickets.index') },
        { title: `Order ${payload.order.order_number}`, href: route('tickets.show', receipt.ticket_id) },
        { title: receipt.receipt_number, href: route('receipts.show', receipt.id) },
    ];

    const [printing, setPrinting] = useState(false);
    const items = payload.items.filter((line) => line.line_type !== 'fee');
    const fees = payload.items.filter((line) => line.line_type === 'fee');
    const orderNumbers = [payload.order.order_number, ...payload.order.merged_from.map((source) => source.order_number)].join(', ');

    /** A back-office print is always a duplicate: log it first (same as a POS reprint), then print. */
    function printDuplicate() {
        setPrinting(true);
        router.post(
            route('receipts.reprint', receipt.id),
            {},
            {
                preserveScroll: true,
                onSuccess: () => setTimeout(() => window.print(), 0),
                onFinish: () => setPrinting(false),
            },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={receipt.receipt_number} />
            <div className="flex h-full flex-1 flex-col items-center gap-4 rounded-xl p-4">
                <div className="flex w-full max-w-sm flex-wrap items-center justify-between gap-2 print:hidden">
                    <p className="text-muted-foreground text-xs">Printing logs a duplicate and marks it "DUPLICATE RECEIPT".</p>
                    <button
                        type="button"
                        onClick={printDuplicate}
                        disabled={printing}
                        className="hover:bg-muted inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium disabled:opacity-50"
                    >
                        <Printer className="size-4" />
                        Print duplicate
                    </button>
                </div>

                <div className="w-full max-w-sm space-y-3 rounded-xl border bg-white p-5 font-mono text-sm text-black print:max-w-[80mm] print:border-0 print:p-0">
                    <div className="hidden text-center font-bold tracking-widest print:block">*** DUPLICATE RECEIPT ***</div>
                    <div className="text-center">
                        <div className="font-bold">{payload.receipt_number}</div>
                        <div className="text-xs">{formatDate(payload.issued_at)}</div>
                    </div>
                    <div className="space-y-0.5 border-t border-dashed border-black pt-3 text-xs">
                        <Row label="Order" value={orderNumbers} />
                        <Row label="Customer" value={payload.order.customer_name} />
                        <Row label="Type" value={payload.order.order_type === 'dine_in' ? 'Dine in' : 'Takeout'} />
                        <Row label="Terminal" value={payload.order.terminal_id} />
                        <Row label="Cashier" value={payload.cashier} />
                    </div>
                    <div className="space-y-1 border-t border-dashed border-black pt-3">
                        <Lines lines={items} />
                        {fees.length > 0 && (
                            <>
                                <div className="pt-1 text-xs font-bold">Fees</div>
                                <Lines lines={fees} />
                            </>
                        )}
                    </div>
                    <div className="space-y-0.5 border-t border-dashed border-black pt-3">
                        <Row label="Subtotal" value={peso(payload.subtotal)} />
                        {payload.discount > 0 && <Row label="Discount" value={`−${peso(payload.discount)}`} />}
                        <Row label="TOTAL" value={peso(payload.total)} strong />
                    </div>
                    <div className="space-y-0.5 border-t border-dashed border-black pt-3 text-xs">
                        <Row label={`Paid (${paymentLabel[payload.payment.method]})`} value={peso(payload.payment.amount)} />
                        {payload.payment.tendered_amount !== null && <Row label="Tendered" value={peso(payload.payment.tendered_amount)} />}
                        {payload.payment.change_due !== null && <Row label="Change" value={peso(payload.payment.change_due)} />}
                        {payload.payment.reference && <Row label="Reference" value={payload.payment.reference} />}
                    </div>
                    <div className="border-t border-dashed border-black pt-3 text-center text-xs">Thank you!</div>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border w-full max-w-sm rounded-xl border print:hidden">
                    <div className="flex items-center gap-2 border-b px-5 py-3">
                        <History className="text-muted-foreground size-4" />
                        <h3 className="font-semibold">Print history</h3>
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Printed</TableHead>
                                <TableHead>By</TableHead>
                                <TableHead>Copy</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {prints.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={3} className="text-muted-foreground py-6 text-center text-sm">
                                        No prints logged yet.
                                    </TableCell>
                                </TableRow>
                            )}
                            {prints.map((print) => (
                                <TableRow key={print.id}>
                                    <TableCell>{formatDate(print.printed_at)}</TableCell>
                                    <TableCell>{print.printed_by ?? '—'}</TableCell>
                                    <TableCell>{print.is_reprint ? 'Duplicate' : 'Original'}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}

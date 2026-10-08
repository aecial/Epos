import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Clock } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Back Office', href: route('back-office') },
    { title: 'Shifts', href: route('shifts.index') },
];

type ShiftRow = {
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
    expected_cash: number | null;
    closing_cash: number | null;
    discrepancy: number | null;
};

type Pagination = { current_page: number; last_page: number; total: number };

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

function peso(value: number | null): string {
    return value === null ? '—' : `₱${value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function discrepancyClass(value: number | null): string {
    if (value === null || value === 0) return '';

    return value < 0 ? 'text-red-600 font-medium' : 'text-green-600 font-medium';
}

const pagerLinkClass = 'hover:bg-muted inline-flex items-center gap-1 rounded-md border px-3 py-1.5 text-sm font-medium';

export default function ShiftManagementPage({ shifts, pagination }: { shifts: ShiftRow[]; pagination: Pagination }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Shifts" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="border-sidebar-border/70 dark:border-sidebar-border relative max-h-[calc(100vh-8rem)] min-h-0 flex-1 overflow-auto rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead colSpan={10}>
                                    <div className="flex items-center gap-3 p-5">
                                        <Clock className="text-muted-foreground size-5 shrink-0" />
                                        <div>
                                            <h2 className="font-semibold">Shifts</h2>
                                            <p className="text-muted-foreground text-xs">
                                                Every shift, newest first. Click one for the full cash breakdown.
                                            </p>
                                        </div>
                                    </div>
                                </TableHead>
                            </TableRow>
                            <TableRow>
                                <TableHead>Opened</TableHead>
                                <TableHead>Closed</TableHead>
                                <TableHead>Opened by</TableHead>
                                <TableHead className="text-right">Starting cash</TableHead>
                                <TableHead className="text-right">Revenue</TableHead>
                                <TableHead className="text-right">Cash</TableHead>
                                <TableHead className="text-right">GCash</TableHead>
                                <TableHead className="text-right">Expected cash</TableHead>
                                <TableHead className="text-right">Counted</TableHead>
                                <TableHead className="text-right">Discrepancy</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {shifts.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={10} className="text-muted-foreground py-8 text-center text-sm">
                                        No shifts yet.
                                    </TableCell>
                                </TableRow>
                            )}
                            {shifts.map((shift) => (
                                <TableRow key={shift.id} className="cursor-pointer" onClick={() => router.visit(route('shifts.show', shift.id))}>
                                    <TableCell className="font-medium">
                                        <Link href={route('shifts.show', shift.id)} onClick={(event) => event.stopPropagation()}>
                                            {formatDate(shift.opened_at)}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        {shift.status === 'open' ? (
                                            <span className="rounded-full bg-green-600 px-3 py-1 text-xs font-medium text-white">Open</span>
                                        ) : (
                                            formatDate(shift.closed_at)
                                        )}
                                    </TableCell>
                                    <TableCell>{shift.opened_by ?? '—'}</TableCell>
                                    <TableCell className="text-right">{peso(shift.starting_cash)}</TableCell>
                                    <TableCell className="text-right">{peso(shift.total_revenue)}</TableCell>
                                    <TableCell className="text-right">{peso(shift.total_cash)}</TableCell>
                                    <TableCell className="text-right">{peso(shift.total_gcash)}</TableCell>
                                    <TableCell className="text-right">{peso(shift.expected_cash)}</TableCell>
                                    <TableCell className="text-right">{peso(shift.closing_cash)}</TableCell>
                                    <TableCell className={`text-right ${discrepancyClass(shift.discrepancy)}`}>{peso(shift.discrepancy)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
                {pagination.last_page > 1 && (
                    <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">
                            Page {pagination.current_page} of {pagination.last_page} · {pagination.total} shifts
                        </span>
                        <div className="flex gap-2">
                            {pagination.current_page > 1 && (
                                <Link href={route('shifts.index', { page: pagination.current_page - 1 })} className={pagerLinkClass}>
                                    <ChevronLeft className="size-4" />
                                    Previous
                                </Link>
                            )}
                            {pagination.current_page < pagination.last_page && (
                                <Link href={route('shifts.index', { page: pagination.current_page + 1 })} className={pagerLinkClass}>
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

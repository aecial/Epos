import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, CircleCheck, Package, SlidersHorizontal, Tags, TriangleAlert, UtensilsCrossed, Wheat } from 'lucide-react';
import { type ReactNode, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Back Office',
        href: route('back-office'),
    },
];

type Counts = {
    categories: number;
    items: number;
    items_available: number;
    items_unavailable: number;
    items_hidden: number;
    modifier_groups: number;
    ingredient_groups: number;
    ingredients: number;
};

type Issue = {
    key: string;
    title: string;
    help: string;
    records: { name: string; detail: string | null; url: string }[];
};

type StockStatus = 'out' | 'low' | 'ok';

type StockRow = {
    kind: 'raw material' | 'item';
    name: string;
    group: string | null;
    unit: string | null;
    on_hand: number;
    reserved: number;
    available: number;
    reorder_level: number | null;
    status: StockStatus;
    url: string;
};

const panelClass = 'border-sidebar-border/70 dark:border-sidebar-border rounded-xl border';

const statusBadge: Record<StockStatus, { label: string; className: string }> = {
    out: { label: 'Out', className: 'bg-red-600 text-white' },
    low: { label: 'Low', className: 'bg-amber-500 text-white' },
    ok: { label: 'OK', className: 'bg-muted text-muted-foreground' },
};

function amount(value: number, unit: string | null): string {
    return `${value.toLocaleString('en-PH', { maximumFractionDigits: 3 })}${unit ? ` ${unit}` : ''}`;
}

function PanelTitle({ icon, children, action }: { icon: ReactNode; children: ReactNode; action?: ReactNode }) {
    return (
        <div className="flex items-center gap-2 border-b px-5 py-3">
            {icon}
            <h3 className="font-semibold">{children}</h3>
            {action && <div className="ml-auto text-sm">{action}</div>}
        </div>
    );
}

/** One management area: what's in it at a glance, and the way in. The whole card is the link. */
function ManagementCard({ href, icon, title, value, detail }: { href: string; icon: ReactNode; title: string; value: number; detail?: string }) {
    return (
        <Link href={href} className={`${panelClass} hover:bg-muted group flex flex-col gap-1 px-4 py-3`}>
            <span className="text-muted-foreground flex items-center gap-2 text-sm font-medium">
                {icon}
                {title}
            </span>
            <span className="text-2xl font-semibold">{value}</span>
            <span className="text-muted-foreground min-h-4 text-xs">{detail}</span>
            <span className="mt-1 inline-flex items-center gap-1 text-sm font-medium">
                Manage
                <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
            </span>
        </Link>
    );
}

export default function BackOffice({ counts, issues, stock }: { counts: Counts; issues: Issue[]; stock: StockRow[] }) {
    const [lowOnly, setLowOnly] = useState(false);
    const shownStock = lowOnly ? stock.filter((row) => row.status !== 'ok') : stock;
    const needsRestock = stock.filter((row) => row.status !== 'ok').length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Back Office" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                    <ManagementCard
                        href={route('category-management')}
                        icon={<Tags className="size-4" />}
                        title="Categories"
                        value={counts.categories}
                    />
                    <ManagementCard
                        href={route('item-management')}
                        icon={<UtensilsCrossed className="size-4" />}
                        title="Items"
                        value={counts.items}
                        detail={`${counts.items_available} available · ${counts.items_unavailable} unavailable · ${counts.items_hidden} hidden`}
                    />
                    <ManagementCard
                        href={route('modifier-management')}
                        icon={<SlidersHorizontal className="size-4" />}
                        title="Modifiers"
                        value={counts.modifier_groups}
                        detail={counts.modifier_groups === 1 ? 'group' : 'groups'}
                    />
                    <ManagementCard
                        href={route('ingredient-management')}
                        icon={<Wheat className="size-4" />}
                        title="Raw materials"
                        value={counts.ingredients}
                        detail={`in ${counts.ingredient_groups} ${counts.ingredient_groups === 1 ? 'group' : 'groups'}`}
                    />
                </div>

                <div className={panelClass}>
                    <PanelTitle
                        icon={
                            issues.length > 0 ? (
                                <TriangleAlert className="size-4 text-amber-600" />
                            ) : (
                                <CircleCheck className="size-4 text-green-600" />
                            )
                        }
                    >
                        Needs fixing
                    </PanelTitle>
                    {issues.length === 0 ? (
                        <p className="text-muted-foreground px-5 py-6 text-center text-sm">Everything's set up correctly.</p>
                    ) : (
                        <div className="divide-y">
                            {issues.map((issue) => (
                                <div key={issue.key} className="px-5 py-3">
                                    <div className="text-sm font-medium">
                                        {issue.title} <span className="text-muted-foreground font-normal">({issue.records.length})</span>
                                    </div>
                                    <p className="text-muted-foreground text-xs">{issue.help}</p>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {issue.records.map((record) => (
                                            <Link
                                                key={record.url}
                                                href={record.url}
                                                className="hover:bg-muted inline-flex items-center gap-1 rounded-md border px-2.5 py-1 text-xs font-medium"
                                            >
                                                {record.name}
                                                {record.detail && <span className="text-muted-foreground font-normal">· {record.detail}</span>}
                                            </Link>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <div className={`${panelClass} overflow-auto`}>
                    <PanelTitle
                        icon={<Package className="text-muted-foreground size-4" />}
                        action={
                            <label className="inline-flex cursor-pointer items-center gap-2">
                                <input type="checkbox" checked={lowOnly} onChange={(event) => setLowOnly(event.target.checked)} />
                                Low &amp; out only
                            </label>
                        }
                    >
                        Stock {needsRestock > 0 && <span className="text-muted-foreground text-sm font-normal">· {needsRestock} to restock</span>}
                    </PanelTitle>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Status</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Group / category</TableHead>
                                <TableHead className="text-right">On hand</TableHead>
                                <TableHead className="text-right">Reserved</TableHead>
                                <TableHead className="text-right">Available</TableHead>
                                <TableHead className="text-right">Reorder level</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {shownStock.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={7} className="text-muted-foreground py-8 text-center text-sm">
                                        {lowOnly ? 'Nothing is low or out.' : 'No raw materials or stock items yet.'}
                                    </TableCell>
                                </TableRow>
                            )}
                            {shownStock.map((row) => (
                                <TableRow key={`${row.kind}-${row.name}`}>
                                    <TableCell>
                                        <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${statusBadge[row.status].className}`}>
                                            {statusBadge[row.status].label}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        <Link href={row.url} className="font-medium underline-offset-2 hover:underline">
                                            {row.name}
                                        </Link>
                                        <div className="text-muted-foreground text-xs capitalize">{row.kind}</div>
                                    </TableCell>
                                    <TableCell>{row.group ?? '—'}</TableCell>
                                    <TableCell className="text-right">{amount(row.on_hand, row.unit)}</TableCell>
                                    <TableCell className="text-right">{row.reserved > 0 ? amount(row.reserved, row.unit) : '—'}</TableCell>
                                    <TableCell className="text-right font-medium">{amount(row.available, row.unit)}</TableCell>
                                    <TableCell className="text-right">
                                        {row.reorder_level === null ? (
                                            <span className="text-muted-foreground">Not set</span>
                                        ) : (
                                            amount(row.reorder_level, row.unit)
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}

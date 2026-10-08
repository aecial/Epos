import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronDown, ChevronLeft, ChevronRight, Printer, ShoppingBag, TriangleAlert } from 'lucide-react';
import { Fragment, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Back Office', href: route('back-office') },
    { title: 'Items Sold', href: route('sales.index') },
];

type SoldRow = {
    key: string;
    item_id: number;
    name: string;
    line_type: 'item' | 'fee' | 'custom';
    quantity: number;
    gross_sales: number;
    discount: number;
    net_sales: number;
    refunded_quantity: number;
    refunded: number;
    cost: number;
    profit: number;
    margin: number | null;
    missing_cost: boolean;
};

type Summary = {
    tickets_paid: number;
    items_sold: number;
    gross_sales: number;
    discounts: number;
    net_sales: number;
    refunds: number;
    cost: number;
    gross_profit: number;
    expenses: number;
    net_profit: number;
};

type Period = { date_from: string; date_to: string; today: string };

type OpenTickets = { count: number; total: number } | null;

type ReportView = 'items' | 'raw-materials';

type RawMaterialDish = {
    item_id: number;
    name: string;
    servings: number;
    used: number;
    returned: number;
    net_used: number;
    cost: number;
    net_sales: number | null;
    profit: number | null;
};

type RawMaterial = {
    ingredient_id: number;
    name: string;
    group: string | null;
    unit: 'piece' | 'kg' | 'gram' | 'liter' | 'ml';
    used: number;
    returned: number;
    net_used: number;
    cost: number;
    stock: number;
    available: number;
    dishes: RawMaterialDish[];
};

type DirectItem = { name: string; quantity: number };

function peso(value: number): string {
    return `${value < 0 ? '−' : ''}₱${Math.abs(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/** Shifts a Y-m-d date by whole days without going through UTC. */
function addDays(date: string, days: number): string {
    const [year, month, day] = date.split('-').map(Number);
    const shifted = new Date(year, month - 1, day + days);

    return [shifted.getFullYear(), String(shifted.getMonth() + 1).padStart(2, '0'), String(shifted.getDate()).padStart(2, '0')].join('-');
}

function formatDay(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
}

function profitClass(value: number): string {
    if (value === 0) return '';

    return value < 0 ? 'text-red-600' : 'text-green-600';
}

const panelClass = 'border-sidebar-border/70 dark:border-sidebar-border rounded-xl border';
const buttonClass = 'hover:bg-muted inline-flex items-center gap-1 rounded-md border px-3 py-2 text-sm font-medium';

function SummaryCard({ label, value, hint, valueClass = '' }: { label: string; value: string; hint?: string; valueClass?: string }) {
    return (
        <div className={`${panelClass} p-4`}>
            <div className="text-muted-foreground text-xs">{label}</div>
            <div className={`mt-1 text-xl font-semibold ${valueClass}`}>{value}</div>
            {hint && <div className="text-muted-foreground mt-0.5 text-xs">{hint}</div>}
        </div>
    );
}

const unitLabel: Record<RawMaterial['unit'], string> = { piece: 'pcs', kg: 'kg', gram: 'g', liter: 'L', ml: 'ml' };

function amount(value: number, unit: RawMaterial['unit']): string {
    return `${value.toLocaleString('en-PH', { maximumFractionDigits: 3 })} ${unitLabel[unit]}`;
}

function RawMaterialsPanel({
    rawMaterials,
    directItems,
    isSingleDay,
}: {
    rawMaterials: RawMaterial[];
    directItems: DirectItem[];
    isSingleDay: boolean;
}) {
    const [collapsed, setCollapsed] = useState<number[]>([]);

    function toggle(ingredientId: number) {
        setCollapsed((current) => (current.includes(ingredientId) ? current.filter((id) => id !== ingredientId) : [...current, ingredientId]));
    }

    return (
        <div className={`${panelClass} overflow-auto`}>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Raw material / dish</TableHead>
                        <TableHead className="text-right">Servings</TableHead>
                        <TableHead className="text-right">Used</TableHead>
                        <TableHead className="text-right">Returned</TableHead>
                        <TableHead className="text-right">Net used</TableHead>
                        <TableHead className="text-right">Cost</TableHead>
                        <TableHead className="text-right">Dish sales</TableHead>
                        <TableHead className="text-right">Dish profit</TableHead>
                        <TableHead className="text-right">Stock left</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rawMaterials.length === 0 && (
                        <TableRow>
                            <TableCell colSpan={9} className="text-muted-foreground py-8 text-center text-sm">
                                {isSingleDay ? 'No raw materials used on this day.' : 'No raw materials used in this period.'}
                            </TableCell>
                        </TableRow>
                    )}
                    {rawMaterials.map((material, index) => {
                        const isCollapsed = collapsed.includes(material.ingredient_id);
                        const showGroup = index === 0 || material.group !== rawMaterials[index - 1].group;

                        return (
                            <Fragment key={material.ingredient_id}>
                                {showGroup && (
                                    <TableRow className="bg-muted/50 hover:bg-muted/50">
                                        <TableCell colSpan={9} className="text-muted-foreground py-1.5 text-xs font-semibold tracking-wide uppercase">
                                            {material.group ?? 'No group'}
                                        </TableCell>
                                    </TableRow>
                                )}
                                <TableRow className="cursor-pointer font-semibold" onClick={() => toggle(material.ingredient_id)}>
                                    <TableCell>
                                        <span className="inline-flex items-center gap-1">
                                            <ChevronDown className={`size-4 transition-transform print:hidden ${isCollapsed ? '-rotate-90' : ''}`} />
                                            {material.name}
                                        </span>
                                    </TableCell>
                                    <TableCell />
                                    <TableCell className="text-right">{amount(material.used, material.unit)}</TableCell>
                                    <TableCell className="text-right">
                                        {material.returned > 0 ? amount(material.returned, material.unit) : '—'}
                                    </TableCell>
                                    <TableCell className="text-right">{amount(material.net_used, material.unit)}</TableCell>
                                    <TableCell className="text-right">{peso(material.cost)}</TableCell>
                                    <TableCell />
                                    <TableCell />
                                    <TableCell className="text-right font-normal">
                                        {amount(material.stock, material.unit)}
                                        {material.available !== material.stock && (
                                            <div className="text-muted-foreground text-xs">{amount(material.available, material.unit)} available</div>
                                        )}
                                    </TableCell>
                                </TableRow>
                                {!isCollapsed &&
                                    material.dishes.map((dish) => (
                                        <TableRow key={`${material.ingredient_id}-${dish.item_id}`} className="text-sm">
                                            <TableCell className="text-muted-foreground pl-10">{dish.name}</TableCell>
                                            <TableCell className="text-right">{dish.servings}</TableCell>
                                            <TableCell className="text-right">{amount(dish.used, material.unit)}</TableCell>
                                            <TableCell className="text-right">
                                                {dish.returned > 0 ? amount(dish.returned, material.unit) : '—'}
                                            </TableCell>
                                            <TableCell className="text-right">{amount(dish.net_used, material.unit)}</TableCell>
                                            <TableCell className="text-right">{peso(dish.cost)}</TableCell>
                                            <TableCell className="text-muted-foreground text-right">
                                                {dish.net_sales === null ? '—' : peso(dish.net_sales)}
                                            </TableCell>
                                            <TableCell className={`text-right ${dish.profit === null ? '' : profitClass(dish.profit)}`}>
                                                {dish.profit === null ? '—' : peso(dish.profit)}
                                            </TableCell>
                                            <TableCell />
                                        </TableRow>
                                    ))}
                            </Fragment>
                        );
                    })}
                </TableBody>
            </Table>
            <p className="text-muted-foreground border-t px-5 py-3 text-xs">
                Dish sales and profit are the dish's totals for the period, shown under every raw material it uses — they aren't added up per raw
                material.
            </p>
            {directItems.length > 0 && (
                <p className="border-t px-5 py-3 text-sm">
                    <span className="text-muted-foreground">Not made from raw materials: </span>
                    {directItems.map((item) => `${item.name} × ${item.quantity}`).join(', ')}
                </p>
            )}
        </div>
    );
}

export default function SalesReportPage({
    rows,
    summary,
    period,
    openTickets,
    view,
    rawMaterials,
    directItems,
}: {
    rows: SoldRow[];
    summary: Summary;
    period: Period;
    openTickets: OpenTickets;
    view: ReportView;
    rawMaterials: RawMaterial[];
    directItems: DirectItem[];
}) {
    const isSingleDay = period.date_from === period.date_to;
    const yesterday = addDays(period.today, -1);

    function showPeriod(dateFrom: string, dateTo: string = dateFrom, nextView: ReportView = view) {
        router.get(route('sales.index'), { date_from: dateFrom, date_to: dateTo, view: nextView }, { preserveScroll: true });
    }

    function showView(nextView: ReportView) {
        showPeriod(period.date_from, period.date_to, nextView);
    }

    const title = isSingleDay
        ? period.date_from === period.today
            ? `Today · ${formatDay(period.date_from)}`
            : formatDay(period.date_from)
        : `${formatDay(period.date_from)} – ${formatDay(period.date_to)}`;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Items Sold" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className={`${panelClass} flex flex-wrap items-center justify-between gap-4 p-5`}>
                    <div className="flex items-center gap-3">
                        <ShoppingBag className="text-muted-foreground size-5 shrink-0" />
                        <div>
                            <h2 className="font-semibold">Items Sold</h2>
                            <p className="text-muted-foreground text-xs">
                                {title} · counted when paid, so an open shift shows live. {summary.tickets_paid}{' '}
                                {summary.tickets_paid === 1 ? 'ticket' : 'tickets'} paid.
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-end gap-2 print:hidden">
                        {isSingleDay && (
                            <button
                                type="button"
                                onClick={() => showPeriod(addDays(period.date_from, -1))}
                                className={buttonClass}
                                aria-label="Previous day"
                            >
                                <ChevronLeft className="size-4" />
                            </button>
                        )}
                        <label className="flex flex-col gap-1 text-xs font-medium">
                            From
                            <input
                                type="date"
                                value={period.date_from}
                                onChange={(event) =>
                                    event.target.value &&
                                    showPeriod(event.target.value, event.target.value > period.date_to ? event.target.value : period.date_to)
                                }
                                className="field"
                            />
                        </label>
                        <label className="flex flex-col gap-1 text-xs font-medium">
                            To
                            <input
                                type="date"
                                value={period.date_to}
                                min={period.date_from}
                                onChange={(event) => event.target.value && showPeriod(period.date_from, event.target.value)}
                                className="field"
                            />
                        </label>
                        {isSingleDay && (
                            <button
                                type="button"
                                onClick={() => showPeriod(addDays(period.date_from, 1))}
                                className={buttonClass}
                                aria-label="Next day"
                            >
                                <ChevronRight className="size-4" />
                            </button>
                        )}
                        <button type="button" onClick={() => showPeriod(period.today)} className={buttonClass}>
                            Today
                        </button>
                        <button type="button" onClick={() => showPeriod(yesterday)} className={buttonClass}>
                            Yesterday
                        </button>
                        <button type="button" onClick={() => window.print()} className={buttonClass}>
                            <Printer className="size-4" />
                            Print
                        </button>
                    </div>
                </div>

                {openTickets && openTickets.count > 0 && (
                    <div className="rounded-xl border border-blue-500/40 bg-blue-500/10 px-5 py-3 text-sm print:hidden">
                        Not yet paid: {openTickets.count} open {openTickets.count === 1 ? 'ticket' : 'tickets'} worth {peso(openTickets.total)}.
                        They'll count here once paid —{' '}
                        <Link href={route('tickets.index', { status: 'open' })} className="font-medium underline underline-offset-2">
                            view open tickets
                        </Link>
                        .
                    </div>
                )}

                <div className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
                    <SummaryCard label="Items sold" value={String(summary.items_sold)} hint={`${summary.tickets_paid} paid tickets`} />
                    <SummaryCard
                        label="Net sales"
                        value={peso(summary.net_sales)}
                        hint={summary.discounts > 0 ? `${peso(summary.gross_sales)} − ${peso(summary.discounts)} discounts` : undefined}
                    />
                    <SummaryCard label="Refunds" value={peso(summary.refunds)} hint="approved in this period" />
                    <SummaryCard label="Cost of items" value={peso(summary.cost)} />
                    <SummaryCard
                        label="Gross profit"
                        value={peso(summary.gross_profit)}
                        hint="sales − refunds − cost"
                        valueClass={profitClass(summary.gross_profit)}
                    />
                    <SummaryCard
                        label="Net profit"
                        value={peso(summary.net_profit)}
                        hint={`after ${peso(summary.expenses)} expenses`}
                        valueClass={profitClass(summary.net_profit)}
                    />
                </div>

                <div className="flex gap-1 self-start rounded-lg border p-1 print:hidden">
                    {(['items', 'raw-materials'] as const).map((tab) => (
                        <button
                            key={tab}
                            type="button"
                            onClick={() => showView(tab)}
                            className={`rounded-md px-3 py-1.5 text-sm font-medium ${view === tab ? 'bg-muted' : 'text-muted-foreground hover:text-foreground'}`}
                        >
                            {tab === 'items' ? 'By item' : 'By raw material'}
                        </button>
                    ))}
                </div>

                {view === 'items' ? (
                    <div className={`${panelClass} overflow-auto`}>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Item</TableHead>
                                    <TableHead className="text-right">Qty</TableHead>
                                    <TableHead className="text-right">Gross</TableHead>
                                    <TableHead className="text-right">Discount</TableHead>
                                    <TableHead className="text-right">Net sales</TableHead>
                                    <TableHead className="text-right">Refunded</TableHead>
                                    <TableHead className="text-right">Cost</TableHead>
                                    <TableHead className="text-right">Profit</TableHead>
                                    <TableHead className="text-right">Margin</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={9} className="text-muted-foreground py-8 text-center text-sm">
                                            {isSingleDay ? 'Nothing sold on this day.' : 'Nothing sold in this period.'}
                                        </TableCell>
                                    </TableRow>
                                )}
                                {rows.map((row) => (
                                    <TableRow key={row.key}>
                                        <TableCell>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-medium">{row.name}</span>
                                                {row.line_type !== 'item' && (
                                                    <span className="rounded-full border px-2 py-0.5 text-xs capitalize">{row.line_type}</span>
                                                )}
                                                {row.missing_cost && (
                                                    <span
                                                        className="inline-flex items-center gap-1 rounded-full bg-amber-500/15 px-2 py-0.5 text-xs text-amber-700 dark:text-amber-400"
                                                        title="Sold with no cost recorded, so its profit is overstated. Set its cost or recipe on the item."
                                                    >
                                                        <TriangleAlert className="size-3" />
                                                        No cost set
                                                    </span>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right">{row.quantity}</TableCell>
                                        <TableCell className="text-right">{peso(row.gross_sales)}</TableCell>
                                        <TableCell className="text-right">{row.discount > 0 ? `−${peso(row.discount)}` : '—'}</TableCell>
                                        <TableCell className="text-right font-medium">{peso(row.net_sales)}</TableCell>
                                        <TableCell className="text-right">
                                            {row.refunded > 0
                                                ? `−${peso(row.refunded)}${row.refunded_quantity > 0 ? ` (${row.refunded_quantity})` : ''}`
                                                : '—'}
                                        </TableCell>
                                        <TableCell className="text-right">{peso(row.cost)}</TableCell>
                                        <TableCell className={`text-right font-medium ${profitClass(row.profit)}`}>{peso(row.profit)}</TableCell>
                                        <TableCell className="text-right">{row.margin === null ? '—' : `${row.margin}%`}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                            {rows.length > 0 && (
                                <TableFooter>
                                    <TableRow>
                                        <TableCell className="font-semibold">Total</TableCell>
                                        <TableCell className="text-right font-semibold">{summary.items_sold}</TableCell>
                                        <TableCell className="text-right">{peso(summary.gross_sales)}</TableCell>
                                        <TableCell className="text-right">{summary.discounts > 0 ? `−${peso(summary.discounts)}` : '—'}</TableCell>
                                        <TableCell className="text-right font-semibold">{peso(summary.net_sales)}</TableCell>
                                        <TableCell className="text-right">{summary.refunds > 0 ? `−${peso(summary.refunds)}` : '—'}</TableCell>
                                        <TableCell className="text-right">{peso(summary.cost)}</TableCell>
                                        <TableCell className={`text-right font-semibold ${profitClass(summary.gross_profit)}`}>
                                            {peso(summary.gross_profit)}
                                        </TableCell>
                                        <TableCell />
                                    </TableRow>
                                </TableFooter>
                            )}
                        </Table>
                    </div>
                ) : (
                    <RawMaterialsPanel rawMaterials={rawMaterials} directItems={directItems} isSingleDay={isSingleDay} />
                )}
            </div>
        </AppLayout>
    );
}

import { type ChartConfig, ChartContainer, ChartLegend, ChartLegendContent, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePoll } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChefHat, CircleCheck, Clock, CloudAlert, LayoutGrid, PackageMinus, TriangleAlert, Undo2 } from 'lucide-react';
import { type ReactNode } from 'react';
import { Bar, BarChart, CartesianGrid, Pie, PieChart, ReferenceLine, XAxis, YAxis } from 'recharts';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

type Headline = {
    net_sales: number;
    tickets_paid: number;
    average_ticket: number;
    gross_profit: number;
    margin: number | null;
    net_profit: number;
    refunds: number;
};

type Shift = {
    id: number;
    opened_at: string;
    opened_by: string | null;
    starting_cash: number;
    expected_cash: number;
    open_tickets: { count: number; total: number };
} | null;

type HourBucket = { hour: number; today: number; yesterday: number };

type LowStock = { kind: 'raw material' | 'item'; name: string; unit: string | null; available: number; reorder_level: number; url: string };

type Attention = {
    pending_refunds: { count: number; oldest_requested_at: string | null };
    sync_issues: { count: number };
    late_kitchen_orders: { count: number; oldest_created_at: string | null };
    running_low: LowStock[];
    missing_cost: string[];
};

type Day = { date: string; net_sales: number; net_profit: number };

type DashboardProps = {
    now: string;
    shift: Shift;
    today: Headline;
    yesterday: Headline;
    hourly: HourBucket[];
    paymentMix: { cash: number; gcash: number };
    topItems: { name: string; quantity: number; net_sales: number }[];
    attention: Attention;
    lastSevenDays: Day[];
};

/** Series colors - categorical slots 1 and 2, validated for light and dark surfaces. */
const seriesOne = { light: '#2a78d6', dark: '#3987e5' };
const seriesTwo = { light: '#eb6834', dark: '#d95926' };
const seriesOneSwatch = 'bg-[#2a78d6] dark:bg-[#3987e5]';
const seriesTwoSwatch = 'bg-[#eb6834] dark:bg-[#d95926]';

const panelClass = 'border-sidebar-border/70 dark:border-sidebar-border rounded-xl border';

function peso(value: number): string {
    return `${value < 0 ? '−' : ''}₱${Math.abs(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function shortPeso(value: number): string {
    const abs = Math.abs(value);
    const text =
        abs >= 1000
            ? `${(abs / 1000).toLocaleString('en-PH', { maximumFractionDigits: 1 })}k`
            : abs.toLocaleString('en-PH', { maximumFractionDigits: 0 });

    return `${value < 0 ? '−' : ''}₱${text}`;
}

function minutesSince(value: string, now: string): number {
    return Math.max(0, Math.floor((new Date(now).getTime() - new Date(value).getTime()) / 60000));
}

function waited(value: string | null, now: string): string {
    if (!value) return '';
    const minutes = minutesSince(value, now);

    if (minutes < 60) return `${minutes} min`;
    if (minutes < 60 * 24) return `${Math.floor(minutes / 60)} h ${minutes % 60} min`;

    return `${Math.floor(minutes / (60 * 24))} d`;
}

function hourLabel(hour: number): string {
    const suffix = hour < 12 ? 'a' : 'p';
    const twelve = hour % 12 === 0 ? 12 : hour % 12;

    return `${twelve}${suffix}`;
}

function dayLabel(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric' });
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

function Swatch({ className }: { className: string }) {
    return <span className={`inline-block size-2.5 rounded-sm ${className}`} />;
}

/**
 * Today against the same clock time yesterday. Up is good except where `higherIsWorse` (refunds);
 * the arrow and wording carry the direction, the color only reinforces it.
 */
function Change({
    today,
    yesterday,
    money = true,
    higherIsWorse = false,
}: {
    today: number;
    yesterday: number;
    money?: boolean;
    higherIsWorse?: boolean;
}) {
    const format = (value: number) => (money ? peso(value) : String(value));

    if (today === yesterday) {
        return <span className="text-muted-foreground">Same as this time yesterday</span>;
    }

    const isUp = today > yesterday;
    const isGood = isUp !== higherIsWorse;
    const percent = yesterday !== 0 ? Math.round((Math.abs(today - yesterday) / Math.abs(yesterday)) * 100) : null;

    return (
        <span className="inline-flex flex-wrap items-center gap-1">
            <span className={`inline-flex items-center font-medium ${isGood ? 'text-green-600' : 'text-red-600'}`}>
                {isUp ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />}
                {percent === null ? (isUp ? 'up' : 'down') : `${percent}%`}
            </span>
            <span className="text-muted-foreground">vs {format(yesterday)} this time yesterday</span>
        </span>
    );
}

function Kpi({ label, value, change, valueClass = '' }: { label: string; value: string; change: ReactNode; valueClass?: string }) {
    return (
        <div className={`${panelClass} p-4`}>
            <div className="text-muted-foreground text-xs">{label}</div>
            <div className={`mt-1 text-xl font-semibold ${valueClass}`}>{value}</div>
            <div className="mt-1 text-xs">{change}</div>
        </div>
    );
}

/** Tooltip row: the series' color dot and name, then the amount in pesos. */
function pesoTooltipRow(value: unknown, name: unknown, item: { color?: string }, config: ChartConfig) {
    const label = config[String(name)]?.label ?? String(name);

    return (
        <>
            <div className="size-2.5 shrink-0 rounded-[2px]" style={{ backgroundColor: item.color }} />
            <div className="flex flex-1 items-center justify-between gap-4 leading-none">
                <span className="text-muted-foreground">{label}</span>
                <span className="text-foreground font-mono font-medium tabular-nums">{peso(Number(value))}</span>
            </div>
        </>
    );
}

const hourlyConfig = {
    today: { label: 'Today', theme: seriesOne },
    yesterday: { label: 'Yesterday', theme: seriesTwo },
} satisfies ChartConfig;

function HourlyChart({ hourly }: { hourly: HourBucket[] }) {
    if (hourly.length === 0) {
        return <p className="text-muted-foreground px-5 py-10 text-center text-sm">No sales yet today or yesterday.</p>;
    }

    const data = hourly.map((bucket) => ({ ...bucket, label: hourLabel(bucket.hour) }));

    return (
        <div className="px-3 pt-4 pb-2">
            <ChartContainer config={hourlyConfig} className="aspect-auto h-60 w-full" aria-label="Net sales by hour, today and yesterday">
                <BarChart data={data} barGap={2} barCategoryGap="24%" accessibilityLayer>
                    <CartesianGrid vertical={false} />
                    <XAxis dataKey="label" tickLine={false} axisLine={false} tickMargin={8} />
                    <YAxis tickLine={false} axisLine={false} width={52} tickFormatter={(value: number) => shortPeso(value)} />
                    <ChartTooltip
                        cursor={{ fill: 'var(--muted)' }}
                        content={
                            <ChartTooltipContent
                                labelFormatter={(_, payload) => {
                                    const hour = payload?.[0]?.payload?.hour as number | undefined;

                                    return hour === undefined ? '' : `${hourLabel(hour)}–${hourLabel((hour + 1) % 24)}`;
                                }}
                                formatter={(value, name, item) => pesoTooltipRow(value, name, item, hourlyConfig)}
                            />
                        }
                    />
                    <ChartLegend content={<ChartLegendContent />} />
                    <Bar dataKey="today" fill="var(--color-today)" radius={[4, 4, 0, 0]} />
                    <Bar dataKey="yesterday" fill="var(--color-yesterday)" radius={[4, 4, 0, 0]} />
                </BarChart>
            </ChartContainer>
        </div>
    );
}

const paymentConfig = {
    cash: { label: 'Cash', theme: seriesOne },
    gcash: { label: 'GCash', theme: seriesTwo },
} satisfies ChartConfig;

function PaymentMix({ cash, gcash }: { cash: number; gcash: number }) {
    const total = cash + gcash;

    if (total === 0) {
        return <p className="text-muted-foreground px-5 py-6 text-center text-sm">No payments yet today.</p>;
    }

    const share = (value: number) => Math.round((value / total) * 100);
    const data = [
        { method: 'cash', amount: cash, fill: 'var(--color-cash)' },
        { method: 'gcash', amount: gcash, fill: 'var(--color-gcash)' },
    ].filter((slice) => slice.amount > 0);

    return (
        <div className="flex items-center gap-4 px-5 py-4">
            <ChartContainer
                config={paymentConfig}
                className="aspect-square h-36 shrink-0"
                aria-label={`Cash ${share(cash)}%, GCash ${share(gcash)}%`}
            >
                <PieChart accessibilityLayer>
                    <ChartTooltip
                        content={
                            <ChartTooltipContent
                                hideLabel
                                nameKey="method"
                                formatter={(value, name, item) => pesoTooltipRow(value, name, { color: item.payload?.fill }, paymentConfig)}
                            />
                        }
                    />
                    {/* A 2px surface-colored stroke separates the two slices. */}
                    <Pie data={data} dataKey="amount" nameKey="method" innerRadius="58%" strokeWidth={2} stroke="var(--background)" />
                </PieChart>
            </ChartContainer>
            <div className="grid flex-1 gap-2 text-sm">
                {(['cash', 'gcash'] as const).map((method) => (
                    <div key={method} className="flex items-center gap-2">
                        <Swatch className={method === 'cash' ? seriesOneSwatch : seriesTwoSwatch} />
                        <span className="flex-1">{paymentConfig[method].label}</span>
                        <span className="font-medium">{peso(method === 'cash' ? cash : gcash)}</span>
                        <span className="text-muted-foreground w-10 text-right">{share(method === 'cash' ? cash : gcash)}%</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

const sevenDayConfig = {
    net_sales: { label: 'Net sales', theme: seriesOne },
    net_profit: { label: 'Net profit', theme: seriesTwo },
} satisfies ChartConfig;

function SevenDayChart({ days }: { days: Day[] }) {
    if (days.every((day) => day.net_sales === 0 && day.net_profit === 0)) {
        return <p className="text-muted-foreground px-5 py-10 text-center text-sm">No sales in the last 7 days.</p>;
    }

    const data = days.map((day) => ({ ...day, label: dayLabel(day.date) }));

    return (
        <div className="px-3 pt-4 pb-2">
            <ChartContainer config={sevenDayConfig} className="aspect-auto h-60 w-full" aria-label="Net sales and net profit for the last 7 days">
                <BarChart data={data} barGap={2} barCategoryGap="24%" accessibilityLayer>
                    <CartesianGrid vertical={false} />
                    <XAxis dataKey="label" tickLine={false} axisLine={false} tickMargin={8} />
                    <YAxis tickLine={false} axisLine={false} width={52} tickFormatter={(value: number) => shortPeso(value)} />
                    {/* A loss day's profit bar drops below this line. */}
                    <ReferenceLine y={0} stroke="var(--border)" />
                    <ChartTooltip
                        cursor={{ fill: 'var(--muted)' }}
                        content={<ChartTooltipContent formatter={(value, name, item) => pesoTooltipRow(value, name, item, sevenDayConfig)} />}
                    />
                    <ChartLegend content={<ChartLegendContent />} />
                    <Bar dataKey="net_sales" fill="var(--color-net_sales)" radius={[4, 4, 0, 0]} />
                    <Bar dataKey="net_profit" fill="var(--color-net_profit)" radius={[4, 4, 0, 0]} />
                </BarChart>
            </ChartContainer>
        </div>
    );
}

function AttentionRow({ icon, href, children }: { icon: ReactNode; href: string; children: ReactNode }) {
    return (
        <Link href={href} className="hover:bg-muted flex items-start gap-3 px-5 py-3 text-sm">
            <span className="mt-0.5 shrink-0">{icon}</span>
            <span>{children}</span>
        </Link>
    );
}

export default function Dashboard({ now, shift, today, yesterday, hourly, paymentMix, topItems, attention, lastSevenDays }: DashboardProps) {
    usePoll(60000);

    const updatedAt = new Date(now).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    const hasAttention =
        attention.pending_refunds.count > 0 ||
        attention.sync_issues.count > 0 ||
        attention.late_kitchen_orders.count > 0 ||
        attention.running_low.length > 0 ||
        attention.missing_cost.length > 0;
    const firstDay = lastSevenDays[0]?.date;
    const lastDay = lastSevenDays[lastSevenDays.length - 1]?.date;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className={`${panelClass} flex flex-wrap items-center gap-3 p-5`}>
                    <LayoutGrid className="text-muted-foreground size-5 shrink-0" />
                    <div className="min-w-0 flex-1">
                        <h2 className="font-semibold">Today so far</h2>
                        <p className="text-muted-foreground text-xs">Updated {updatedAt} · refreshes every minute · sales count when paid</p>
                    </div>
                    {shift ? (
                        <Link
                            href={route('shifts.show', shift.id)}
                            className="hover:bg-muted flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg border px-4 py-2 text-sm"
                        >
                            <span className="inline-flex items-center gap-1.5 font-medium">
                                <span className="size-2 rounded-full bg-green-600" />
                                Shift open since {new Date(shift.opened_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}
                                {shift.opened_by && <span className="text-muted-foreground font-normal">by {shift.opened_by}</span>}
                            </span>
                            <span>
                                <span className="text-muted-foreground">Drawer should have </span>
                                <span className="font-medium">{peso(shift.expected_cash)}</span>
                            </span>
                            <span>
                                <span className="text-muted-foreground">Open orders </span>
                                <span className="font-medium">
                                    {shift.open_tickets.count} · {peso(shift.open_tickets.total)}
                                </span>
                            </span>
                        </Link>
                    ) : (
                        <span className="text-muted-foreground inline-flex items-center gap-1.5 rounded-lg border px-4 py-2 text-sm">
                            <Clock className="size-4" />
                            No shift open — open one on a POS terminal.
                        </span>
                    )}
                </div>

                <div className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
                    <Kpi
                        label="Net sales"
                        value={peso(today.net_sales)}
                        change={<Change today={today.net_sales} yesterday={yesterday.net_sales} />}
                    />
                    <Kpi
                        label="Tickets paid"
                        value={String(today.tickets_paid)}
                        change={<Change today={today.tickets_paid} yesterday={yesterday.tickets_paid} money={false} />}
                    />
                    <Kpi
                        label="Average ticket"
                        value={peso(today.average_ticket)}
                        change={<Change today={today.average_ticket} yesterday={yesterday.average_ticket} />}
                    />
                    <Kpi
                        label={`Gross profit${today.margin === null ? '' : ` · ${today.margin}%`}`}
                        value={peso(today.gross_profit)}
                        valueClass={today.gross_profit < 0 ? 'text-red-600' : ''}
                        change={<Change today={today.gross_profit} yesterday={yesterday.gross_profit} />}
                    />
                    <Kpi
                        label="Net profit"
                        value={peso(today.net_profit)}
                        valueClass={today.net_profit < 0 ? 'text-red-600' : ''}
                        change={<Change today={today.net_profit} yesterday={yesterday.net_profit} />}
                    />
                    <Kpi
                        label="Refunds approved"
                        value={peso(today.refunds)}
                        change={<Change today={today.refunds} yesterday={yesterday.refunds} higherIsWorse />}
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <div className={`${panelClass} lg:col-span-2`}>
                        <PanelTitle icon={<Clock className="text-muted-foreground size-4" />}>Sales by hour</PanelTitle>
                        <HourlyChart hourly={hourly} />
                    </div>
                    <div className={panelClass}>
                        <PanelTitle
                            icon={
                                hasAttention ? <TriangleAlert className="size-4 text-amber-600" /> : <CircleCheck className="size-4 text-green-600" />
                            }
                        >
                            Needs attention
                        </PanelTitle>
                        {!hasAttention && <p className="text-muted-foreground px-5 py-10 text-center text-sm">All clear.</p>}
                        <div className="divide-y">
                            {attention.pending_refunds.count > 0 && (
                                <AttentionRow icon={<Undo2 className="size-4 text-amber-600" />} href={route('refunds.index')}>
                                    <span className="font-medium">
                                        {attention.pending_refunds.count} {attention.pending_refunds.count === 1 ? 'refund' : 'refunds'} waiting for
                                        approval
                                    </span>
                                    <span className="text-muted-foreground block text-xs">
                                        Oldest waiting {waited(attention.pending_refunds.oldest_requested_at, now)} · approve on a POS terminal
                                    </span>
                                </AttentionRow>
                            )}
                            {attention.late_kitchen_orders.count > 0 && (
                                <AttentionRow icon={<ChefHat className="size-4 text-red-600" />} href={route('kitchen-orders.index')}>
                                    <span className="font-medium">
                                        {attention.late_kitchen_orders.count} kitchen {attention.late_kitchen_orders.count === 1 ? 'order' : 'orders'}{' '}
                                        waiting 20+ min
                                    </span>
                                    <span className="text-muted-foreground block text-xs">
                                        Oldest waiting {waited(attention.late_kitchen_orders.oldest_created_at, now)}
                                    </span>
                                </AttentionRow>
                            )}
                            {attention.sync_issues.count > 0 && (
                                <AttentionRow icon={<CloudAlert className="size-4 text-amber-600" />} href={route('sync-issues.index')}>
                                    <span className="font-medium">
                                        {attention.sync_issues.count} offline {attention.sync_issues.count === 1 ? 'sale needs' : 'sales need'} a look
                                    </span>
                                    <span className="text-muted-foreground block text-xs">
                                        Synced after an outage with something that didn't add up · Sync Review
                                    </span>
                                </AttentionRow>
                            )}
                            {attention.running_low.map((stock) => (
                                <AttentionRow
                                    key={`${stock.kind}-${stock.name}`}
                                    icon={<PackageMinus className="size-4 text-amber-600" />}
                                    href={stock.url}
                                >
                                    <span className="font-medium">{stock.name} is running low</span>
                                    <span className="text-muted-foreground block text-xs">
                                        {stock.available.toLocaleString('en-PH', { maximumFractionDigits: 3 })} {stock.unit ?? ''} available · reorder
                                        at {stock.reorder_level.toLocaleString('en-PH', { maximumFractionDigits: 3 })} {stock.unit ?? ''} (
                                        {stock.kind})
                                    </span>
                                </AttentionRow>
                            ))}
                            {attention.missing_cost.length > 0 && (
                                <AttentionRow icon={<TriangleAlert className="size-4 text-amber-600" />} href={route('sales.index')}>
                                    <span className="font-medium">Sold today with no cost set</span>
                                    <span className="text-muted-foreground block text-xs">
                                        {attention.missing_cost.join(', ')} — profit is overstated
                                    </span>
                                </AttentionRow>
                            )}
                        </div>
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <div className={panelClass}>
                        <PanelTitle icon={<LayoutGrid className="text-muted-foreground size-4" />}>Payment mix today</PanelTitle>
                        <PaymentMix cash={paymentMix.cash} gcash={paymentMix.gcash} />
                    </div>
                    <div className={`${panelClass} lg:col-span-2`}>
                        <PanelTitle
                            icon={<LayoutGrid className="text-muted-foreground size-4" />}
                            action={
                                <Link
                                    href={route('sales.index')}
                                    className="text-muted-foreground hover:text-foreground underline underline-offset-2"
                                >
                                    All items sold
                                </Link>
                            }
                        >
                            Top items today
                        </PanelTitle>
                        {topItems.length === 0 ? (
                            <p className="text-muted-foreground px-5 py-6 text-center text-sm">Nothing sold yet today.</p>
                        ) : (
                            <ol className="divide-y">
                                {topItems.map((item, index) => (
                                    <li key={item.name} className="flex items-center gap-3 px-5 py-2.5 text-sm">
                                        <span className="text-muted-foreground w-4 text-right">{index + 1}</span>
                                        <span className="flex-1 font-medium">{item.name}</span>
                                        <span className="text-muted-foreground">× {item.quantity}</span>
                                        <span className="w-28 text-right">{peso(item.net_sales)}</span>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </div>
                </div>

                <div className={panelClass}>
                    <PanelTitle
                        icon={<LayoutGrid className="text-muted-foreground size-4" />}
                        action={
                            firstDay &&
                            lastDay && (
                                <Link
                                    href={route('sales.index', { date_from: firstDay, date_to: lastDay })}
                                    className="text-muted-foreground hover:text-foreground underline underline-offset-2"
                                >
                                    See these 7 days
                                </Link>
                            )
                        }
                    >
                        Last 7 days
                    </PanelTitle>
                    <SevenDayChart days={lastSevenDays} />
                    <p className="text-muted-foreground border-t px-5 py-2 text-xs">
                        Last 7 days: {shortPeso(lastSevenDays.reduce((sum, day) => sum + day.net_sales, 0))} net sales ·{' '}
                        {shortPeso(lastSevenDays.reduce((sum, day) => sum + day.net_profit, 0))} net profit
                    </p>
                </div>
            </div>
        </AppLayout>
    );
}

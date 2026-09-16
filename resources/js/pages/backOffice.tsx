import BackOfficeUpperDiv from '@/components/ui/backOfficeUpperDiv';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Check, Search, X } from 'lucide-react';
import { useState } from 'react';
const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Back Office',
        href: route('back-office'),
    },
];

type ItemStatus = 'available' | 'unavailable' | 'hidden';

type BackOfficeItem = {
    id: number;
    category: string;
    name: string;
    base_price: number;
    cost_price: number;
    quantity: number;
    reserved_quantity: number;
    status: ItemStatus;
};

const items: BackOfficeItem[] = [
    {
        id: 1,
        category: 'Burgers',
        name: 'Classic Burger',
        base_price: 250,
        cost_price: 95,
        quantity: 40,
        reserved_quantity: 6,
        status: 'available',
    },
    {
        id: 2,
        category: 'Burgers',
        name: 'Crispy Chicken Sandwich',
        base_price: 220,
        cost_price: 88,
        quantity: 25,
        reserved_quantity: 4,
        status: 'available',
    },
    {
        id: 3,
        category: 'Beverages',
        name: 'House Iced Tea',
        base_price: 90,
        cost_price: 24,
        quantity: 0,
        reserved_quantity: 0,
        status: 'unavailable',
    },
];

function SearchInput({ className = '' }: { className?: string }) {
    return (
        <div className={`relative ${className}`}>
            <Search className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" />
            <Input type="search" placeholder="Search" className="pl-9" />
        </div>
    );
}

export default function BackOffice() {
    const [itemStatuses, setItemStatuses] = useState<Record<number, ItemStatus>>(Object.fromEntries(items.map((item) => [item.id, item.status])));
    const [quantities, setQuantities] = useState<Record<number, number>>(Object.fromEntries(items.map((item) => [item.id, item.quantity])));
    const [editingQuantity, setEditingQuantity] = useState<number | null>(null);
    const [quantityDraft, setQuantityDraft] = useState('');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Back Office" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="grid auto-rows-min gap-4 md:grid-cols-4">
                    <BackOfficeUpperDiv
                        children={
                            <Button asChild className="h-full w-full cursor-pointer text-xl">
                                <Link href={route('category-management')}>Category Management</Link>
                            </Button>
                        }
                    />
                    <BackOfficeUpperDiv
                        children={
                            <Button asChild className="h-full w-full cursor-pointer text-xl">
                                <Link href={route('item-management')}>Item Management</Link>
                            </Button>
                        }
                    />
                    <BackOfficeUpperDiv
                        children={
                            <Button asChild className="h-full w-full cursor-pointer text-xl">
                                <Link href={route('modifier-management')}>Modifier Management</Link>
                            </Button>
                        }
                    />
                    <BackOfficeUpperDiv
                        children={
                            <Button asChild className="h-full w-full cursor-pointer text-xl">
                                <Link href={route('ingredient-management')}>Ingredient Management</Link>
                            </Button>
                        }
                    />
                </div>
                <div className="border-sidebar-border/70 dark:border-sidebar-border relative min-h-[100vh] flex-1 rounded-xl border md:min-h-min">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead colSpan={3} className="">
                                    <SearchInput />
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-[70px]">ID</TableHead>
                                <TableHead>Category</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead className="text-right">Base Price</TableHead>
                                <TableHead className="text-right">Cost Price</TableHead>
                                <TableHead className="text-right">Margin %</TableHead>
                                <TableHead className="text-right">Quantity</TableHead>
                                <TableHead className="text-right">Reserved</TableHead>
                                <TableHead className="text-right">Available</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {items.map((item) => (
                                <TableRow key={item.id}>
                                    <TableCell className="font-medium">{item.id}</TableCell>
                                    <TableCell>{item.category}</TableCell>
                                    <TableCell>{item.name}</TableCell>
                                    <TableCell className="text-right">₱{item.base_price.toFixed(2)}</TableCell>
                                    <TableCell className="text-right">₱{item.cost_price.toFixed(2)}</TableCell>
                                    <TableCell className="text-right">
                                        {(((item.base_price - item.cost_price) / item.base_price) * 100).toFixed(2)}%
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {editingQuantity === item.id ? (
                                            <div className="flex items-center justify-end gap-1">
                                                <Input
                                                    type="number"
                                                    min="0"
                                                    value={quantityDraft}
                                                    onChange={(event) => setQuantityDraft(event.target.value)}
                                                    className="h-7 w-16 appearance-none px-2 text-right [&::-webkit-inner-spin-button]:m-0 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:m-0 [&::-webkit-outer-spin-button]:appearance-none"
                                                    autoFocus
                                                />
                                                <button
                                                    type="button"
                                                    aria-label="Save quantity"
                                                    className="flex size-7 shrink-0 items-center justify-center text-green-600 hover:text-green-700"
                                                    onClick={() => {
                                                        setQuantities((current) => ({ ...current, [item.id]: Number(quantityDraft) || 0 }));
                                                        setEditingQuantity(null);
                                                    }}
                                                >
                                                    <Check className="size-3" />
                                                </button>
                                                <button
                                                    type="button"
                                                    aria-label="Cancel quantity edit"
                                                    className="flex size-7 shrink-0 items-center justify-center text-red-600 hover:text-red-700"
                                                    onClick={() => setEditingQuantity(null)}
                                                >
                                                    <X className="size-3" />
                                                </button>
                                            </div>
                                        ) : (
                                            <button
                                                type="button"
                                                className="cursor-pointer"
                                                onClick={() => {
                                                    setEditingQuantity(item.id);
                                                    setQuantityDraft(String(quantities[item.id]));
                                                }}
                                            >
                                                {quantities[item.id]}
                                            </button>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">{item.reserved_quantity}</TableCell>
                                    <TableCell className="text-right">{item.quantity - item.reserved_quantity}</TableCell>
                                    <TableCell>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setItemStatuses((statuses) => ({
                                                    ...statuses,
                                                    [item.id]: statuses[item.id] === 'available' ? 'unavailable' : 'available',
                                                }))
                                            }
                                            className={`rounded-full px-3 py-1 text-xs font-medium text-white capitalize ${
                                                itemStatuses[item.id] === 'available' ? 'bg-green-600' : 'bg-red-600'
                                            }`}
                                        >
                                            {itemStatuses[item.id]}
                                        </button>
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

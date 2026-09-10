import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

type ItemStatus = 'available' | 'unavailable' | 'hidden';

type Item = {
    id: number;
    name: string;
    category?: { id: number; name: string };
    base_price: number | string;
    cost_price: number | string;
    quantity: number;
    reserved_quantity: number;
    status: ItemStatus;
};
const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Back Office',
        href: route('back-office'),
    },
    {
        title: 'Item Management',
        href: route('item-management'),
    },
];

export default function ItemManagementPage({ items }: { items: Item[] }) {
    const [search, setSearch] = useState('');
    const [itemToDelete, setItemToDelete] = useState<Item | null>(null);
    const deleteForm = useForm({});
    const [updating, setUpdating] = useState<number | null>(null);

    const filteredItems = useMemo(() => {
        const normalizedSearch = search.toLowerCase();

        return items.filter(
            (item) => item.name.toLowerCase().includes(normalizedSearch) || item.category?.name.toLowerCase().includes(normalizedSearch),
        );
    }, [items, search]);

    const updateStatus = (item: Item) => {
        const nextStatus: ItemStatus = item.status === 'available' ? 'unavailable' : item.status === 'unavailable' ? 'hidden' : 'available';

        setUpdating(item.id);
        router.patch(
            route('items.update', item.id),
            { status: nextStatus },
            {
                preserveScroll: true,
                onFinish: () => setUpdating(null),
            },
        );
    };

    const deleteItem = () => {
        if (!itemToDelete) {
            return;
        }

        deleteForm.delete(route('items.destroy', itemToDelete.id), {
            preserveScroll: true,
            onSuccess: () => setItemToDelete(null),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Item Management" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="border-sidebar-border/70 dark:border-sidebar-border relative min-h-[100vh] flex-1 overflow-hidden rounded-xl border md:min-h-min">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead colSpan={10}>
                                    <div className="flex items-center justify-between gap-4">
                                        <div className="relative">
                                            <Search className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                                            <Input
                                                type="search"
                                                value={search}
                                                onChange={(event) => setSearch(event.target.value)}
                                                placeholder="Search items"
                                                className="pl-9"
                                            />
                                        </div>
                                        <Link
                                            href={route('create-item')}
                                            className="bg-primary text-primary-foreground hover:bg-primary/80 inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium"
                                        >
                                            <Plus className="size-4" />
                                            Add New
                                        </Link>
                                    </div>
                                </TableHead>
                            </TableRow>
                            <TableRow>
                                <TableHead className="w-[70px]">ID</TableHead>
                                <TableHead>Item Name</TableHead>
                                <TableHead>Category</TableHead>
                                <TableHead className="text-right">Price</TableHead>
                                <TableHead className="text-right">Cost</TableHead>
                                <TableHead className="text-right">Margin</TableHead>
                                <TableHead className="text-right">Stock</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {filteredItems.map((item) => (
                                <TableRow key={item.id}>
                                    <TableCell className="font-medium">{item.id}</TableCell>
                                    <TableCell className="font-medium uppercase">{item.name}</TableCell>
                                    <TableCell className="uppercase">{item.category?.name ?? 'Uncategorized'}</TableCell>
                                    <TableCell className="text-right">₱{Number(item.base_price).toFixed(2)}</TableCell>
                                    <TableCell className="text-right">₱{Number(item.cost_price).toFixed(2)}</TableCell>
                                    <TableCell className="text-right">
                                        {(((Number(item.base_price) - Number(item.cost_price)) / Number(item.base_price)) * 100).toFixed(2)}%
                                    </TableCell>
                                    <TableCell className="text-right">{item.quantity - item.reserved_quantity}</TableCell>
                                    <TableCell>
                                        <button
                                            type="button"
                                            disabled={updating === item.id}
                                            onClick={() => updateStatus(item)}
                                            className={`rounded-full px-3 py-1 text-xs font-medium text-white capitalize ${
                                                item.status === 'available'
                                                    ? 'bg-green-600'
                                                    : item.status === 'unavailable'
                                                      ? 'bg-yellow-600'
                                                      : 'bg-gray-500'
                                            }`}
                                        >
                                            {item.status}
                                        </button>
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center gap-2">
                                            <Link
                                                href={route('items.edit', item.id)}
                                                aria-label={`Update ${item.name}`}
                                                className="hover:bg-muted inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs font-medium"
                                            >
                                                <Pencil className="size-3" />
                                                Update
                                            </Link>
                                            <button
                                                type="button"
                                                aria-label={`Delete ${item.name}`}
                                                onClick={() => setItemToDelete(item)}
                                                className="text-destructive border-destructive/30 hover:bg-destructive/10 inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs font-medium"
                                            >
                                                <Trash2 className="size-3" />
                                                Delete
                                            </button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
            <Dialog open={itemToDelete !== null} onOpenChange={(open) => !open && setItemToDelete(null)}>
                <DialogContent>
                    <DialogTitle>Delete {itemToDelete?.name}?</DialogTitle>
                    <DialogDescription>This action cannot be undone. The item will be permanently deleted.</DialogDescription>
                    <DialogFooter>
                        <DialogClose asChild>
                            <button type="button" className="hover:bg-muted rounded-md border px-4 py-2 text-sm font-medium">
                                Cancel
                            </button>
                        </DialogClose>
                        <button
                            type="button"
                            onClick={deleteItem}
                            disabled={deleteForm.processing}
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90 rounded-md px-4 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {deleteForm.processing ? 'Deleting...' : 'Delete item'}
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

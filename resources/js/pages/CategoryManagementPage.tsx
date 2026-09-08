import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Back Office',
        href: route('back-office'),
    },
    {
        title: 'Category Management',
        href: route('category-management'),
    },
];

type CategoryStatus = 'active' | 'inactive';

type Category = {
    id: number;
    name: string;
    items_count?: number;
    status: CategoryStatus;
    is_visible_to_pos: boolean;
};

function SearchInput({ value, onChange }: { value: string; onChange: (value: string) => void }) {
    return (
        <div className="relative max-w-sm">
            <Search className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" />
            <Input type="search" value={value} onChange={(event) => onChange(event.target.value)} placeholder="Search categories" className="pl-9" />
        </div>
    );
}

export default function CategoryManagementPage({ categories }: { categories: Category[] }) {
    const [search, setSearch] = useState('');
    const [categoryToDelete, setCategoryToDelete] = useState<Category | null>(null);
    const deleteForm = useForm({});
    const [updating, setUpdating] = useState<Record<number, 'status' | 'visibility' | null>>({});
    const [statuses, setStatuses] = useState<Record<number, CategoryStatus>>(
        Object.fromEntries(categories.map((category) => [category.id, category.status])),
    );
    const [visibility, setVisibility] = useState<Record<number, boolean>>(
        Object.fromEntries(categories.map((category) => [category.id, category.is_visible_to_pos])),
    );

    const filteredCategories = useMemo(
        () => categories.filter((category) => category.name.toLowerCase().includes(search.toLowerCase())),
        [categories, search],
    );

    const updateCategory = (categoryId: number, field: 'status' | 'visibility', data: Record<string, string | boolean>) => {
        setUpdating((current) => ({ ...current, [categoryId]: field }));

        router.patch(route('categories.update', categoryId), data, {
            preserveScroll: true,
            onSuccess: () => {
                if (field === 'status') {
                    setStatuses((current) => ({ ...current, [categoryId]: data.status as CategoryStatus }));
                } else {
                    setVisibility((current) => ({ ...current, [categoryId]: data.is_visible_to_pos as boolean }));
                }
            },
            onFinish: () => setUpdating((current) => ({ ...current, [categoryId]: null })),
        });
    };

    const deleteCategory = () => {
        if (!categoryToDelete) {
            return;
        }

        deleteForm.delete(route('categories.destroy', categoryToDelete.id), {
            preserveScroll: true,
            onSuccess: () => setCategoryToDelete(null),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Category Management" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="border-sidebar-border/70 dark:border-sidebar-border relative min-h-[100vh] flex-1 rounded-xl border md:min-h-min">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead colSpan={6}>
                                    <div className="flex items-center justify-between gap-4">
                                        <SearchInput value={search} onChange={setSearch} />
                                        <Link
                                            href={route('create-category')}
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
                                <TableHead>Category Name</TableHead>
                                <TableHead className="text-right">Items</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Visible to POS</TableHead>
                                <TableHead>Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {filteredCategories.map((category) => {
                                const status = statuses[category.id];
                                const isVisible = visibility[category.id];

                                return (
                                    <TableRow key={category.id}>
                                        <TableCell className="font-medium">{category.id}</TableCell>
                                        <TableCell className="uppercase">{category.name}</TableCell>
                                        <TableCell className="text-right">{category.items_count ?? 0}</TableCell>
                                        <TableCell>
                                            <button
                                                type="button"
                                                disabled={updating[category.id] !== undefined && updating[category.id] !== null}
                                                onClick={() =>
                                                    updateCategory(category.id, 'status', { status: status === 'active' ? 'inactive' : 'active' })
                                                }
                                                className={`rounded-full px-3 py-1 text-xs font-medium text-white capitalize ${
                                                    status === 'active' ? 'bg-green-600' : 'bg-red-600'
                                                }`}
                                            >
                                                {status}
                                            </button>
                                        </TableCell>
                                        <TableCell>
                                            <button
                                                type="button"
                                                disabled={updating[category.id] !== undefined && updating[category.id] !== null}
                                                onClick={() => updateCategory(category.id, 'visibility', { is_visible_to_pos: !isVisible })}
                                                className={`rounded-full px-3 py-1 text-xs font-medium text-white ${
                                                    isVisible ? 'bg-green-600' : 'bg-gray-500'
                                                }`}
                                            >
                                                {isVisible ? 'Yes' : 'No'}
                                            </button>
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <Link
                                                    href={route('categories.edit', category.id)}
                                                    aria-label={`Update ${category.name}`}
                                                    className="hover:bg-muted inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs font-medium"
                                                >
                                                    <Pencil className="size-3" />
                                                    Update
                                                </Link>
                                                <button
                                                    type="button"
                                                    aria-label={`Delete ${category.name}`}
                                                    onClick={() => setCategoryToDelete(category)}
                                                    className="text-destructive border-destructive/30 hover:bg-destructive/10 inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs font-medium"
                                                >
                                                    <Trash2 className="size-3" />
                                                    Delete
                                                </button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            </div>
            <Dialog open={categoryToDelete !== null} onOpenChange={(open) => !open && setCategoryToDelete(null)}>
                <DialogContent>
                    <DialogTitle>Delete {categoryToDelete?.name}?</DialogTitle>
                    <DialogDescription>
                        This action cannot be undone. The category and its associated items will be permanently deleted.
                    </DialogDescription>
                    <DialogFooter>
                        <DialogClose asChild>
                            <button type="button" className="hover:bg-muted rounded-md border px-4 py-2 text-sm font-medium">
                                Cancel
                            </button>
                        </DialogClose>
                        <button
                            type="button"
                            onClick={deleteCategory}
                            disabled={deleteForm.processing}
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90 rounded-md px-4 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {deleteForm.processing ? 'Deleting...' : 'Delete category'}
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

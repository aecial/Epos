import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
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
    itemCount: number;
    status: CategoryStatus;
    visibleToPos: boolean;
};

const categories: Category[] = [
    { id: 1, name: 'Burgers', itemCount: 8, status: 'active', visibleToPos: true },
    { id: 2, name: 'Beverages', itemCount: 6, status: 'active', visibleToPos: true },
    { id: 3, name: 'Side Dishes', itemCount: 5, status: 'active', visibleToPos: true },
    { id: 4, name: 'Desserts', itemCount: 4, status: 'inactive', visibleToPos: false },
];

function SearchInput({ value, onChange }: { value: string; onChange: (value: string) => void }) {
    return (
        <div className="relative max-w-sm">
            <Search className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" />
            <Input type="search" value={value} onChange={(event) => onChange(event.target.value)} placeholder="Search categories" className="pl-9" />
        </div>
    );
}

export default function CategoryManagementPage() {
    const [search, setSearch] = useState('');
    const [statuses, setStatuses] = useState<Record<number, CategoryStatus>>(
        Object.fromEntries(categories.map((category) => [category.id, category.status])),
    );
    const [visibility, setVisibility] = useState<Record<number, boolean>>(
        Object.fromEntries(categories.map((category) => [category.id, category.visibleToPos])),
    );

    const filteredCategories = useMemo(() => categories.filter((category) => category.name.toLowerCase().includes(search.toLowerCase())), [search]);

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
                                        <button
                                            type="button"
                                            className="bg-primary text-primary-foreground hover:bg-primary/80 inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium"
                                        >
                                            <Plus className="size-4" />
                                            Add New
                                        </button>
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
                                        <TableCell>{category.name}</TableCell>
                                        <TableCell className="text-right">{category.itemCount}</TableCell>
                                        <TableCell>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setStatuses((current) => ({
                                                        ...current,
                                                        [category.id]: status === 'active' ? 'inactive' : 'active',
                                                    }))
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
                                                onClick={() => setVisibility((current) => ({ ...current, [category.id]: !isVisible }))}
                                                className={`rounded-full px-3 py-1 text-xs font-medium text-white ${
                                                    isVisible ? 'bg-green-600' : 'bg-gray-500'
                                                }`}
                                            >
                                                {isVisible ? 'Yes' : 'No'}
                                            </button>
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <button
                                                    type="button"
                                                    aria-label={`Update ${category.name}`}
                                                    className="hover:bg-muted inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs font-medium"
                                                >
                                                    <Pencil className="size-3" />
                                                    Update
                                                </button>
                                                <button
                                                    type="button"
                                                    aria-label={`Delete ${category.name}`}
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
        </AppLayout>
    );
}

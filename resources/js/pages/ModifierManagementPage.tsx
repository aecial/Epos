import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Layers3, Pencil, Plus, Search, SlidersHorizontal, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Back Office',
        href: route('back-office'),
    },
    {
        title: 'Modifier Management',
        href: '/modifier-management',
    },
];

type ModifierGroup = {
    id: number;
    name: string;
    is_required: boolean;
    modifiers_count?: number;
};

type Modifier = {
    id: number;
    modifier_group_id: number | null;
    name: string;
    status: 'active' | 'inactive';
    group?: { id: number; name: string } | null;
};

function SearchInput({ value, onChange, placeholder }: { value: string; onChange: (value: string) => void; placeholder: string }) {
    return (
        <div className="relative w-full">
            <Search className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" />
            <Input type="search" value={value} onChange={(event) => onChange(event.target.value)} placeholder={placeholder} className="pl-9" />
        </div>
    );
}

function StatusButton({ status, disabled, onClick }: { status: Modifier['status']; disabled: boolean; onClick: () => void }) {
    return (
        <button
            type="button"
            disabled={disabled}
            onClick={onClick}
            className={`rounded-full px-3 py-1 text-xs font-medium text-white capitalize disabled:cursor-not-allowed disabled:opacity-60 ${
                status === 'active' ? 'bg-green-600' : 'bg-red-600'
            }`}
        >
            {status}
        </button>
    );
}

export default function ModifierManagementPage({ modifierGroups, modifiers }: { modifierGroups: ModifierGroup[]; modifiers: Modifier[] }) {
    const [groupSearch, setGroupSearch] = useState('');
    const [modifierSearch, setModifierSearch] = useState('');
    const [updating, setUpdating] = useState<number | null>(null);
    const deleteForm = useForm({});

    const filteredGroups = useMemo(() => {
        const search = groupSearch.toLowerCase();
        return modifierGroups.filter((group) => group.name.toLowerCase().includes(search));
    }, [modifierGroups, groupSearch]);

    const filteredModifiers = useMemo(() => {
        const search = modifierSearch.toLowerCase();
        return modifiers.filter((modifier) => modifier.name.toLowerCase().includes(search) || modifier.group?.name.toLowerCase().includes(search));
    }, [modifiers, modifierSearch]);

    const updateStatus = (modifier: Modifier) => {
        setUpdating(modifier.id);
        router.patch(
            route('modifiers.update', modifier.id),
            { status: modifier.status === 'active' ? 'inactive' : 'active' },
            { preserveScroll: true, onFinish: () => setUpdating(null) },
        );
    };

    const deleteRecord = (type: 'group' | 'modifier', id: number) => {
        deleteForm.delete(route(type === 'group' ? 'modifier-groups.destroy' : 'modifiers.destroy', id), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Modifier Management" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="grid min-h-[100vh] flex-1 grid-cols-1 gap-4 md:min-h-min xl:grid-cols-2">
                    <section className="border-sidebar-border/70 dark:border-sidebar-border overflow-hidden rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead colSpan={5}>
                                        <div className="flex items-center gap-3 p-5">
                                            <Layers3 className="text-muted-foreground size-5 shrink-0" />
                                            <div className="flex min-w-0 flex-1 items-center justify-between gap-4">
                                                <div>
                                                    <h2 className="font-semibold">Modifier Groups</h2>
                                                    <p className="text-muted-foreground text-xs">Define reusable option sets.</p>
                                                </div>
                                                <Link
                                                    href={route('create-modifier-group')}
                                                    className="bg-primary text-primary-foreground inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium hover:opacity-90"
                                                >
                                                    <Plus className="size-4" /> Add New
                                                </Link>
                                            </div>
                                        </div>
                                    </TableHead>
                                </TableRow>
                                <TableRow>
                                    <TableHead colSpan={5}>
                                        <SearchInput value={groupSearch} onChange={setGroupSearch} placeholder="Search modifier groups" />
                                    </TableHead>
                                </TableRow>
                                <TableRow>
                                    <TableHead>ID</TableHead>
                                    <TableHead>Group Name</TableHead>
                                    <TableHead className="text-right">Modifiers</TableHead>
                                    <TableHead>Required</TableHead>
                                    <TableHead>Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filteredGroups.map((group) => (
                                    <TableRow key={group.id}>
                                        <TableCell className="font-medium">{group.id}</TableCell>
                                        <TableCell className="font-medium uppercase">{group.name}</TableCell>
                                        <TableCell className="text-right">{group.modifiers_count ?? 0}</TableCell>
                                        <TableCell>{group.is_required ? 'Yes' : 'No'}</TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <Link
                                                    href={route('modifier-groups.edit', group.id)}
                                                    aria-label={`Update ${group.name}`}
                                                    className="hover:bg-muted inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs font-medium"
                                                >
                                                    <Pencil className="size-3" />
                                                    Update
                                                </Link>
                                                <button
                                                    type="button"
                                                    aria-label={`Delete ${group.name}`}
                                                    onClick={() => deleteRecord('group', group.id)}
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
                    </section>

                    <section className="border-sidebar-border/70 dark:border-sidebar-border overflow-hidden rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead colSpan={5}>
                                        <div className="flex items-center gap-3 p-5">
                                            <SlidersHorizontal className="text-muted-foreground size-5 shrink-0" />
                                            <div className="flex min-w-0 flex-1 items-center justify-between gap-4">
                                                <div>
                                                    <h2 className="font-semibold">Modifiers</h2>
                                                    <p className="text-muted-foreground text-xs">Manage the choices available to menu items.</p>
                                                </div>
                                                <Link
                                                    href={route('create-modifier')}
                                                    className="bg-primary text-primary-foreground inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium hover:opacity-90"
                                                >
                                                    <Plus className="size-4" /> Add New
                                                </Link>
                                            </div>
                                        </div>
                                    </TableHead>
                                </TableRow>
                                <TableRow>
                                    <TableHead colSpan={5}>
                                        <SearchInput value={modifierSearch} onChange={setModifierSearch} placeholder="Search modifiers or groups" />
                                    </TableHead>
                                </TableRow>
                                <TableRow>
                                    <TableHead>ID</TableHead>
                                    <TableHead>Modifier</TableHead>
                                    <TableHead>Group</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filteredModifiers.map((modifier) => (
                                    <TableRow key={modifier.id}>
                                        <TableCell className="font-medium">{modifier.id}</TableCell>
                                        <TableCell className="font-medium uppercase">{modifier.name}</TableCell>
                                        <TableCell className="uppercase">{modifier.group?.name ?? 'Unassigned'}</TableCell>
                                        <TableCell>
                                            <StatusButton
                                                status={modifier.status}
                                                disabled={updating === modifier.id}
                                                onClick={() => updateStatus(modifier)}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <Link
                                                    href={route('modifiers.edit', modifier.id)}
                                                    aria-label={`Update ${modifier.name}`}
                                                    className="hover:bg-muted inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs font-medium"
                                                >
                                                    <Pencil className="size-3" />
                                                    Update
                                                </Link>
                                                <button
                                                    type="button"
                                                    aria-label={`Delete ${modifier.name}`}
                                                    onClick={() => deleteRecord('modifier', modifier.id)}
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
                    </section>
                </div>
            </div>
        </AppLayout>
    );
}

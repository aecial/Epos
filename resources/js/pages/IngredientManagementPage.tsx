import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Layers3, Pencil, Plus, Search, Trash2, Wheat } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Back Office',
        href: route('back-office'),
    },
    {
        title: 'Ingredient Management',
        href: '/ingredient-management',
    },
];

type IngredientStatus = 'active' | 'inactive';
type IngredientUnit = 'piece' | 'kg' | 'gram' | 'liter' | 'ml';

type IngredientGroup = {
    id: number;
    name: string;
    status: IngredientStatus;
    ingredients_count?: number;
};

type Ingredient = {
    id: number;
    name: string;
    unit: IngredientUnit;
    quantity: number | string;
    reserved_quantity: number | string;
    cost_per_unit: number | string;
    status: IngredientStatus;
    ingredient_group?: { id: number; name: string };
};

function SearchInput({ value, onChange, placeholder }: { value: string; onChange: (value: string) => void; placeholder: string }) {
    return (
        <div className="relative w-full">
            <Search className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" />
            <Input type="search" value={value} onChange={(event) => onChange(event.target.value)} placeholder={placeholder} className="pl-9" />
        </div>
    );
}

function StatusButton({ status, disabled, onClick }: { status: IngredientStatus; disabled: boolean; onClick: () => void }) {
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

export default function IngredientManagementPage({
    ingredientGroups,
    ingredients,
}: {
    ingredientGroups: IngredientGroup[];
    ingredients: Ingredient[];
}) {
    const [groupSearch, setGroupSearch] = useState('');
    const [ingredientSearch, setIngredientSearch] = useState('');
    const [updating, setUpdating] = useState<string | null>(null);
    const deleteForm = useForm({});

    const filteredGroups = useMemo(() => {
        const normalizedSearch = groupSearch.toLowerCase();

        return ingredientGroups.filter((group) => group.name.toLowerCase().includes(normalizedSearch));
    }, [ingredientGroups, groupSearch]);

    const filteredIngredients = useMemo(() => {
        const normalizedSearch = ingredientSearch.toLowerCase();

        return ingredients.filter(
            (ingredient) =>
                ingredient.name.toLowerCase().includes(normalizedSearch) ||
                ingredient.ingredient_group?.name.toLowerCase().includes(normalizedSearch),
        );
    }, [ingredients, ingredientSearch]);

    const updateStatus = (type: 'group' | 'ingredient', record: IngredientGroup | Ingredient) => {
        const nextStatus = record.status === 'active' ? 'inactive' : 'active';
        const routeName = type === 'group' ? 'ingredient-groups.update' : 'ingredients.update';

        setUpdating(`${type}-${record.id}`);
        router.patch(
            route(routeName, record.id),
            { status: nextStatus },
            {
                preserveScroll: true,
                onFinish: () => setUpdating(null),
            },
        );
    };

    const deleteRecord = (type: 'group' | 'ingredient', id: number) => {
        const routeName = type === 'group' ? 'ingredient-groups.destroy' : 'ingredients.destroy';

        deleteForm.delete(route(routeName, id), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Ingredient Management" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="grid min-h-[100vh] flex-1 grid-cols-1 gap-4 md:min-h-min xl:grid-cols-2">
                    <section className="border-sidebar-border/70 dark:border-sidebar-border overflow-hidden rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead colSpan={5}>
                                        <div className="flex items-center gap-3">
                                            <Layers3 className="text-muted-foreground size-5 shrink-0" />
                                            <div className="flex min-w-0 flex-1 items-center justify-between gap-4">
                                                <div>
                                                    <h2 className="font-semibold">Ingredient Groups</h2>
                                                    <p className="text-muted-foreground text-xs">Organize shared stock by category.</p>
                                                </div>
                                                <Link
                                                    href={route('create-ingredient-group')}
                                                    className="bg-primary text-primary-foreground hover:bg-primary/80 inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium"
                                                >
                                                    <Plus className="size-4" />
                                                    Add New
                                                </Link>
                                            </div>
                                        </div>
                                    </TableHead>
                                </TableRow>
                                <TableRow>
                                    <TableHead colSpan={5}>
                                        <SearchInput value={groupSearch} onChange={setGroupSearch} placeholder="Search groups" />
                                    </TableHead>
                                </TableRow>
                                <TableRow>
                                    <TableHead className="w-[70px]">ID</TableHead>
                                    <TableHead>Group Name</TableHead>
                                    <TableHead className="text-right">Ingredients</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filteredGroups.map((group) => (
                                    <TableRow key={group.id}>
                                        <TableCell className="font-medium">{group.id}</TableCell>
                                        <TableCell className="font-medium uppercase">{group.name}</TableCell>
                                        <TableCell className="text-right">{group.ingredients_count ?? 0}</TableCell>
                                        <TableCell>
                                            <StatusButton
                                                status={group.status}
                                                disabled={updating === `group-${group.id}`}
                                                onClick={() => updateStatus('group', group)}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <Link
                                                    href={route('ingredient-groups.edit', group.id)}
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
                                    <TableHead colSpan={7}>
                                        <div className="flex items-center gap-3">
                                            <Wheat className="text-muted-foreground size-5 shrink-0" />
                                            <div className="flex min-w-0 flex-1 items-center justify-between gap-4">
                                                <div>
                                                    <h2 className="font-semibold">Ingredients</h2>
                                                    <p className="text-muted-foreground text-xs">Track stock used by recipe items.</p>
                                                </div>
                                                <Link
                                                    href={route('create-ingredient')}
                                                    className="bg-primary text-primary-foreground hover:bg-primary/80 inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium"
                                                >
                                                    <Plus className="size-4" />
                                                    Add New
                                                </Link>
                                            </div>
                                        </div>
                                    </TableHead>
                                </TableRow>
                                <TableRow>
                                    <TableHead colSpan={7}>
                                        <SearchInput
                                            value={ingredientSearch}
                                            onChange={setIngredientSearch}
                                            placeholder="Search ingredients or groups"
                                        />
                                    </TableHead>
                                </TableRow>
                                <TableRow>
                                    <TableHead className="w-[70px]">ID</TableHead>
                                    <TableHead>Ingredient</TableHead>
                                    <TableHead>Group</TableHead>
                                    <TableHead className="text-right">Available</TableHead>
                                    <TableHead className="text-right">Cost / Unit</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filteredIngredients.map((ingredient) => {
                                    const available = Number(ingredient.quantity) - Number(ingredient.reserved_quantity);

                                    return (
                                        <TableRow key={ingredient.id}>
                                            <TableCell className="font-medium">{ingredient.id}</TableCell>
                                            <TableCell className="font-medium uppercase">{ingredient.name}</TableCell>
                                            <TableCell className="uppercase">{ingredient.ingredient_group?.name ?? 'Unassigned'}</TableCell>
                                            <TableCell className="text-right">
                                                {available.toFixed(3)} {ingredient.unit}
                                            </TableCell>
                                            <TableCell className="text-right">₱{Number(ingredient.cost_per_unit).toFixed(2)}</TableCell>
                                            <TableCell>
                                                <StatusButton
                                                    status={ingredient.status}
                                                    disabled={updating === `ingredient-${ingredient.id}`}
                                                    onClick={() => updateStatus('ingredient', ingredient)}
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <Link
                                                        href={route('ingredients.edit', ingredient.id)}
                                                        aria-label={`Update ${ingredient.name}`}
                                                        className="hover:bg-muted inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs font-medium"
                                                    >
                                                        <Pencil className="size-3" />
                                                        Update
                                                    </Link>
                                                    <button
                                                        type="button"
                                                        aria-label={`Delete ${ingredient.name}`}
                                                        onClick={() => deleteRecord('ingredient', ingredient.id)}
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
                    </section>
                </div>
            </div>
        </AppLayout>
    );
}

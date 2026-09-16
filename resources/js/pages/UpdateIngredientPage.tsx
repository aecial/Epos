import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

type IngredientGroup = { id: number; name: string };
type IngredientUnit = 'piece' | 'kg' | 'gram' | 'liter' | 'ml';
type IngredientStatus = 'active' | 'inactive';
type Ingredient = {
    id: number;
    name: string;
    ingredient_group_id: number;
    unit: IngredientUnit;
    quantity: number | string;
    reserved_quantity: number | string;
    cost_per_unit: number | string;
    status: IngredientStatus;
    ingredientGroup?: { id: number; name: string };
};

export default function UpdateIngredientPage({ ingredient, ingredientGroups }: { ingredient: Ingredient; ingredientGroups: IngredientGroup[] }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Back Office', href: route('back-office') },
        { title: 'Ingredient Management', href: route('ingredient-management') },
        { title: `Update ${ingredient.name}`, href: route('ingredients.edit', ingredient.id) },
    ];
    const form = useForm({
        ingredient_group_id: ingredient.ingredient_group_id,
        name: ingredient.name,
        unit: ingredient.unit,
        quantity: String(ingredient.quantity),
        cost_per_unit: String(ingredient.cost_per_unit),
        status: ingredient.status,
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.patch(route('ingredients.update', ingredient.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Update ${ingredient.name}`} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="bg-card mx-auto w-full max-w-2xl rounded-xl border p-6 shadow-sm">
                    <div className="mb-6">
                        <h1 className="text-xl font-semibold">Update ingredient</h1>
                        <p className="text-muted-foreground mt-1 text-sm">Update the shared ingredient stock details.</p>
                    </div>

                    <form onSubmit={submit} className="grid gap-5 sm:grid-cols-2">
                        <Field label="Name" error={form.errors.name} className="sm:col-span-2">
                            <input
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                required
                                autoComplete="off"
                                className="field"
                            />
                        </Field>
                        <Field label="Ingredient group" error={form.errors.ingredient_group_id}>
                            <select
                                value={form.data.ingredient_group_id}
                                onChange={(event) => form.setData('ingredient_group_id', Number(event.target.value))}
                                required
                                className="field"
                            >
                                {ingredientGroups.map((group) => (
                                    <option key={group.id} value={group.id}>
                                        {group.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Unit" error={form.errors.unit}>
                            <select
                                value={form.data.unit}
                                onChange={(event) => form.setData('unit', event.target.value as IngredientUnit)}
                                required
                                className="field"
                            >
                                <option value="piece">Piece</option>
                                <option value="kg">Kilogram</option>
                                <option value="gram">Gram</option>
                                <option value="liter">Liter</option>
                                <option value="ml">Milliliter</option>
                            </select>
                        </Field>
                        <Field label="Quantity" error={form.errors.quantity}>
                            <input
                                type="number"
                                min="0"
                                step="0.001"
                                value={form.data.quantity}
                                onChange={(event) => form.setData('quantity', event.target.value)}
                                className="field"
                            />
                        </Field>
                        <Field label="Cost per unit" error={form.errors.cost_per_unit}>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                value={form.data.cost_per_unit}
                                onChange={(event) => form.setData('cost_per_unit', event.target.value)}
                                className="field"
                            />
                        </Field>
                        <Field label="Status" error={form.errors.status}>
                            <select
                                value={form.data.status}
                                onChange={(event) => form.setData('status', event.target.value as IngredientStatus)}
                                className="field"
                            >
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </Field>
                        <div className="flex items-end pb-2 text-sm">
                            <span className="text-muted-foreground">
                                Reserved quantity: {Number(ingredient.reserved_quantity).toFixed(3)} {ingredient.unit}
                            </span>
                        </div>
                        <div className="flex justify-end gap-3 pt-2 sm:col-span-2">
                            <button
                                type="button"
                                onClick={() => window.history.back()}
                                className="hover:bg-muted rounded-md border px-4 py-2 text-sm font-medium"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={form.processing || ingredientGroups.length === 0}
                                className="bg-primary text-primary-foreground rounded-md px-4 py-2 text-sm font-medium hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {form.processing ? 'Updating...' : 'Update ingredient'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}

function Field({ label, error, className = '', children }: { label: string; error?: string; className?: string; children: React.ReactNode }) {
    return (
        <div className={`space-y-2 ${className}`}>
            <label className="text-sm font-medium">{label}</label>
            {children}
            {error && <p className="text-destructive text-sm">{error}</p>}
        </div>
    );
}

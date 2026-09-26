import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

type Category = { id: number; name: string; type: 'menu' | 'special' };
type ItemStatus = 'available' | 'unavailable' | 'hidden';
type InventoryType = 'direct' | 'recipe' | 'none';
type EntryMode = 'fixed' | 'price' | 'name_price';

const pricingHelp: Record<EntryMode, string> = {
    fixed: 'Added to a ticket at the amount below, like a normal item (e.g. a packaging fee).',
    price: 'Fee item: the cashier types the amount on POS (e.g. a delivery fee). The suggested amount is only pre-filled.',
    name_price: 'Custom item: the cashier types both the name and the amount on POS (e.g. an off-menu dish). The kitchen sees the typed name.',
};
type RecipeIngredient = { id: number; name: string; unit: 'piece' | 'kg' | 'gram' | 'liter' | 'ml' };
type ItemRecipeRow = {
    ingredient_id: number;
    quantity_required: string;
    unit: RecipeIngredient['unit'];
};
type ModifierOption = { id: number; name: string };
type ModifierGroupOption = { id: number; name: string; is_required: boolean; modifiers: ModifierOption[] };
type ItemModifierRow = {
    modifier_id: number;
    price_modifier: string;
    display_order: number;
};

export default function CreateItemPage({
    categories,
    ingredients,
    modifierGroups,
}: {
    categories: Category[];
    ingredients: RecipeIngredient[];
    modifierGroups: ModifierGroupOption[];
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Back Office', href: route('back-office') },
        { title: 'Item Management', href: route('item-management') },
        { title: 'Create Item', href: route('create-item') },
    ];
    const form = useForm({
        category_id: categories[0]?.id ?? '',
        name: '',
        base_price: '',
        cost_price: '0',
        quantity: '0',
        inventory_type: 'direct' as InventoryType,
        entry_mode: (categories[0]?.type === 'special' ? 'price' : 'fixed') as EntryMode,
        image_url: '',
        status: 'available' as ItemStatus,
        ingredients: [] as ItemRecipeRow[],
        modifiers: [] as ItemModifierRow[],
    });

    // Special items (fees and custom items) live in a special category: no inventory, cost, recipe or
    // modifiers, and the cashier may type the price (and name) on POS. The server enforces the same rules.
    const isSpecial = categories.find((category) => category.id === Number(form.data.category_id))?.type === 'special';
    const isCustom = isSpecial && form.data.entry_mode === 'name_price';
    const amountLabel = !isSpecial ? 'Base price' : form.data.entry_mode === 'price' ? 'Suggested amount' : 'Amount';

    // Picking a special category defaults to a Fee item; leaving it resets to a normal fixed-price item.
    const changeCategory = (categoryId: number) => {
        const nextIsSpecial = categories.find((category) => category.id === categoryId)?.type === 'special';

        form.setData((data) => ({
            ...data,
            category_id: categoryId,
            entry_mode: nextIsSpecial ? (isSpecial ? data.entry_mode : 'price') : 'fixed',
        }));
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.transform((data) =>
            isSpecial
                ? {
                      ...data,
                      inventory_type: 'none',
                      quantity: '0',
                      cost_price: '0',
                      base_price: isCustom || data.base_price === '' ? '0' : data.base_price,
                      ingredients: [],
                      modifiers: [],
                  }
                : { ...data, entry_mode: 'fixed' },
        );
        form.post(route('items.store'));
    };

    const addRecipeRow = () => {
        const ingredient = ingredients.find((candidate) => !form.data.ingredients.some((row) => row.ingredient_id === candidate.id));

        if (!ingredient) {
            return;
        }

        form.setData('ingredients', [...form.data.ingredients, { ingredient_id: ingredient.id, quantity_required: '1', unit: ingredient.unit }]);
    };

    const toggleModifier = (modifierId: number) => {
        const isSelected = form.data.modifiers.some((row) => row.modifier_id === modifierId);

        if (isSelected) {
            form.setData(
                'modifiers',
                form.data.modifiers.filter((row) => row.modifier_id !== modifierId),
            );
            return;
        }

        form.setData('modifiers', [
            ...form.data.modifiers,
            { modifier_id: modifierId, price_modifier: '0', display_order: form.data.modifiers.length },
        ]);
    };

    const updateModifierPrice = (modifierId: number, price: string) => {
        form.setData(
            'modifiers',
            form.data.modifiers.map((row) => (row.modifier_id === modifierId ? { ...row, price_modifier: price } : row)),
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create Item" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="bg-card mx-auto w-full max-w-2xl rounded-xl border p-6 shadow-sm">
                    <div className="mb-6">
                        <h1 className="text-xl font-semibold">Create item</h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            {isSpecial
                                ? 'Add a special item: a fee or custom item the cashier prices on POS. It never tracks inventory.'
                                : 'Add a menu item to a category.'}
                        </p>
                    </div>
                    <form onSubmit={submit} className="grid gap-5 sm:grid-cols-2">
                        <Field label={isCustom ? 'Button label' : 'Name'} error={form.errors.name} className="sm:col-span-2">
                            <input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required className="field" />
                            {isCustom && (
                                <p className="text-muted-foreground text-xs">
                                    Shown on the POS button. The cashier types the real name when adding it to a ticket.
                                </p>
                            )}
                        </Field>
                        <Field label="Category" error={form.errors.category_id}>
                            <select
                                value={form.data.category_id}
                                onChange={(event) => changeCategory(Number(event.target.value))}
                                required
                                className="field"
                            >
                                {categories.map((category) => (
                                    <option key={category.id} value={category.id}>
                                        {category.name}
                                        {category.type === 'special' ? ' (Special)' : ''}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Status" error={form.errors.status}>
                            <select
                                value={form.data.status}
                                onChange={(event) => form.setData('status', event.target.value as ItemStatus)}
                                className="field"
                            >
                                <option value="available">Available</option>
                                <option value="unavailable">Unavailable</option>
                                <option value="hidden">Hidden</option>
                            </select>
                        </Field>
                        {isSpecial && (
                            <Field label="Pricing" error={form.errors.entry_mode} className="sm:col-span-2">
                                <select
                                    value={form.data.entry_mode}
                                    onChange={(event) => form.setData('entry_mode', event.target.value as EntryMode)}
                                    className="field"
                                >
                                    <option value="fixed">Fixed amount (fee with a set price)</option>
                                    <option value="price">Fee item: cashier enters the amount</option>
                                    <option value="name_price">Custom item: cashier enters the name and amount</option>
                                </select>
                                <p className="text-muted-foreground text-xs">{pricingHelp[form.data.entry_mode]}</p>
                            </Field>
                        )}
                        {!isSpecial && (
                            <Field label="Inventory type" error={form.errors.inventory_type}>
                                <select
                                    value={form.data.inventory_type}
                                    onChange={(event) => form.setData('inventory_type', event.target.value as InventoryType)}
                                    className="field"
                                >
                                    <option value="direct">Direct item stock</option>
                                    <option value="recipe">Recipe ingredients</option>
                                    <option value="none">No inventory tracking</option>
                                </select>
                            </Field>
                        )}
                        {!isCustom && (
                            <Field label={amountLabel} error={form.errors.base_price}>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={form.data.base_price}
                                    onChange={(event) => form.setData('base_price', event.target.value)}
                                    required={!isSpecial}
                                    className="field"
                                />
                            </Field>
                        )}
                        {!isSpecial && (
                            <Field label="Cost price" error={form.errors.cost_price}>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={form.data.cost_price}
                                    onChange={(event) => form.setData('cost_price', event.target.value)}
                                    className="field"
                                />
                            </Field>
                        )}
                        {!isSpecial && (
                            <Field label="Quantity" error={form.errors.quantity}>
                                <input
                                    type="number"
                                    step="1"
                                    min="0"
                                    value={form.data.quantity}
                                    onChange={(event) => form.setData('quantity', event.target.value)}
                                    className="field"
                                />
                            </Field>
                        )}
                        <Field label="Image URL" error={form.errors.image_url}>
                            <input
                                type="url"
                                value={form.data.image_url}
                                onChange={(event) => form.setData('image_url', event.target.value)}
                                className="field"
                            />
                        </Field>
                        {!isSpecial && form.data.inventory_type === 'recipe' && (
                            <div className="space-y-4 border-t pt-6 sm:col-span-2">
                                <div>
                                    <h2 className="font-semibold">Recipe ingredients</h2>
                                    <p className="text-muted-foreground mt-1 text-sm">Define the shared stock consumed by one serving.</p>
                                </div>
                                {form.data.ingredients.map((row: ItemRecipeRow, index: number) => {
                                    const selectedIngredient = ingredients.find((ingredient) => ingredient.id === row.ingredient_id);

                                    return (
                                        <div key={`${row.ingredient_id}-${index}`} className="grid gap-3 sm:grid-cols-[1fr_140px_auto]">
                                            <select
                                                value={row.ingredient_id}
                                                onChange={(event) => {
                                                    const nextIngredient = ingredients.find(
                                                        (ingredient) => ingredient.id === Number(event.target.value),
                                                    );
                                                    const nextRows = [...form.data.ingredients];
                                                    nextRows[index] = {
                                                        ...row,
                                                        ingredient_id: Number(event.target.value),
                                                        unit: nextIngredient?.unit ?? row.unit,
                                                    };
                                                    form.setData('ingredients', nextRows);
                                                }}
                                                className="field"
                                            >
                                                {ingredients.map((ingredient) => (
                                                    <option key={ingredient.id} value={ingredient.id}>
                                                        {ingredient.name}
                                                    </option>
                                                ))}
                                            </select>
                                            <input
                                                type="number"
                                                min="0.001"
                                                step="0.001"
                                                value={row.quantity_required}
                                                onChange={(event) => {
                                                    const nextRows = [...form.data.ingredients];
                                                    nextRows[index] = { ...row, quantity_required: event.target.value };
                                                    form.setData('ingredients', nextRows);
                                                }}
                                                className="field"
                                            />
                                            <div className="flex items-center gap-2">
                                                <span className="text-muted-foreground min-w-12 text-sm">{selectedIngredient?.unit ?? row.unit}</span>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        form.setData(
                                                            'ingredients',
                                                            form.data.ingredients.filter((_, rowIndex) => rowIndex !== index),
                                                        )
                                                    }
                                                    className="text-destructive border-destructive/30 hover:bg-destructive/10 rounded-md border px-2 py-1 text-xs font-medium"
                                                >
                                                    Remove
                                                </button>
                                            </div>
                                        </div>
                                    );
                                })}
                                {form.errors.ingredients && <p className="text-destructive text-sm">{form.errors.ingredients}</p>}
                                <button
                                    type="button"
                                    onClick={addRecipeRow}
                                    disabled={ingredients.length === form.data.ingredients.length}
                                    className="hover:bg-muted rounded-md border px-3 py-2 text-sm font-medium disabled:opacity-60"
                                >
                                    Add ingredient
                                </button>
                            </div>
                        )}
                        {!isSpecial && modifierGroups.length > 0 && (
                            <div className="space-y-4 border-t pt-6 sm:col-span-2">
                                <div>
                                    <h2 className="font-semibold">Modifiers</h2>
                                    <p className="text-muted-foreground mt-1 text-sm">
                                        Choose the variant options customers can pick for this item on POS.
                                    </p>
                                </div>
                                <div className="space-y-4">
                                    {modifierGroups.map((group) => (
                                        <div key={group.id} className="space-y-2">
                                            <p className="text-sm font-medium">
                                                {group.name}
                                                {group.is_required && (
                                                    <span className="text-muted-foreground ml-1 text-xs font-normal">(required group)</span>
                                                )}
                                            </p>
                                            <div className="space-y-2">
                                                {group.modifiers.map((modifier) => {
                                                    const row = form.data.modifiers.find((candidate) => candidate.modifier_id === modifier.id);

                                                    return (
                                                        <div key={modifier.id} className="flex items-center gap-3">
                                                            <label className="flex flex-1 items-center gap-2 text-sm">
                                                                <input
                                                                    type="checkbox"
                                                                    checked={!!row}
                                                                    onChange={() => toggleModifier(modifier.id)}
                                                                    className="size-4"
                                                                />
                                                                {modifier.name}
                                                            </label>
                                                            {row && (
                                                                <input
                                                                    type="number"
                                                                    step="0.01"
                                                                    value={row.price_modifier}
                                                                    onChange={(event) => updateModifierPrice(modifier.id, event.target.value)}
                                                                    placeholder="+0.00"
                                                                    className="field w-28"
                                                                />
                                                            )}
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                {form.errors.modifiers && <p className="text-destructive text-sm">{form.errors.modifiers}</p>}
                            </div>
                        )}
                        <FormActions processing={form.processing} label="Create item" />
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

function FormActions({ processing, label }: { processing: boolean; label: string }) {
    return (
        <div className="flex justify-end gap-3 pt-2 sm:col-span-2">
            <button type="button" onClick={() => window.history.back()} className="hover:bg-muted rounded-md border px-4 py-2 text-sm font-medium">
                Cancel
            </button>
            <button
                type="submit"
                disabled={processing}
                className="bg-primary text-primary-foreground rounded-md px-4 py-2 text-sm font-medium hover:opacity-90 disabled:opacity-60"
            >
                {processing ? 'Saving...' : label}
            </button>
        </div>
    );
}

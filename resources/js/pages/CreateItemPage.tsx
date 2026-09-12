import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

type Category = { id: number; name: string };
type ItemStatus = 'available' | 'unavailable' | 'hidden';
type InventoryType = 'direct' | 'recipe' | 'none';

export default function CreateItemPage({ categories }: { categories: Category[] }) {
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
        image_url: '',
        status: 'available' as ItemStatus,
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(route('items.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create Item" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="bg-card mx-auto w-full max-w-2xl rounded-xl border p-6 shadow-sm">
                    <div className="mb-6">
                        <h1 className="text-xl font-semibold">Create item</h1>
                        <p className="text-muted-foreground mt-1 text-sm">Add a menu item to a category.</p>
                    </div>
                    <form onSubmit={submit} className="grid gap-5 sm:grid-cols-2">
                        <Field label="Name" error={form.errors.name} className="sm:col-span-2">
                            <input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required className="field" />
                        </Field>
                        <Field label="Category" error={form.errors.category_id}>
                            <select
                                value={form.data.category_id}
                                onChange={(event) => form.setData('category_id', Number(event.target.value))}
                                required
                                className="field"
                            >
                                {categories.map((category) => (
                                    <option key={category.id} value={category.id}>
                                        {category.name}
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
                        <Field label="Base price" error={form.errors.base_price}>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                value={form.data.base_price}
                                onChange={(event) => form.setData('base_price', event.target.value)}
                                required
                                className="field"
                            />
                        </Field>
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
                        <Field label="Image URL" error={form.errors.image_url}>
                            <input
                                type="url"
                                value={form.data.image_url}
                                onChange={(event) => form.setData('image_url', event.target.value)}
                                className="field"
                            />
                        </Field>
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

import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

type ModifierGroup = { id: number; name: string; is_required: boolean };

export default function UpdateModifierGroupPage({ modifierGroup }: { modifierGroup: ModifierGroup }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Back Office', href: route('back-office') },
        { title: 'Modifier Management', href: route('modifier-management') },
        { title: `Update ${modifierGroup.name}`, href: route('modifier-groups.edit', modifierGroup.id) },
    ];
    const form = useForm({ name: modifierGroup.name, is_required: modifierGroup.is_required });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.patch(route('modifier-groups.update', modifierGroup.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Update ${modifierGroup.name}`} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="bg-card mx-auto w-full max-w-2xl rounded-xl border p-6 shadow-sm">
                    <div className="mb-6"><h1 className="text-xl font-semibold">Update modifier group</h1><p className="text-muted-foreground mt-1 text-sm">Update the reusable modifier group.</p></div>
                    <form onSubmit={submit} className="space-y-5">
                        <div className="space-y-2"><label htmlFor="name" className="text-sm font-medium">Name</label><input id="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required className="field" />{form.errors.name && <p className="text-destructive text-sm">{form.errors.name}</p>}</div>
                        <label className="flex items-center gap-2 text-sm font-medium"><input type="checkbox" checked={form.data.is_required} onChange={(event) => form.setData('is_required', event.target.checked)} /> Customer must choose an option</label>
                        {form.errors.is_required && <p className="text-destructive text-sm">{form.errors.is_required}</p>}
                        <Actions processing={form.processing} label="Update modifier group" />
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}

function Actions({ processing, label }: { processing: boolean; label: string }) {
    return <div className="flex justify-end gap-3 pt-2"><button type="button" onClick={() => window.history.back()} className="hover:bg-muted rounded-md border px-4 py-2 text-sm font-medium">Cancel</button><button type="submit" disabled={processing} className="bg-primary text-primary-foreground rounded-md px-4 py-2 text-sm font-medium hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60">{processing ? 'Saving...' : label}</button></div>;
}

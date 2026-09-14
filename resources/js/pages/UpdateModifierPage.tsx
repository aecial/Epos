import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

type ModifierGroup = { id: number; name: string };
type ModifierStatus = 'active' | 'inactive';
type Modifier = { id: number; modifier_group_id: number | null; name: string; status: ModifierStatus; group?: { id: number; name: string } | null };

export default function UpdateModifierPage({ modifier, modifierGroups }: { modifier: Modifier; modifierGroups: ModifierGroup[] }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Back Office', href: route('back-office') },
        { title: 'Modifier Management', href: route('modifier-management') },
        { title: `Update ${modifier.name}`, href: route('modifiers.edit', modifier.id) },
    ];
    const form = useForm({ modifier_group_id: modifier.modifier_group_id ?? '', name: modifier.name, status: modifier.status });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.patch(route('modifiers.update', modifier.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Update ${modifier.name}`} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="bg-card mx-auto w-full max-w-2xl rounded-xl border p-6 shadow-sm">
                    <div className="mb-6"><h1 className="text-xl font-semibold">Update modifier</h1><p className="text-muted-foreground mt-1 text-sm">Update the modifier choice and its group.</p></div>
                    <form onSubmit={submit} className="space-y-5">
                        <Field label="Name" error={form.errors.name}><input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required className="field" /></Field>
                        <Field label="Modifier group" error={form.errors.modifier_group_id}><select value={form.data.modifier_group_id} onChange={(event) => form.setData('modifier_group_id', event.target.value ? Number(event.target.value) : '')} className="field"><option value="">Unassigned</option>{modifierGroups.map((group) => <option key={group.id} value={group.id}>{group.name}</option>)}</select></Field>
                        <Field label="Status" error={form.errors.status}><select value={form.data.status} onChange={(event) => form.setData('status', event.target.value as ModifierStatus)} className="field"><option value="active">Active</option><option value="inactive">Inactive</option></select></Field>
                        <Actions processing={form.processing} label="Update modifier" />
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return <div className="space-y-2"><label className="text-sm font-medium">{label}</label>{children}{error && <p className="text-destructive text-sm">{error}</p>}</div>;
}

function Actions({ processing, label }: { processing: boolean; label: string }) {
    return <div className="flex justify-end gap-3 pt-2"><button type="button" onClick={() => window.history.back()} className="hover:bg-muted rounded-md border px-4 py-2 text-sm font-medium">Cancel</button><button type="submit" disabled={processing} className="bg-primary text-primary-foreground rounded-md px-4 py-2 text-sm font-medium hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60">{processing ? 'Saving...' : label}</button></div>;
}

import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

export default function CreateModifierGroupPage() {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Back Office', href: route('back-office') },
        { title: 'Modifier Management', href: route('modifier-management') },
        { title: 'Create Modifier Group', href: route('create-modifier-group') },
    ];
    const form = useForm({ name: '', is_required: false });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(route('modifier-groups.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create Modifier Group" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="bg-card mx-auto w-full max-w-2xl rounded-xl border p-6 shadow-sm">
                    <div className="mb-6"><h1 className="text-xl font-semibold">Create modifier group</h1><p className="text-muted-foreground mt-1 text-sm">Create a reusable set of modifier choices.</p></div>
                    <form onSubmit={submit} className="space-y-5">
                        <Field label="Name" error={form.errors.name}>
                            <input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required autoComplete="off" className="field" placeholder="e.g. Size" />
                        </Field>
                        <label className="flex items-center gap-2 text-sm font-medium"><input type="checkbox" checked={form.data.is_required} onChange={(event) => form.setData('is_required', event.target.checked)} /> Customer must choose an option</label>
                        {form.errors.is_required && <p className="text-destructive text-sm">{form.errors.is_required}</p>}
                        <Actions processing={form.processing} label="Create modifier group" />
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

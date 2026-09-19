import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Back Office', href: route('back-office') },
    { title: 'Employee Management', href: route('employee-management') },
    { title: 'Add User', href: route('users.create') },
];

type UserRole = 'manager' | 'cashier';

export default function CreateUserPage() {
    const form = useForm({
        name: '',
        username: '',
        password: '',
        passcode: '',
        role: 'cashier' as UserRole,
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(route('users.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add User" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="bg-card mx-auto w-full max-w-2xl rounded-xl border p-6 shadow-sm">
                    <div className="mb-6">
                        <h1 className="text-xl font-semibold">Add user</h1>
                        <p className="text-muted-foreground mt-1 text-sm">Create a manager or cashier account.</p>
                    </div>

                    <form onSubmit={submit} className="space-y-5">
                        <div className="grid gap-5 sm:grid-cols-2">
                            <div className="space-y-2">
                                <label htmlFor="name" className="text-sm font-medium">
                                    Name <span className="text-destructive">*</span>
                                </label>
                                <input
                                    id="name"
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                    required
                                    className="bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm outline-none focus:ring-2"
                                />
                                {form.errors.name && <p className="text-destructive text-sm">{form.errors.name}</p>}
                            </div>
                            <div className="space-y-2">
                                <label htmlFor="username" className="text-sm font-medium">
                                    Username <span className="text-destructive">*</span>
                                </label>
                                <input
                                    id="username"
                                    value={form.data.username}
                                    onChange={(event) => form.setData('username', event.target.value)}
                                    required
                                    autoComplete="username"
                                    className="bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm outline-none focus:ring-2"
                                />
                                {form.errors.username && <p className="text-destructive text-sm">{form.errors.username}</p>}
                            </div>
                            <div className="space-y-2">
                                <label htmlFor="password" className="text-sm font-medium">
                                    Password <span className="text-destructive">*</span>
                                </label>
                                <input
                                    id="password"
                                    type="password"
                                    value={form.data.password}
                                    onChange={(event) => form.setData('password', event.target.value)}
                                    required
                                    minLength={8}
                                    autoComplete="new-password"
                                    className="bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm outline-none focus:ring-2"
                                />
                                {form.errors.password && <p className="text-destructive text-sm">{form.errors.password}</p>}
                            </div>
                            {form.data.role == 'manager' ? (
                                <div className="space-y-2">
                                    <label htmlFor="passcode" className="text-sm font-medium">
                                        Passcode
                                    </label>
                                    <input
                                        id="passcode"
                                        type="password"
                                        inputMode="numeric"
                                        maxLength={4}
                                        value={form.data.passcode}
                                        onChange={(event) => form.setData('passcode', event.target.value.replace(/\D/g, '').slice(0, 4))}
                                        autoComplete="off"
                                        placeholder="Optional 4-digit PIN"
                                        className="bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm outline-none focus:ring-2"
                                    />
                                    {form.errors.passcode && <p className="text-destructive text-sm">{form.errors.passcode}</p>}
                                </div>
                            ) : (
                                ''
                            )}
                        </div>
                        <div className="space-y-2">
                            <label htmlFor="role" className="text-sm font-medium">
                                Role <span className="text-destructive">*</span>
                            </label>
                            <select
                                id="role"
                                value={form.data.role}
                                onChange={(event) => {
                                    const role = event.target.value as UserRole;

                                    form.setData('role', role);
                                    if (role === 'cashier') {
                                        form.setData('passcode', '');
                                    }
                                }}
                                className="bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm outline-none focus:ring-2"
                            >
                                <option value="cashier">Cashier</option>
                                <option value="manager">Manager</option>
                            </select>
                            {form.errors.role && <p className="text-destructive text-sm">{form.errors.role}</p>}
                        </div>
                        <div className="flex justify-end gap-3 pt-2">
                            <button
                                type="button"
                                onClick={() => window.history.back()}
                                className="hover:bg-muted rounded-md border px-4 py-2 text-sm font-medium"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="bg-primary text-primary-foreground rounded-md px-4 py-2 text-sm font-medium hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {form.processing ? 'Creating...' : 'Create user'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}

import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

type UserRole = 'manager' | 'cashier';
type UserStatus = 'active' | 'inactive';
type User = { id: number; name: string; username: string; role: UserRole; status: UserStatus };

export default function UpdateUserPage({ user }: { user: User }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Back Office', href: route('back-office') },
        { title: 'Employee Management', href: route('employee-management') },
        { title: `Update ${user.name}`, href: route('users.edit', user.id) },
    ];
    const form = useForm({ name: user.name, username: user.username, password: '', passcode: '', role: user.role, status: user.status });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.patch(route('users.update', user.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Update ${user.name}`} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="bg-card mx-auto w-full max-w-2xl rounded-xl border p-6 shadow-sm">
                    <div className="mb-6">
                        <h1 className="text-xl font-semibold">Update user</h1>
                        <p className="text-muted-foreground mt-1 text-sm">Update account details and access.</p>
                    </div>
                    <form onSubmit={submit} className="space-y-5">
                        <div className="grid gap-5 sm:grid-cols-2">
                            <div className="space-y-2">
                                <label htmlFor="name" className="text-sm font-medium">
                                    Name
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
                                    Username
                                </label>
                                <input
                                    id="username"
                                    value={form.data.username}
                                    onChange={(event) => form.setData('username', event.target.value)}
                                    required
                                    className="bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm outline-none focus:ring-2"
                                />
                                {form.errors.username && <p className="text-destructive text-sm">{form.errors.username}</p>}
                            </div>
                            <div className="space-y-2">
                                <label htmlFor="password" className="text-sm font-medium">
                                    New password
                                </label>
                                <input
                                    id="password"
                                    type="password"
                                    minLength={8}
                                    value={form.data.password}
                                    onChange={(event) => form.setData('password', event.target.value)}
                                    placeholder="Leave blank to keep current"
                                    className="bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm outline-none focus:ring-2"
                                />
                                {form.errors.password && <p className="text-destructive text-sm">{form.errors.password}</p>}
                            </div>
                            {form.data.role == 'manager' ? (
                                <div className="space-y-2">
                                    <label htmlFor="passcode" className="text-sm font-medium">
                                        New passcode
                                    </label>
                                    <input
                                        id="passcode"
                                        type="password"
                                        inputMode="numeric"
                                        maxLength={4}
                                        value={form.data.passcode}
                                        onChange={(event) => form.setData('passcode', event.target.value.replace(/\D/g, '').slice(0, 4))}
                                        placeholder="Leave blank to clear"
                                        className="bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm outline-none focus:ring-2"
                                    />
                                    {form.errors.passcode && <p className="text-destructive text-sm">{form.errors.passcode}</p>}
                                </div>
                            ) : (
                                ''
                            )}
                        </div>
                        <div className="grid gap-5 sm:grid-cols-2">
                            <div className="space-y-2">
                                <label htmlFor="role" className="text-sm font-medium">
                                    Role
                                </label>
                                <select
                                    id="role"
                                    value={form.data.role}
                                    onChange={(event) => form.setData('role', event.target.value as UserRole)}
                                    className="bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm outline-none focus:ring-2"
                                >
                                    <option value="cashier">Cashier</option>
                                    <option value="manager">Manager</option>
                                </select>
                                {form.errors.role && <p className="text-destructive text-sm">{form.errors.role}</p>}
                            </div>
                            <div className="space-y-2">
                                <label htmlFor="status" className="text-sm font-medium">
                                    Status
                                </label>
                                <select
                                    id="status"
                                    value={form.data.status}
                                    onChange={(event) => form.setData('status', event.target.value as UserStatus)}
                                    className="bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm outline-none focus:ring-2"
                                >
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                                {form.errors.status && <p className="text-destructive text-sm">{form.errors.status}</p>}
                            </div>
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
                                {form.processing ? 'Updating...' : 'Update user'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}

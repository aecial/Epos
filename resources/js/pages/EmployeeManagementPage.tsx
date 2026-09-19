import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Search, Trash2, Users } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Back Office', href: route('back-office') },
    { title: 'Employee Management', href: route('employee-management') },
];

type UserRole = 'manager' | 'cashier';
type UserStatus = 'active' | 'inactive';
type User = { id: number; name: string; username: string; role: UserRole; status: UserStatus };

export default function EmployeeManagementPage({ users }: { users: User[] }) {
    const [search, setSearch] = useState('');
    const [userToDelete, setUserToDelete] = useState<User | null>(null);
    const deleteForm = useForm({});
    const [updating, setUpdating] = useState<Record<number, boolean>>({});

    const filteredUsers = useMemo(
        () => users.filter((user) => `${user.name} ${user.username}`.toLowerCase().includes(search.toLowerCase())),
        [users, search],
    );

    const updateUserStatus = (user: User) => {
        setUpdating((current) => ({ ...current, [user.id]: true }));
        router.patch(
            route('users.update', user.id),
            { status: user.status === 'active' ? 'inactive' : 'active' },
            {
                preserveScroll: true,
                onFinish: () => setUpdating((current) => ({ ...current, [user.id]: false })),
            },
        );
    };

    const deleteUser = () => {
        if (!userToDelete) return;
        deleteForm.delete(route('users.destroy', userToDelete.id), {
            preserveScroll: true,
            onSuccess: () => setUserToDelete(null),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Employee Management" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="border-sidebar-border/70 dark:border-sidebar-border relative max-h-[calc(100vh-8rem)] min-h-0 flex-1 overflow-auto rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead colSpan={6}>
                                    <div className="flex items-center gap-3 p-5">
                                        <Users className="text-muted-foreground size-5 shrink-0" />
                                        <div className="flex min-w-0 flex-1 items-center justify-between gap-4">
                                            <div>
                                                <h2 className="font-semibold">Users</h2>
                                                <p className="text-muted-foreground text-xs">Manage manager and cashier accounts.</p>
                                            </div>
                                            <Link
                                                href={route('users.create')}
                                                className="bg-primary text-primary-foreground inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium hover:opacity-90"
                                            >
                                                <Plus className="size-4" />
                                                Add New
                                            </Link>
                                        </div>
                                    </div>
                                </TableHead>
                            </TableRow>
                            <TableRow>
                                <TableHead colSpan={6}>
                                    <div className="relative max-w-sm">
                                        <Search className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                                        <Input
                                            type="search"
                                            value={search}
                                            onChange={(event) => setSearch(event.target.value)}
                                            placeholder="Search users"
                                            className="pl-9"
                                        />
                                    </div>
                                </TableHead>
                            </TableRow>
                            <TableRow>
                                <TableHead className="w-[70px]">ID</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Username</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {filteredUsers.map((user) => (
                                <TableRow key={user.id}>
                                    <TableCell className="font-medium">{user.id}</TableCell>
                                    <TableCell>{user.name}</TableCell>
                                    <TableCell>{user.username}</TableCell>
                                    <TableCell className="capitalize">{user.role}</TableCell>
                                    <TableCell>
                                        <button
                                            type="button"
                                            disabled={updating[user.id]}
                                            onClick={() => updateUserStatus(user)}
                                            className={`rounded-full px-3 py-1 text-xs font-medium text-white capitalize ${user.status === 'active' ? 'bg-green-600' : 'bg-red-600'}`}
                                        >
                                            {user.status}
                                        </button>
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center gap-2">
                                            <Link
                                                href={route('users.edit', user.id)}
                                                aria-label={`Update ${user.name}`}
                                                className="hover:bg-muted inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs font-medium"
                                            >
                                                <Pencil className="size-3" />
                                                Update
                                            </Link>
                                            <button
                                                type="button"
                                                aria-label={`Delete ${user.name}`}
                                                onClick={() => setUserToDelete(user)}
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
                </div>
            </div>
            <Dialog open={userToDelete !== null} onOpenChange={(open) => !open && setUserToDelete(null)}>
                <DialogContent>
                    <DialogTitle>Delete {userToDelete?.name}?</DialogTitle>
                    <DialogDescription>This action cannot be undone. The user account will be permanently deleted.</DialogDescription>
                    <DialogFooter>
                        <DialogClose asChild>
                            <button type="button" className="hover:bg-muted rounded-md border px-4 py-2 text-sm font-medium">
                                Cancel
                            </button>
                        </DialogClose>
                        <button
                            type="button"
                            onClick={deleteUser}
                            disabled={deleteForm.processing}
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90 rounded-md px-4 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {deleteForm.processing ? 'Deleting...' : 'Delete user'}
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { LogOut, Smartphone } from 'lucide-react';
import { useState } from 'react';

type SessionUser = { id: number; name: string; username: string };
type Session = {
    id: number;
    device_name: string;
    last_used_at: string | null;
    created_at: string;
    /** The POS device code (P1, P2...) printed on its offline receipts; null for a kitchen display. */
    device_code: string | null;
    last_synced_at: string | null;
    /** Offline actions the phone last said it still holds. */
    pending_actions: number;
};

function formatDate(value: string | null): string {
    if (!value) return 'Never';

    return new Date(value).toLocaleString();
}

export default function UserSessionsPage({ user, sessions }: { user: SessionUser; sessions: Session[] }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Back Office', href: route('back-office') },
        { title: 'Employee Management', href: route('employee-management') },
        { title: `${user.name}'s devices`, href: route('users.sessions', user.id) },
    ];

    const [sessionToRevoke, setSessionToRevoke] = useState<Session | null>(null);
    const [revokingAll, setRevokingAll] = useState(false);
    const revokeForm = useForm({});
    const revokeAllForm = useForm({});

    const revokeSession = () => {
        if (!sessionToRevoke) return;
        revokeForm.delete(route('users.sessions.revoke', [user.id, sessionToRevoke.id]), {
            preserveScroll: true,
            onSuccess: () => setSessionToRevoke(null),
        });
    };

    const revokeAll = () => {
        revokeAllForm.delete(route('users.sessions.revoke-all', user.id), {
            preserveScroll: true,
            onSuccess: () => setRevokingAll(false),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${user.name}'s devices`} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="border-sidebar-border/70 dark:border-sidebar-border relative min-h-0 flex-1 overflow-auto rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead colSpan={5}>
                                    <div className="flex items-center gap-3 p-5">
                                        <Smartphone className="text-muted-foreground size-5 shrink-0" />
                                        <div className="flex min-w-0 flex-1 items-center justify-between gap-4">
                                            <div>
                                                <h2 className="font-semibold">{user.name}'s signed-in devices</h2>
                                                <p className="text-muted-foreground text-xs">
                                                    POS logins never expire on their own — revoke a device here if it's lost or the account shouldn't
                                                    stay signed in.
                                                </p>
                                            </div>
                                            {sessions.length > 0 && (
                                                <button
                                                    type="button"
                                                    onClick={() => setRevokingAll(true)}
                                                    className="text-destructive border-destructive/30 hover:bg-destructive/10 inline-flex shrink-0 items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium"
                                                >
                                                    <LogOut className="size-4" />
                                                    Sign out everywhere
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                </TableHead>
                            </TableRow>
                            <TableRow>
                                <TableHead>Device</TableHead>
                                <TableHead>Signed in</TableHead>
                                <TableHead>Last used</TableHead>
                                <TableHead>Offline sync</TableHead>
                                <TableHead>Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {sessions.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={5} className="text-muted-foreground py-8 text-center text-sm">
                                        No device is currently signed in as {user.name}.
                                    </TableCell>
                                </TableRow>
                            )}
                            {sessions.map((session) => (
                                <TableRow key={session.id}>
                                    <TableCell className="font-medium">
                                        {session.device_name}
                                        {session.device_code && <div className="text-muted-foreground text-xs">Code {session.device_code}</div>}
                                    </TableCell>
                                    <TableCell>{formatDate(session.created_at)}</TableCell>
                                    <TableCell>{formatDate(session.last_used_at)}</TableCell>
                                    <TableCell>
                                        {session.device_code ? (
                                            <>
                                                {session.last_synced_at ? `Synced ${formatDate(session.last_synced_at)}` : 'Never synced'}
                                                {session.pending_actions > 0 && (
                                                    <div className="text-xs font-medium text-amber-700 dark:text-amber-400">
                                                        {session.pending_actions} offline {session.pending_actions === 1 ? 'action' : 'actions'}{' '}
                                                        waiting
                                                    </div>
                                                )}
                                            </>
                                        ) : (
                                            '—'
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <button
                                            type="button"
                                            aria-label={`Sign out ${session.device_name}`}
                                            onClick={() => setSessionToRevoke(session)}
                                            className="text-destructive border-destructive/30 hover:bg-destructive/10 inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs font-medium"
                                        >
                                            <LogOut className="size-3" />
                                            Sign out
                                        </button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
            <Dialog open={sessionToRevoke !== null} onOpenChange={(open) => !open && setSessionToRevoke(null)}>
                <DialogContent>
                    <DialogTitle>Sign out {sessionToRevoke?.device_name}?</DialogTitle>
                    <DialogDescription>
                        That device's token is revoked immediately. Whoever is using it will need to log in again to keep using the POS.
                        {sessionToRevoke && sessionToRevoke.pending_actions > 0 && (
                            <span className="mt-2 block font-medium text-amber-700 dark:text-amber-400">
                                It still holds {sessionToRevoke.pending_actions} offline{' '}
                                {sessionToRevoke.pending_actions === 1 ? 'action' : 'actions'} that haven't reached the server. Signed out, it can't
                                send them — let it sync first if you can.
                            </span>
                        )}
                    </DialogDescription>
                    <DialogFooter>
                        <DialogClose asChild>
                            <button type="button" className="hover:bg-muted rounded-md border px-4 py-2 text-sm font-medium">
                                Cancel
                            </button>
                        </DialogClose>
                        <button
                            type="button"
                            onClick={revokeSession}
                            disabled={revokeForm.processing}
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90 rounded-md px-4 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {revokeForm.processing ? 'Signing out...' : 'Sign out device'}
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <Dialog open={revokingAll} onOpenChange={setRevokingAll}>
                <DialogContent>
                    <DialogTitle>Sign {user.name} out everywhere?</DialogTitle>
                    <DialogDescription>
                        Every device currently signed in as {user.name} is revoked immediately. Each one will need to log in again.
                    </DialogDescription>
                    <DialogFooter>
                        <DialogClose asChild>
                            <button type="button" className="hover:bg-muted rounded-md border px-4 py-2 text-sm font-medium">
                                Cancel
                            </button>
                        </DialogClose>
                        <button
                            type="button"
                            onClick={revokeAll}
                            disabled={revokeAllForm.processing}
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90 rounded-md px-4 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {revokeAllForm.processing ? 'Signing out...' : 'Sign out everywhere'}
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

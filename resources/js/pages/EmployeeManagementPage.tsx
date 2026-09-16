import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Employee Management',
        href: route('back-office'),
    },
];

export default function EmployeeManagementPage() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Employee Management" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="border-sidebar-border/70 dark:border-sidebar-border relative max-h-[calc(100vh-8rem)] min-h-0 flex-1 overflow-auto rounded-xl border"></div>
            </div>
        </AppLayout>
    );
}

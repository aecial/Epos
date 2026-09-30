import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useEffect, useState } from 'react';

export function FlashMessage() {
    const { flash } = usePage<SharedData>().props;
    const [dismissed, setDismissed] = useState(false);

    useEffect(() => {
        setDismissed(false);
    }, [flash?.error, flash?.success]);

    const message = flash?.error ?? flash?.success;

    if (!message || dismissed) {
        return null;
    }

    const isError = Boolean(flash?.error);

    return (
        <div
            className={`mx-4 mt-4 flex items-start justify-between gap-3 rounded-lg border px-4 py-3 text-sm ${
                isError
                    ? 'border-destructive/50 bg-destructive/10 text-destructive'
                    : 'border-green-500/50 bg-green-500/10 text-green-700 dark:text-green-400'
            }`}
        >
            <span>{message}</span>
            <button onClick={() => setDismissed(true)} className="shrink-0 opacity-70 hover:opacity-100" aria-label="Dismiss">
                <X className="size-4" />
            </button>
        </div>
    );
}

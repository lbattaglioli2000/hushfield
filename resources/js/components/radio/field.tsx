import type { ReactNode } from 'react';
import InputError from '@/components/input-error';

/** An etched label over a control, with its validation message beneath. */
export default function Field({
    label,
    htmlFor,
    error,
    aside,
    children,
}: {
    label: ReactNode;
    htmlFor?: string;
    error?: string;
    /** Sits at the far end of the label line, like a "forgot password" link. */
    aside?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-2">
            <div className="flex items-baseline justify-between gap-2">
                <label
                    htmlFor={htmlFor}
                    className="text-xs font-semibold tracking-[0.2em] text-ink uppercase"
                >
                    {label}
                </label>
                {aside}
            </div>
            {children}
            <InputError
                message={error}
                className="font-mono text-xs text-record"
            />
        </div>
    );
}

import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** A slot of dark glass with a glowing amber value, and an optional etched label. */
export default function AmberReadout({
    label,
    children,
    className,
    ...props
}: ComponentProps<'div'> & { label?: ReactNode }) {
    return (
        <div
            className={cn(
                'flex items-baseline gap-3 rounded-md glass px-3 py-2 font-mono',
                className,
            )}
            {...props}
        >
            {label && (
                <span className="text-[0.65rem] tracking-[0.2em] text-bakelite/55 uppercase">
                    {label}
                </span>
            )}
            <span className="ml-auto truncate amber-glow tabular-nums">
                {children}
            </span>
        </div>
    );
}

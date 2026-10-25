import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** Four signal bars, like the set's strength meter: lit for each step of `level`. */
export function SignalBars({
    level,
    className,
}: {
    level: number;
    className?: string;
}) {
    return (
        <span aria-hidden className={cn('inline-flex gap-[3px]', className)}>
            {[0, 1, 2, 3].map((bar) => (
                <span
                    key={bar}
                    className={cn(
                        'h-2.5 w-1 rounded-[1px]',
                        bar < level
                            ? 'bg-amber shadow-[0_0_4px_rgb(242_184_75/0.6)]'
                            : 'bg-bakelite/20',
                    )}
                />
            ))}
        </span>
    );
}

/**
 * Notices and results arrive as interruptions on the glass: a dark card with a
 * coloured edge, the strength it came in at, and the message.
 */
export default function SignalCard({
    heading = 'Incoming signal',
    title,
    tone = 'red',
    level = 4,
    children,
    className,
    ...props
}: Omit<ComponentProps<'div'>, 'title'> & {
    heading?: ReactNode;
    title?: ReactNode;
    tone?: 'red' | 'amber';
    level?: number;
}) {
    return (
        <div
            className={cn(
                'animate-[signal-in_0.25s_ease-out] rounded-md border-l-4 glass p-3 font-mono text-sm leading-relaxed text-bakelite/85 shadow-[0_8px_20px_rgb(0_0_0/0.45)]',
                tone === 'red' ? 'border-record' : 'border-amber',
                className,
            )}
            {...props}
        >
            <p className="mb-1 flex items-center gap-2 text-[0.65rem] tracking-[0.2em] text-bakelite/65 uppercase">
                <SignalBars level={level} />
                {heading}
            </p>
            {title && (
                <p className="font-sans text-lg leading-tight font-bold tracking-wide amber-glow uppercase">
                    {title}
                </p>
            )}
            {children}
        </div>
    );
}

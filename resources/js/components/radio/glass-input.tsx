import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/** Dark glass with amber type: for inputs, selects, and the password field. */
export const glassField =
    'glass h-11 w-full min-w-0 rounded-md border-0 bg-glass px-4 font-mono text-base text-amber caret-amber shadow-[inset_0_2px_6px_rgb(0_0_0/0.6)] outline-none placeholder:text-bakelite/35 focus-visible:ring-2 focus-visible:ring-amber/60 aria-invalid:ring-2 aria-invalid:ring-record md:text-sm';

export default function GlassInput({
    className,
    ...props
}: ComponentProps<'input'>) {
    return <input className={cn(glassField, className)} {...props} />;
}

import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/** The set's moulded cream case: anything that sits on the radio sits on this. */
export default function BakelitePanel({
    className,
    ...props
}: ComponentProps<'div'>) {
    return (
        <div
            className={cn(
                'rounded-[1.75rem] bg-bakelite text-ink shadow-[inset_0_2px_0_rgb(255_255_255/0.7),inset_0_-6px_0_rgb(0_0_0/0.12),0_18px_40px_rgb(0_0_0/0.45)]',
                className,
            )}
            {...props}
        />
    );
}

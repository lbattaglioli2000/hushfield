import { cva } from 'class-variance-authority';
import type { VariantProps } from 'class-variance-authority';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

const keyVariants = cva(
    'inline-flex shrink-0 items-center justify-center gap-1.5 rounded-md font-sans font-semibold tracking-[0.14em] whitespace-nowrap uppercase transition-[transform,box-shadow,background-color] duration-75 outline-none select-none focus-visible:ring-2 focus-visible:ring-amber focus-visible:ring-offset-2 focus-visible:ring-offset-transparent disabled:cursor-not-allowed disabled:opacity-55 [&_svg]:size-4 [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                // Raised keys travel down when pressed, and stay down while latched (aria-pressed).
                bakelite:
                    'bg-bakelite-shade text-ink shadow-[inset_0_1px_0_rgb(255_255_255/0.65),0_3px_0_#b0a184,0_5px_7px_rgb(0_0_0/0.25)] hover:bg-[#e5dac3] enabled:active:translate-y-[2px] enabled:active:shadow-[inset_0_1px_0_rgb(255_255_255/0.65),0_1px_0_#b0a184] aria-pressed:translate-y-[2px] aria-pressed:bg-walnut aria-pressed:text-bakelite aria-pressed:shadow-[inset_0_2px_4px_rgb(0_0_0/0.45),0_1px_0_#24160d]',
                walnut: 'bg-walnut text-bakelite shadow-[inset_0_1px_0_rgb(255_255_255/0.12),0_3px_0_#24160d,0_5px_7px_rgb(0_0_0/0.3)] hover:bg-[#573825] enabled:active:translate-y-[2px] enabled:active:shadow-[0_1px_0_#24160d]',
                red: 'bg-record text-bakelite shadow-[inset_0_1px_0_rgb(255_255_255/0.25),0_3px_0_#7e1f18,0_5px_7px_rgb(0_0_0/0.3)] hover:bg-[#d4423a] enabled:active:translate-y-[2px] enabled:active:shadow-[0_1px_0_#7e1f18]',
                // Flat, for the far side of the glass.
                glass: 'border border-amber/30 text-amber hover:bg-amber/10 aria-pressed:bg-amber aria-pressed:text-glass',
            },
            size: {
                sm: 'h-8 px-3 text-xs',
                md: 'h-10 px-4 text-sm',
            },
        },
        defaultVariants: { variant: 'bakelite', size: 'md' },
    },
);

/** A chunky radio key. Pass aria-pressed to make it a latching key. */
export default function RadioKey({
    variant,
    size,
    className,
    type = 'button',
    ...props
}: ComponentProps<'button'> & VariantProps<typeof keyVariants>) {
    return (
        <button
            type={type}
            className={cn(keyVariants({ variant, size }), className)}
            {...props}
        />
    );
}

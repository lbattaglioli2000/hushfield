import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * An OFF/ON slide switch. With onCheckedChange it is a switch; without one (a
 * submit button, say) it only shows `checked`.
 */
export default function ToggleSwitch({
    checked,
    onCheckedChange,
    className,
    type = 'button',
    ...props
}: Omit<ComponentProps<'button'>, 'onChange'> & {
    checked: boolean;
    onCheckedChange?: (checked: boolean) => void;
}) {
    return (
        <button
            type={type}
            role={onCheckedChange ? 'switch' : undefined}
            aria-checked={onCheckedChange ? checked : undefined}
            onClick={onCheckedChange && (() => onCheckedChange(!checked))}
            className={cn(
                'relative grid h-9 w-24 shrink-0 grid-cols-2 items-center rounded-md bg-walnut p-1 font-mono text-[0.65rem] font-medium tracking-widest text-bakelite/80 shadow-[inset_0_2px_4px_rgb(0_0_0/0.45)] outline-none focus-visible:ring-2 focus-visible:ring-amber focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-55',
                className,
            )}
            {...props}
        >
            <span aria-hidden>OFF</span>
            <span aria-hidden>ON</span>
            <span
                aria-hidden
                className={cn(
                    'absolute inset-y-1 left-1 grid w-[calc(50%-0.25rem)] place-items-center rounded-[0.3rem] bg-bakelite text-ink shadow-[inset_0_1px_0_white,0_2px_3px_rgb(0_0_0/0.4)] transition-transform duration-150',
                    checked && 'translate-x-full',
                )}
            >
                {checked ? 'ON' : 'OFF'}
            </span>
        </button>
    );
}

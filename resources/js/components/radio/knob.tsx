import { useRef } from 'react';
import type { KeyboardEvent, PointerEvent } from 'react';
import { cn } from '@/lib/utils';

const STEP = 0.05;
/** Pixels of vertical drag for a full turn. */
const DRAG_RANGE = 160;

const clamp = (value: number) => Math.min(1, Math.max(0, value));

/**
 * A brushed-metal knob, turned by dragging, the scroll wheel, or the arrow
 * keys. `value` runs from 0 to 1 over 270 degrees. A disabled knob is inert.
 */
export default function Knob({
    label,
    sublabel,
    value,
    onChange,
    disabled = false,
    className,
}: {
    label: string;
    sublabel?: string;
    value: number;
    onChange?: (value: number) => void;
    disabled?: boolean;
    className?: string;
}) {
    const drag = useRef<{ y: number; value: number } | null>(null);
    const turn = (next: number) => !disabled && onChange?.(clamp(next));

    const onKeyDown = (event: KeyboardEvent) => {
        const next = {
            ArrowUp: value + STEP,
            ArrowRight: value + STEP,
            ArrowDown: value - STEP,
            ArrowLeft: value - STEP,
            Home: 0,
            End: 1,
        }[event.key];

        if (next === undefined) return;

        event.preventDefault();
        turn(next);
    };

    const onPointerDown = (event: PointerEvent<HTMLDivElement>) => {
        if (disabled) return;

        event.currentTarget.setPointerCapture(event.pointerId);
        drag.current = { y: event.clientY, value };
    };

    const onPointerMove = (event: PointerEvent) => {
        if (!drag.current) return;

        turn(
            drag.current.value + (drag.current.y - event.clientY) / DRAG_RANGE,
        );
    };

    return (
        <div
            className={cn(
                'flex flex-col items-center gap-1.5 select-none',
                className,
            )}
        >
            <div
                role="slider"
                tabIndex={disabled ? -1 : 0}
                aria-label={label}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={Math.round(value * 100)}
                aria-disabled={disabled || undefined}
                onKeyDown={onKeyDown}
                onWheel={(event) =>
                    turn(value - Math.sign(event.deltaY) * STEP)
                }
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={() => (drag.current = null)}
                onPointerCancel={() => (drag.current = null)}
                className={cn(
                    'relative size-16 touch-none rounded-full bg-[conic-gradient(from_200deg,#f4f3ef,#8e8c86,#e2e0da,#77756f,#efeee9,#9a9892,#f4f3ef)] shadow-[0_0_0_4px_#d2c5aa,0_0_0_5px_rgb(0_0_0/0.15),0_6px_10px_rgb(0_0_0/0.4),inset_0_1px_1px_white] outline-none focus-visible:ring-2 focus-visible:ring-amber focus-visible:ring-offset-4 focus-visible:ring-offset-bakelite',
                    disabled ? 'cursor-not-allowed opacity-70' : 'cursor-grab',
                )}
            >
                <span
                    aria-hidden
                    className="absolute inset-0"
                    style={{ transform: `rotate(${value * 270 - 135}deg)` }}
                >
                    <span className="absolute top-2 left-1/2 h-4 w-1 -translate-x-1/2 rounded-full bg-glass" />
                </span>
            </div>
            <span className="text-xs font-semibold tracking-[0.2em] text-ink uppercase">
                {label}
            </span>
            {sublabel && (
                <span className="-mt-1 font-mono text-[0.6rem] tracking-[0.15em] text-ink/55 uppercase">
                    {sublabel}
                </span>
            )}
        </div>
    );
}

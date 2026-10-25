import type { GameEvents, Station } from '@/game';
import { cn } from '@/lib/utils';

const LOW = 87.5;
const HIGH = 108.5;
/** Where the needle rests for a room with no station of its own. */
const OFF_STATION = 107.3;

type Room = GameEvents['room'];

/** Which station a room is on, so a change of room is a change of station. */
export function stationKey(room: Room): string {
    return room.home ? `home:${room.home.id}` : room.slug;
}

const at = (frequency: number) =>
    `${((frequency - LOW) / (HIGH - LOW)) * 100}%`;
const NUMBERS = Array.from({ length: 11 }, (_, i) => 88 + i * 2);
const TICKS = Array.from({ length: 41 }, (_, i) => 88 + i / 2);

/**
 * The tuning dial across the top of the set. A display, not a control: the
 * needle sweeps to whichever room the player is in.
 */
export default function TuningDial({
    stations,
    room,
    retune,
}: {
    /** From the server, lowest first: each room's frequency comes from its map. */
    stations: Station[];
    room: Room | null;
    /** Counts station changes; each one replays the static. */
    retune: number;
}) {
    const station = room?.frequency
        ? stations.find((s) => s.frequency === room.frequency)
        : undefined;
    const needle = room ? (room.frequency ?? OFF_STATION) : LOW;

    return (
        <div
            className="relative h-[4.5rem] shrink-0 overflow-hidden rounded-xl glass px-6 font-mono select-none"
            data-test="tuning-dial"
            data-station={station?.frequency.toFixed(1) ?? 'none'}
        >
            <div className="relative h-full">
                {NUMBERS.map((n) => (
                    <span
                        key={n}
                        className="absolute top-2 -translate-x-1/2 text-xs text-amber/90"
                        style={{ left: at(n) }}
                    >
                        {n}
                    </span>
                ))}
                {TICKS.map((t) => (
                    <span
                        key={t}
                        aria-hidden
                        className={cn(
                            'absolute top-7 w-px bg-bakelite/45',
                            Number.isInteger(t) ? 'h-3' : 'h-1.5',
                        )}
                        style={{ left: at(t) }}
                    />
                ))}
                {/* Neighbouring stations alternate between two rows so their labels never overlap. */}
                {stations.map(({ frequency, label }, i) => (
                    <span
                        key={frequency}
                        className={cn(
                            'absolute pl-2 text-[0.65rem] leading-none tracking-[0.18em] whitespace-nowrap uppercase transition-colors duration-500',
                            i % 2 ? 'bottom-1.5' : 'bottom-4',
                            station?.frequency === frequency
                                ? 'amber-glow'
                                : 'text-bakelite/45',
                        )}
                        style={{ left: at(frequency) }}
                    >
                        {frequency.toFixed(1)} {label}
                    </span>
                ))}
                <span
                    aria-hidden
                    className="absolute inset-y-1 w-0.5 -translate-x-1/2 bg-record shadow-[0_0_6px_rgb(200_55_45/0.8)] transition-[left] duration-[900ms] ease-[cubic-bezier(0.3,0.75,0.2,1)] motion-reduce:transition-none"
                    style={{ left: at(needle) }}
                    data-test="dial-needle"
                />
            </div>
            <span className="sr-only" role="status">
                {room ? `Tuned to ${room.title}` : ''}
            </span>
            {retune > 0 && <Static key={retune} />}
        </div>
    );
}

/** A moment of snow across a piece of glass. Remount it to play it again. */
export function Static({ className }: { className?: string }) {
    return (
        <span
            aria-hidden
            className={cn(
                'pointer-events-none absolute inset-0 animate-[static-burst_0.6s_ease-out_forwards] static-snow opacity-0 mix-blend-screen motion-reduce:hidden',
                className,
            )}
        />
    );
}

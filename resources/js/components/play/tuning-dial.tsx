import type { Copy, GameEvents } from '@/game';
import { cn } from '@/lib/utils';

const LOW = 87.5;
const HIGH = 108.5;
/** Where the needle rests for a room with no station of its own. */
const OFF_STATION = 107.3;

type Room = GameEvents['room'];

/** Every room's place on the band. Homes all share one frequency. */
const STATIONS: Array<{
    frequency: number;
    label: keyof Copy & `station_${string}`;
    matches: (room: Room) => boolean;
}> = [
    { frequency: 88.3, label: 'station_home', matches: (room) => !!room.home },
    {
        frequency: 94.2,
        label: 'station_town_square',
        matches: (room) => !room.home && room.slug === 'town-square',
    },
    {
        frequency: 99.1,
        label: 'station_lantern_street',
        matches: (room) => !room.home && room.slug === 'lantern-street',
    },
    {
        frequency: 104.8,
        label: 'station_residences',
        matches: (room) => !room.home && room.slug === 'hearthmore-residences',
    },
];

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
    copy,
    room,
    retune,
}: {
    copy: Copy;
    room: Room | null;
    /** Counts station changes; each one replays the static. */
    retune: number;
}) {
    const station = room && STATIONS.find((s) => s.matches(room));
    const needle = station?.frequency ?? (room ? OFF_STATION : LOW);

    return (
        <div
            className="relative h-[4.5rem] shrink-0 overflow-hidden rounded-xl glass px-6 font-mono select-none"
            data-test="tuning-dial"
            data-station={station?.label ?? 'none'}
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
                {STATIONS.map(({ frequency, label }) => (
                    <span
                        key={label}
                        className={cn(
                            'absolute bottom-1.5 pl-2 text-[0.65rem] tracking-[0.18em] whitespace-nowrap uppercase transition-colors duration-500',
                            station?.label === label
                                ? 'amber-glow'
                                : 'text-bakelite/45',
                        )}
                        style={{ left: at(frequency) }}
                    >
                        {frequency.toFixed(1)} {copy[label]}
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

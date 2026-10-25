import type { CSSProperties } from 'react';
import { useState } from 'react';
import RadioDialog from '@/components/radio/radio-dialog';
import RadioKey from '@/components/radio/radio-key';
import SignalCard from '@/components/radio/signal-card';
import type { Copy, EventBus, GameEvents, TownRoom } from '@/game';
import { useGameBus } from '@/hooks/use-game-bus';
import { cn } from '@/lib/utils';

/** Height of one row of the town map's grid, in pixels. */
const ROW_PX = 104;

type Room = GameEvents['room'];

/**
 * The Map key and the town map: every room laid out by its map's grid cell,
 * joined by its doors, with its station and who is in it. Choosing a room
 * travels there the way the Neighbors "Go to" does.
 */
export default function TownMap({ bus, copy }: { bus: EventBus; copy: Copy }) {
    const [room, setRoom] = useState<Room | null>(null);
    const [open, setOpen] = useState(false);
    const [town, setTown] = useState<GameEvents['town'] | null>(null);

    useGameBus(bus, 'room', (next) => {
        setRoom(next);
        setOpen(false);
    });
    useGameBus(bus, 'town', setTown);

    if (!room) return null;

    const travel = (slug: string) => {
        setOpen(false);
        bus.emit('travel', { slug, instanceId: null });
    };

    return (
        <>
            <RadioKey
                onClick={() => {
                    setOpen(true);
                    setTown(null);
                    bus.emit('load-town', {});
                }}
                data-test="open-town-map"
            >
                Map
            </RadioKey>

            <RadioDialog
                open={open}
                onOpenChange={setOpen}
                band="Town map"
                title={copy.town_map_title}
                description={copy.town_map_intro}
                className="sm:max-w-4xl"
                data-test="town-map-panel"
            >
                {town === null ? (
                    <p className="text-bakelite/60">Tuning…</p>
                ) : 'error' in town ? (
                    <SignalCard role="status">{town.error}</SignalCard>
                ) : (
                    <Town
                        rooms={town.map.rooms}
                        here={room}
                        unplaced={copy.town_map_unplaced}
                        onTravel={travel}
                    />
                )}
            </RadioDialog>
        </>
    );
}

function Town({
    rooms,
    here,
    unplaced,
    onTravel,
}: {
    rooms: TownRoom[];
    here: Room;
    unplaced: string;
    onTravel: (slug: string) => void;
}) {
    // Row by row, so keys tab across the map the way it reads.
    const placed = rooms
        .filter(
            (r): r is TownRoom & { position: { x: number; y: number } } =>
                r.position !== null,
        )
        .sort(
            (a, b) =>
                a.position.y - b.position.y || a.position.x - b.position.x,
        );
    const elsewhere = rooms.filter((r) => r.position === null);
    const xs = placed.map((r) => r.position.x);
    const ys = placed.map((r) => r.position.y);
    const minX = Math.min(...xs);
    const minY = Math.min(...ys);
    const cols = Math.max(...xs) - minX + 1;
    const rows = Math.max(...ys) - minY + 1;
    const bySlug = new Map(placed.map((r) => [r.slug, r]));
    // Cell centres in grid units, which the SVG and the stops share.
    const cx = (r: (typeof placed)[number]) => r.position.x - minX + 0.5;
    const cy = (r: (typeof placed)[number]) => r.position.y - minY + 0.5;
    const roads = placed.flatMap((a) =>
        a.links
            .filter((slug) => a.slug < slug && bySlug.has(slug))
            .map((slug) => [a, bySlug.get(slug)!] as const),
    );

    return (
        <div className="grid gap-4">
            {placed.length > 0 && (
                <div
                    className="relative rounded-lg bg-walnut-deep/60 shadow-[inset_0_2px_6px_rgb(0_0_0/0.6)]"
                    style={{ height: rows * ROW_PX }}
                    data-test="town-grid"
                >
                    <svg
                        aria-hidden
                        className="absolute inset-0 size-full"
                        viewBox={`0 0 ${cols} ${rows}`}
                        preserveAspectRatio="none"
                    >
                        {roads.map(([a, b]) => (
                            <line
                                key={`${a.slug}:${b.slug}`}
                                x1={cx(a)}
                                y1={cy(a)}
                                x2={cx(b)}
                                y2={cy(b)}
                                className="stroke-amber/45"
                                strokeWidth={2}
                                strokeDasharray="5 5"
                                vectorEffect="non-scaling-stroke"
                            />
                        ))}
                    </svg>
                    {placed.map((r) => (
                        <Stop
                            key={r.slug}
                            room={r}
                            here={here}
                            onTravel={onTravel}
                            className="absolute -translate-x-1/2 -translate-y-1/2"
                            style={{
                                left: `${(cx(r) / cols) * 100}%`,
                                top: `${(cy(r) / rows) * 100}%`,
                                width: `calc(${100 / cols}% - 0.75rem)`,
                            }}
                        />
                    ))}
                </div>
            )}

            {elsewhere.length > 0 && (
                <div className="grid gap-2">
                    <p className="border-b border-bakelite/10 pb-1 text-[0.65rem] tracking-[0.2em] text-bakelite/55 uppercase">
                        {unplaced}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {elsewhere.map((r) => (
                            <Stop
                                key={r.slug}
                                room={r}
                                here={here}
                                onTravel={onTravel}
                                className="w-32"
                            />
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

/** One room on the map: a key to travel there, or the "you are here" mark. */
function Stop({
    room,
    here,
    onTravel,
    className,
    style,
}: {
    room: TownRoom;
    here: Room;
    onTravel: (slug: string) => void;
    className?: string;
    style?: CSSProperties;
}) {
    const residence = room.kind === 'residence';
    const inside = here.slug === room.slug;
    // In someone else's home, the residence still takes the player to their own.
    const current = inside && (!residence || here.ownHome);
    const detail = residence ? 'Your home' : `${room.occupants ?? 0} listening`;

    return (
        <button
            type="button"
            disabled={current}
            aria-current={inside ? 'location' : undefined}
            onClick={() => onTravel(room.slug)}
            className={cn(
                'flex min-h-20 flex-col items-center justify-center gap-0.5 rounded-md border px-1.5 py-1.5 text-center leading-tight transition-colors outline-none focus-visible:ring-2 focus-visible:ring-amber',
                // Opaque glass under the tint, so the roads stop at the edge of each stop.
                inside
                    ? 'border-amber bg-glass shadow-[inset_0_0_0_999px_rgb(242_184_75/0.14),0_0_12px_rgb(242_184_75/0.35)]'
                    : 'border-bakelite/20 bg-glass hover:border-amber/70 hover:shadow-[inset_0_0_0_999px_rgb(242_184_75/0.1)]',
                current && 'cursor-default',
                className,
            )}
            style={style}
            data-test={`town-room-${room.slug}`}
        >
            {inside && (
                <span className="text-[0.55rem] tracking-[0.2em] whitespace-nowrap text-record uppercase">
                    ● You are here
                </span>
            )}
            <span className="font-sans text-xs font-bold tracking-wide text-bakelite uppercase">
                {room.name}
            </span>
            {room.frequency !== null && (
                <span className="text-[0.65rem] amber-glow tabular-nums">
                    {room.frequency.toFixed(1)} FM
                </span>
            )}
            <span className="text-[0.65rem] whitespace-nowrap text-bakelite/60 tabular-nums">
                {detail}
            </span>
        </button>
    );
}

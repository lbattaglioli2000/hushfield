import { useCallback, useState } from 'react';
import RadioKey from '@/components/radio/radio-key';
import type { CinemaState, Copy, EventBus } from '@/game';
import { useGameBus } from '@/hooks/use-game-bus';

const startTime = new Intl.DateTimeFormat(undefined, {
    hour: 'numeric',
    minute: '2-digit',
});

/**
 * The layer over the glass the VFA's film plays in. The game lays the
 * player's frame over the screen in the room, so it must sit above every
 * other layer on the glass: YouTube forbids anything drawn over its player.
 * Clicks pass through it to the room everywhere but the frame.
 */
export function CinemaLayer({ bus }: { bus: EventBus }) {
    // Mounts once the game says the player is in the cinema, so the game is listening for it.
    const [inCinema, setInCinema] = useState(false);
    useGameBus(bus, 'cinema-state', ({ state }) => setInCinema(state !== null));
    const layer = useCallback(
        (element: HTMLDivElement | null) =>
            bus.emit('cinema-screen', { element }),
        [bus],
    );

    if (!inCinema) return null;

    return (
        <div
            ref={layer}
            className="pointer-events-none absolute inset-0 z-10"
            data-test="cinema-layer"
        />
    );
}

/**
 * One line in the bezel while the player is in the VFA: what is showing on
 * the screen in the room, what is next, and the Sound key.
 */
export default function CinemaBar({
    bus,
    copy,
}: {
    bus: EventBus;
    copy: Copy;
}) {
    const [state, setState] = useState<CinemaState | null>(null);
    useGameBus(bus, 'cinema-state', ({ state: next }) => setState(next));

    if (!state) return null;

    const playing = state.phase === 'loading' || state.phase === 'playing';
    const line =
        state.phase === 'dark'
            ? copy.cinema_dark
            : state.phase === 'unavailable'
              ? copy.cinema_unavailable
              : state.phase === 'idle'
                ? copy.cinema_idle
                : !state.inView
                  ? copy.cinema_out_of_view
                  : state.muted
                    ? copy.cinema_muted
                    : null;

    return (
        <section
            className="flex h-12 shrink-0 items-center gap-4 rounded-md glass px-3 font-mono text-sm text-bakelite/85"
            data-test="cinema"
            data-phase={state.phase}
        >
            <span className="shrink-0 font-sans text-xs font-bold tracking-[0.3em] text-bakelite uppercase">
                VFA
            </span>
            <div className="min-w-0 flex-1">
                <p className="truncate">
                    {state.film ? (
                        <span
                            className="amber-glow uppercase"
                            title={state.film.title}
                            data-test="cinema-title"
                        >
                            {state.film.title}
                        </span>
                    ) : (
                        <span className="text-xs tracking-[0.2em] text-bakelite/60 uppercase">
                            {copy.cinema_branch}
                        </span>
                    )}
                    {state.next && (
                        <span className="text-bakelite/50">
                            {' '}
                            · Next {startTime.format(
                                state.next.startsAtMs,
                            )}:{' '}
                            {state.next.title}
                        </span>
                    )}
                </p>
                {line && (
                    <p
                        className="truncate text-xs text-bakelite/60"
                        data-test="cinema-line"
                    >
                        {line}
                    </p>
                )}
            </div>
            {playing && (
                <RadioKey
                    variant={state.muted ? 'red' : 'glass'}
                    size="sm"
                    aria-pressed={!state.muted}
                    disabled={!state.muted}
                    className="disabled:opacity-100"
                    onClick={() => bus.emit('cinema-sound', {})}
                    data-test="cinema-sound"
                >
                    {state.muted ? 'Sound' : 'Sound on'}
                </RadioKey>
            )}
        </section>
    );
}

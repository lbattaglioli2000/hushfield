import { useState } from 'react';
import RadioKey from '@/components/radio/radio-key';
import type { BroadcastState, Copy, EventBus } from '@/game';
import { useGameBus } from '@/hooks/use-game-bus';
import { cn } from '@/lib/utils';

/** Seconds per character on the ticker, plus a lead-in for crossing the empty glass. */
const SECONDS_PER_CHAR = 0.18;
const LEAD_IN_CHARS = 60;

function pickAfter(lines: string[], current: number): number {
    if (lines.length < 2) return 0;

    const next = Math.floor(Math.random() * (lines.length - 1));

    return next >= current ? next + 1 : next;
}

/**
 * ON AIR: Augustin Moss, reading one line at a time, in no particular order.
 * While staff have a broadcast on WHSH, the lamp flashes and the ticker
 * names the broadcast instead, with Listen until the browser lets it play.
 */
export default function OnAirTicker({
    bus,
    copy,
}: {
    bus: EventBus;
    copy: Copy;
}) {
    const [line, setLine] = useState(() => pickAfter(copy.on_air, -1));
    const [plays, setPlays] = useState(0);
    const [broadcast, setBroadcast] = useState<BroadcastState | null>(null);
    useGameBus(bus, 'broadcast-state', ({ state }) => setBroadcast(state));
    const text = broadcast
        ? `${copy.broadcast_station} ▸ ${broadcast.title}`
        : `${copy.on_air_host} ▸ ${copy.on_air[line] ?? ''}`;

    return (
        <div
            className="flex h-9 shrink-0 overflow-hidden rounded-md glass"
            data-test="on-air"
            data-broadcast={broadcast?.id ?? 'none'}
        >
            <span
                className={cn(
                    'flex items-center bg-record px-3 text-xs font-bold tracking-[0.2em] whitespace-nowrap text-bakelite uppercase',
                    broadcast &&
                        'animate-[on-air-flash_1s_steps(1,end)_infinite] shadow-[0_0_12px_rgb(200_55_45/0.8)] motion-reduce:animate-none',
                )}
            >
                On air
            </span>
            <div className="relative min-w-0 flex-1 overflow-hidden">
                <p
                    key={`${broadcast?.id ?? 'moss'}-${plays}`}
                    className="ticker-line absolute inset-y-0 left-0 flex w-max items-center pl-[100%] font-mono text-sm tracking-wide whitespace-nowrap amber-glow uppercase"
                    style={{
                        animation: `ticker ${(text.length + LEAD_IN_CHARS) * SECONDS_PER_CHAR}s linear`,
                    }}
                    onAnimationEnd={() => {
                        if (!broadcast) setLine(pickAfter(copy.on_air, line));
                        setPlays(plays + 1);
                    }}
                    data-test="on-air-line"
                >
                    <span className="pl-3">{text}</span>
                </p>
            </div>
            {broadcast && !broadcast.listening && (
                <RadioKey
                    variant="red"
                    size="sm"
                    className="my-0.5 mr-0.5 h-auto"
                    title={copy.broadcast_listen}
                    onClick={() => bus.emit('broadcast-listen', {})}
                    data-test="broadcast-listen"
                >
                    Listen
                </RadioKey>
            )}
        </div>
    );
}

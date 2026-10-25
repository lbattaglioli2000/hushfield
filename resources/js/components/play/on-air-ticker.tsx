import { useState } from 'react';
import type { Copy } from '@/game';

/** Seconds per character on the ticker, plus a lead-in for crossing the empty glass. */
const SECONDS_PER_CHAR = 0.18;
const LEAD_IN_CHARS = 60;

function pickAfter(lines: string[], current: number): number {
    if (lines.length < 2) return 0;

    const next = Math.floor(Math.random() * (lines.length - 1));

    return next >= current ? next + 1 : next;
}

/** ON AIR: Augustin Moss, reading one line at a time, in no particular order. */
export default function OnAirTicker({ copy }: { copy: Copy }) {
    const [line, setLine] = useState(() => pickAfter(copy.on_air, -1));
    const [plays, setPlays] = useState(0);
    const text = copy.on_air[line] ?? '';

    return (
        <div
            className="flex h-9 shrink-0 overflow-hidden rounded-md glass"
            data-test="on-air"
        >
            <span className="flex items-center bg-record px-3 text-xs font-bold tracking-[0.2em] whitespace-nowrap text-bakelite uppercase">
                On air
            </span>
            <div className="relative min-w-0 flex-1 overflow-hidden">
                <p
                    key={plays}
                    className="ticker-line absolute inset-y-0 left-0 flex w-max items-center pl-[100%] font-mono text-sm tracking-wide whitespace-nowrap amber-glow uppercase"
                    style={{
                        animation: `ticker ${(text.length + LEAD_IN_CHARS) * SECONDS_PER_CHAR}s linear`,
                    }}
                    onAnimationEnd={() => {
                        setLine(pickAfter(copy.on_air, line));
                        setPlays(plays + 1);
                    }}
                >
                    <span className="pl-3">
                        {copy.on_air_host} ▸ {text}
                    </span>
                </p>
            </div>
        </div>
    );
}

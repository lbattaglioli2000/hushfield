import { useEffect, useState } from 'react';
import RadioKey from '@/components/radio/radio-key';
import SignalCard from '@/components/radio/signal-card';
import type { Copy, EventBus, GameEvents } from '@/game';
import { useGameBus } from '@/hooks/use-game-bus';

/** An outdated build reloads itself after the player has had a moment to read why. */
const RELOAD_AFTER_MS = 2500;

export default function FatalScreen({
    bus,
    copy,
}: {
    bus: EventBus;
    copy: Copy;
}) {
    const [fatal, setFatal] = useState<GameEvents['fatal'] | null>(null);
    useGameBus(bus, 'fatal', setFatal);

    useEffect(() => {
        if (fatal?.kind !== 'please_reload') return;

        const timer = setTimeout(
            () => window.location.reload(),
            RELOAD_AFTER_MS,
        );

        return () => clearTimeout(timer);
    }, [fatal]);

    if (!fatal) return null;

    const action =
        fatal.kind === 'session_moved'
            ? copy.session_moved_action
            : fatal.kind === 'banned'
              ? copy.banned_action
              : copy.please_reload_action; // please_reload and unavailable

    return (
        <div
            className="absolute inset-0 z-40 grid place-items-center bg-glass/90 p-6"
            data-test={`fatal-${fatal.kind}`}
        >
            <SignalCard level={0} className="grid w-full max-w-md gap-3 p-6">
                <p className="font-sans text-2xl leading-tight font-bold tracking-wide amber-glow uppercase">
                    {fatal.message}
                </p>
                <p className="text-bakelite/70">{action}</p>
                {fatal.kind !== 'banned' && (
                    <RadioKey
                        className="justify-self-start"
                        onClick={() => window.location.reload()}
                    >
                        Reload
                    </RadioKey>
                )}
            </SignalCard>
        </div>
    );
}

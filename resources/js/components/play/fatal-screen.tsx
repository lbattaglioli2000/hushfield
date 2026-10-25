import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
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
            className="absolute inset-0 flex flex-col items-center justify-center gap-4 bg-[#1d2330]/95 p-6 text-center"
            data-test={`fatal-${fatal.kind}`}
        >
            <p className="max-w-md text-xl">{fatal.message}</p>
            <p className="max-w-md text-sm text-white/70">{action}</p>
            {fatal.kind !== 'banned' && (
                <Button
                    variant="secondary"
                    onClick={() => window.location.reload()}
                >
                    Reload
                </Button>
            )}
        </div>
    );
}

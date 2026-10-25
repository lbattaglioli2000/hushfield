import { useEffect, useRef, useState } from 'react';
import type { ConnectionState, EventBus } from '@/game';
import { useGameBus } from '@/hooks/use-game-bus';

/** How long a "you're back" message stays up once the connection is online again. */
const ONLINE_MESSAGE_MS = 3000;

export default function ConnectionBanner({ bus }: { bus: EventBus }) {
    const [state, setState] = useState<ConnectionState>('connecting');
    const [message, setMessage] = useState<string | null>(null);
    const hideTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useGameBus(bus, 'connection', ({ state, message }) => {
        if (hideTimer.current) clearTimeout(hideTimer.current);
        setState(state);
        setMessage(message ?? null);

        if (state === 'online' && message) {
            hideTimer.current = setTimeout(
                () => setMessage(null),
                ONLINE_MESSAGE_MS,
            );
        }
    });

    useEffect(
        () => () => {
            if (hideTimer.current) clearTimeout(hideTimer.current);
        },
        [],
    );

    if (!message) return null;

    return (
        <div
            role="status"
            className="pointer-events-none absolute top-3 left-1/2 -translate-x-1/2 rounded-full bg-[#fbf7ee] px-4 py-1.5 text-sm text-[#1d2330] shadow-lg"
            data-test="connection-banner"
            data-state={state}
        >
            {message}
        </div>
    );
}

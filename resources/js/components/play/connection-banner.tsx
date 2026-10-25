import { useEffect, useRef, useState } from 'react';
import { OnTray } from '@/components/play/signal-tray';
import SignalCard from '@/components/radio/signal-card';
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

    const online = state === 'online';

    return (
        <OnTray>
            <SignalCard
                role="status"
                tone={online ? 'amber' : 'red'}
                level={online ? 4 : 1}
                className="pointer-events-none"
                data-test="connection-banner"
                data-state={state}
            >
                {message}
            </SignalCard>
        </OnTray>
    );
}

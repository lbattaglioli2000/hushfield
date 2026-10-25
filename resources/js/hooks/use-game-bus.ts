import { useEffect, useRef } from 'react';
import type { EventBus, GameEvents } from '@/game';

/** Subscribes to one game event for the component's lifetime; the latest handler is always called. */
export function useGameBus<K extends keyof GameEvents>(
    bus: EventBus,
    event: K,
    handler: (payload: GameEvents[K]) => void,
): void {
    const latest = useRef(handler);
    latest.current = handler;

    useEffect(
        () => bus.on(event, (payload) => latest.current(payload)),
        [bus, event],
    );
}

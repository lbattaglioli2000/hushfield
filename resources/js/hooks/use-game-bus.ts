import { useEffect, useRef } from 'react';
import type { EventBus, FixtureKind, GameEvents } from '@/game';

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

/**
 * Runs when the player walks onto a fixture of one kind, such as the shop
 * counter: how a panel opens at its place on the map.
 */
export function useFixture(
    bus: EventBus,
    kind: FixtureKind,
    handler: () => void,
): void {
    useGameBus(bus, 'use-fixture', (fixture) => {
        if (fixture.kind === kind) handler();
    });
}

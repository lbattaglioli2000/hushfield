import type { BroadcastState, EventBus } from './bus';
import type { WorldNet } from './net/WorldNet';
import type { Broadcast } from './protocol/types';

/** How the set keeps up with WHSH: checked every tick, and put back in step when it drifts too far. */
const SYNC = {
    tickMs: 1000,
    /** Further than this from the town, in seconds, jump to where it is. */
    seekSeconds: 2,
    seekCooldownMs: 5000,
} as const;

/** The broadcast on the air at `nowMs`, if any. */
export function airing(
    broadcast: Broadcast | null,
    nowMs: number,
): Broadcast | null {
    return broadcast &&
        broadcast.starts_at_ms <= nowMs &&
        nowMs < broadcast.ends_at_ms
        ? broadcast
        : null;
}

/**
 * WHSH through the set's speaker: whatever staff have on the air plays in
 * every room, at the moment the town is at, at the Volume knob's level.
 * Nobody can pause it. Browsers hold the sound back until the player has
 * clicked something; until then the set offers Listen, and it starts with
 * the next click. Keeps playing in a hidden tab, as a radio would.
 * Returns the cleanup.
 */
export function connectBroadcast(net: WorldNet, bus: EventBus): () => void {
    const audio = new Audio();
    audio.preload = 'auto';
    let broadcast: Broadcast | null = null;
    /** The broadcast loaded into the speaker. */
    let loaded: number | null = null;
    let blocked = false;
    let volume = 0.5;
    let lastSeekAt = 0;
    let lastState = '';

    const emit = (state: BroadcastState | null) => {
        const key = JSON.stringify(state);

        if (key === lastState) return;

        lastState = key;
        bus.emit('broadcast-state', { state });
    };

    /** Seconds into the broadcast the town is at. */
    const position = (on: Broadcast) =>
        (net.serverNow() - on.starts_at_ms) / 1000;

    const report = () => {
        const on = airing(broadcast, net.serverNow());

        emit(
            on && {
                id: on.id,
                title: on.title,
                listening: !blocked && (!audio.paused || audio.ended),
            },
        );
    };

    /** Only reports how it went: the next tick tries again, never this, or a refusal would loop. */
    const play = () => {
        audio.volume = volume;
        audio
            .play()
            .then(() => {
                blocked = false;
                report();
            })
            .catch((error: unknown) => {
                // Not allowed before a click; anything else (a broken file) leaves it silent too.
                blocked =
                    error instanceof DOMException &&
                    error.name === 'NotAllowedError';
                report();
            });
    };

    const tick = () => {
        const on = airing(broadcast, net.serverNow());

        if (!on) {
            if (loaded !== null) {
                audio.pause();
                audio.removeAttribute('src');
                audio.load();
                loaded = null;
            }

            report();

            return;
        }

        if (loaded !== on.id) {
            loaded = on.id;
            audio.src = on.url;
            audio.currentTime = position(on);
            lastSeekAt = Date.now();
            play();
        } else if (audio.paused && !audio.ended) {
            // Held back until a click, or paused by the device: try again where the town is now.
            audio.currentTime = position(on);
            play();
        } else if (
            !audio.paused &&
            audio.readyState >= HTMLMediaElement.HAVE_METADATA &&
            Math.abs(audio.currentTime - position(on)) > SYNC.seekSeconds &&
            Date.now() - lastSeekAt > SYNC.seekCooldownMs
        ) {
            audio.currentTime = position(on);
            lastSeekAt = Date.now();
        }

        report();
    };

    const timer = setInterval(tick, SYNC.tickMs);

    const offs = [
        bus.on('broadcast', (next) => {
            broadcast = next.broadcast;
            tick();
        }),
        bus.on('voice-volume', (next) => {
            volume = next.volume;
            audio.volume = volume;
        }),
        // Runs inside the click, so the browser counts it as the player's own.
        bus.on('broadcast-listen', () => {
            const on = airing(broadcast, net.serverNow());

            if (!on || loaded !== on.id) return;

            audio.currentTime = position(on);
            play();
        }),
    ];

    return () => {
        clearInterval(timer);
        offs.forEach((off) => off());
        audio.pause();
        audio.removeAttribute('src');
        audio.load();
    };
}

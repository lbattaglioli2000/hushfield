import type { EventBus, FishingPhase } from './bus';
import type { Copy } from './copy';
import { userMessage } from './net/http';
import type { WorldNet } from './net/WorldNet';
import type { FishingCast } from './protocol/types';

/** After the bite window closes, how long to wait before asking the server how it went. */
const LATE_GRACE_MS = 250;

/** What the scene draws for the line: the phase, and on reeling in, whether something came up. */
export type FishingView = {
    showFishing(phase: FishingPhase, landed?: boolean): void;
};

/**
 * Where a cast's line is at a moment of server time: waiting for a bite, biting
 * (a reel now lands it), or gone (the bite let go). Only drives what the player
 * sees; the server judges the reel against its own clock.
 */
export function linePhase(
    cast: FishingCast,
    nowMs: number,
): 'waiting' | 'bite' | 'gone' {
    if (nowMs < cast.bite_at_ms) return 'waiting';

    return nowMs <= cast.reel_by_ms ? 'bite' : 'gone';
}

/**
 * Connects the Cast/Reel key to the server and the scene. The line shows the
 * bite when the cast said it would; a line left past its window is reeled in
 * automatically, so the server says it got away. Returns the cleanup.
 */
export function connectFishing(
    net: WorldNet,
    bus: EventBus,
    copy: Copy,
    scene: () => FishingView | null,
): () => void {
    let cast: FishingCast | null = null;
    let phase: FishingPhase = 'idle';
    let timer: ReturnType<typeof setTimeout> | null = null;
    let instanceId: number | null = null;

    const set = (next: FishingPhase, landed?: boolean) => {
        phase = next;
        bus.emit('fishing', { phase: next });
        scene()?.showFishing(next, landed);
    };
    const stopTimer = () => {
        if (timer) clearTimeout(timer);
        timer = null;
    };
    const abandon = () => {
        cast = null;
        stopTimer();

        if (phase !== 'idle') set('idle');
    };
    const fail = (error: unknown) => {
        set('idle', false);
        bus.emit('fishing-result', {
            ok: false,
            message: userMessage(error, copy),
            catch: null,
            paid: 0,
        });
    };

    const reel = () => {
        if (!cast) return;

        const id = cast.id;
        cast = null;
        stopTimer();
        set('reeling');
        net.reelIn(id)
            .then(({ message, wallet, reel: result }) => {
                bus.emit('wallet', wallet);
                set('idle', result.outcome === 'landed');
                bus.emit('fishing-result', {
                    ok: result.outcome === 'landed',
                    message,
                    catch: result.catch,
                    paid: result.paid,
                });
            })
            .catch(fail);
    };

    // Re-checks the line at each moment it changes: the bite, then the end of the window.
    const watch = () => {
        stopTimer();

        if (!cast) return;

        const now = net.serverNow();
        const at = linePhase(cast, now);

        if (at === 'gone') {
            reel();

            return;
        }

        if (at === 'bite' && phase !== 'bite') set('bite');

        const next =
            at === 'waiting'
                ? cast.bite_at_ms
                : cast.reel_by_ms + LATE_GRACE_MS;
        timer = setTimeout(watch, Math.max(0, next - now));
    };

    const cleanups = [
        bus.on('cast-line', () => {
            if (phase === 'casting' || phase === 'reeling') return;

            abandon();
            set('casting');
            net.castLine()
                .then((next) => {
                    cast = next;
                    set('waiting');
                    watch();
                })
                .catch(fail);
        }),
        bus.on('reel-in', reel),
        bus.on('fishing-interrupted', abandon),
        // The server takes the line out of the water when the player leaves the room.
        bus.on('instance', ({ id }) => {
            if (instanceId !== null && id !== instanceId) abandon();

            instanceId = id;
        }),
    ];

    return () => {
        stopTimer();
        cleanups.forEach((cleanup) => cleanup());
    };
}

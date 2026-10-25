import Phaser from 'phaser';
import { EventBus } from './bus';
import type { RealtimeConfig } from './net';
import { RefusedError, WorldNet } from './net';
import type { AssetManifest } from './protocol';
import { TownSquare } from './scenes/TownSquare';

export { EventBus } from './bus';
export type { GameEvents, ConnectionState } from './bus';
export { PROTOCOL } from './protocol';

export type GameConfig = {
    baseUrl: string;
    roomSlug: string;
    manifestUrl: string;
    heartbeatSeconds: number;
    afkSeconds: number;
    bubbleSeconds: number;
    maxPathSteps: number;
    realtime: RealtimeConfig;
    copy: Record<string, string>;
};

export type GameHandle = { destroy(): void };

/**
 * Boots the game into `parent`. Framework-free: the host talks to it only
 * through `bus`. The returned destroy() is idempotent and releases everything:
 * the Phaser instance, every Echo channel, the heartbeat, and bus listeners.
 */
export function startGame(parent: HTMLElement, config: GameConfig, bus: EventBus): GameHandle {
    let destroyed = false;
    let game: Phaser.Game | null = null;
    let scene: TownSquare | null = null;
    const cleanups: Array<() => void> = [];

    const net = new WorldNet(
        {
            baseUrl: config.baseUrl,
            roomSlug: config.roomSlug,
            heartbeatSeconds: config.heartbeatSeconds,
            realtime: config.realtime,
            copy: config.copy,
        },
        bus,
        {
            snapshot: (s, self) => scene?.snapshot(s, self),
            joined: (e) => scene?.joined(e),
            left: (e) => scene?.left(e),
            moved: (e) => scene?.moved(e),
            chat: (e) => scene?.chat(e),
            idle: (id, idle) => scene?.idle(id, idle),
        },
    );

    // AFK is cosmetic: after a quiet spell, others see this avatar dimmed.
    let lastInput = Date.now();
    let idle = false;
    const touch = () => {
        lastInput = Date.now();

        if (idle) {
            idle = false;
            net.whisperIdle(false);
        }
    };
    const afkTimer = setInterval(() => {
        if (!idle && Date.now() - lastInput > config.afkSeconds * 1000) {
            idle = true;
            net.whisperIdle(true);
        }
    }, 5000);
    cleanups.push(() => clearInterval(afkTimer));
    cleanups.push(bus.on('input-activity', touch));

    cleanups.push(
        bus.on('send-chat', ({ body }) => {
            touch();
            net.chat(body)
                .then((message) => message && scene?.sayOwn(message.id, message.body))
                .catch((error: unknown) =>
                    bus.emit('chat-error', {
                        message: error instanceof Error ? error.message : String(error),
                    }),
                );
        }),
    );

    cleanups.push(
        bus.on('send-report', ({ userId, messageId, reason }) => {
            net.report(userId, messageId, reason)
                .then((message) => bus.emit('report-result', { ok: true, message }))
                .catch((error: unknown) =>
                    bus.emit('report-result', {
                        ok: false,
                        message: error instanceof RefusedError ? error.message : String(error),
                    }),
                );
        }),
    );

    void (async () => {
        const manifest = (await fetch(config.manifestUrl).then((r) => r.json())) as AssetManifest;

        if (destroyed) return;

        game = new Phaser.Game({
            type: Phaser.AUTO,
            parent,
            backgroundColor: '#1d2330',
            scale: { mode: Phaser.Scale.RESIZE, width: '100%', height: '100%' },
            render: { antialias: true, roundPixels: true },
            input: { activePointers: 2 },
            fps: { target: 60 },
        });
        game.scene.add('TownSquare', TownSquare, true, {
            net,
            bus,
            options: {
                manifest,
                bubbleSeconds: config.bubbleSeconds,
                maxPathSteps: config.maxPathSteps,
                copy: config.copy,
            },
        });
        scene = game.scene.getScene('TownSquare') as TownSquare;

        await net.start();
    })().catch((error: unknown) => {
        if (destroyed) return;

        bus.emit('connection', {
            state: 'reconnecting',
            message: error instanceof Error ? error.message : config.copy.reconnecting,
        });
    });

    return {
        destroy() {
            if (destroyed) return;

            destroyed = true;
            cleanups.forEach((cleanup) => cleanup());
            net.destroy();
            game?.destroy(true);
            game = null;
            scene = null;
        },
    };
}

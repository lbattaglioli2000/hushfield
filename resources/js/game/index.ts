import Phaser from 'phaser';
import type { EventBus } from './bus';
import type { Copy } from './copy';
import { connectEconomy } from './economy';
import { userMessage } from './net/http';
import type { RealtimeConfig } from './net/realtime';
import { WorldNet } from './net/WorldNet';
import type { AssetManifest } from './protocol/types';
import { PROTOCOL } from './protocol/types';
import { RoomScene } from './scenes/RoomScene';
import { COLORS } from './theme';

export { EventBus } from './bus';
export type { ConnectionState, FatalKind, GameEvents } from './bus';
export type { Copy } from './copy';
export type { RealtimeConfig } from './net/realtime';
export { PROTOCOL } from './protocol/types';
export type { Item, ItemSlot, Wallet } from './protocol/types';

export type ReportReasonOption = { value: string; label: string };

/** Exactly what PlayController sends as the `game` prop. */
export type GameBootProps = {
    protocol: number;
    roomSlug: string;
    maxPathSteps: number;
    realtime: RealtimeConfig;
    heartbeatSeconds: number;
    afkSeconds: number;
    chatMaxLength: number;
    bubbleSeconds: number;
    manifestUrl: string;
    reportReasons: ReportReasonOption[];
    copy: Copy;
};

/** Boot props plus where the API lives: '' on the web, an absolute origin in a native shell. */
export type GameConfig = GameBootProps & { baseUrl: string };

export type GameHandle = { destroy(): void };

/** How often to check whether the player has gone quiet long enough to show as away. */
const AFK_POLL_MS = 5000;

/**
 * Boots the game into `parent`. Framework-free: the host talks to it only
 * through `bus`. The returned destroy() is idempotent and releases everything:
 * the Phaser instance, every Echo channel, the heartbeat, and bus listeners.
 */
export function startGame(
    parent: HTMLElement,
    config: GameConfig,
    bus: EventBus,
): GameHandle {
    // The page itself came from a newer server than this bundle: no point booting.
    if (config.protocol > PROTOCOL) {
        bus.emit('fatal', {
            kind: 'please_reload',
            message: config.copy.please_reload,
        });

        return { destroy: () => undefined };
    }

    let destroyed = false;
    let game: Phaser.Game | null = null;
    const cleanups: Array<() => void> = [];

    // Created once the manifest is in; the scene buffers whatever arrives before Phaser boots it.
    let scene: RoomScene | null = null;
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
    }, AFK_POLL_MS);
    cleanups.push(() => clearInterval(afkTimer));
    cleanups.push(bus.on('input-activity', touch));

    cleanups.push(
        bus.on('send-chat', ({ body }) => {
            touch();
            net.chat(body)
                .then(
                    (message) =>
                        message && scene?.sayOwn(message.id, message.body),
                )
                .catch((error: unknown) =>
                    bus.emit('chat-error', {
                        message: userMessage(error, config.copy),
                    }),
                );
        }),
    );

    cleanups.push(
        bus.on('send-report', ({ userId, messageId, reason }) => {
            net.report(userId, messageId, reason)
                .then((message) =>
                    bus.emit('report-result', { ok: true, message }),
                )
                .catch((error: unknown) =>
                    bus.emit('report-result', {
                        ok: false,
                        message: userMessage(error, config.copy),
                    }),
                );
        }),
    );

    const economy = connectEconomy(net, bus, config.copy);
    cleanups.push(() => economy.dispose());

    void (async () => {
        const response = await fetch(config.manifestUrl);

        if (!response.ok) throw new Error(`Asset manifest ${response.status}`);

        const manifest = (await response.json()) as AssetManifest;

        if (destroyed) return;

        scene = new RoomScene(net, bus, {
            manifest,
            bubbleSeconds: config.bubbleSeconds,
            maxPathSteps: config.maxPathSteps,
        });
        game = new Phaser.Game({
            type: Phaser.AUTO,
            parent,
            backgroundColor: COLORS.night,
            scale: { mode: Phaser.Scale.RESIZE, width: '100%', height: '100%' },
            render: { antialias: true, roundPixels: true },
            input: { activePointers: 2 },
            fps: { target: 60 },
        });
        game.scene.add(RoomScene.KEY, scene, true);

        await net.start();

        if (!destroyed) economy.loadWallet();
    })().catch((error: unknown) => {
        // Boot failures are not reconnects: show them, so the player isn't left waiting.
        if (!destroyed) net.abort(error);
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

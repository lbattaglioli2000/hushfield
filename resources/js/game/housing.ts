import type { EventBus } from './bus';
import type { Copy } from './copy';
import { userMessage } from './net/http';
import type { WorldNet } from './net/WorldNet';
import type { RoomScene } from './scenes/RoomScene';

/**
 * Connects the residences overlays to the game: travelling to homes, the
 * resident's own door, and decorate mode. Returns the cleanup.
 */
export function connectHousing(
    net: WorldNet,
    bus: EventBus,
    copy: Copy,
    scene: () => RoomScene | null,
): () => void {
    const fail = (error: unknown) =>
        bus.emit('home-result', {
            ok: false,
            message: userMessage(error, copy),
        });
    const loadHomes = () =>
        net.homes().then((homes) => bus.emit('homes', homes));

    const cleanups = [
        bus.on('travel', ({ slug, instanceId }) => {
            scene()?.travel(() => net.joinRoom(slug, instanceId));
        }),
        bus.on('load-homes', () => {
            loadHomes().catch(fail);
        }),
        bus.on('set-home-visitors', ({ visitors }) => {
            net.setHomeVisitors(visitors)
                .then((message) => {
                    bus.emit('home-result', { ok: true, message });

                    return loadHomes();
                })
                .catch(fail);
        }),
        bus.on('decorate', ({ active, armedItemId }) => {
            scene()?.setDecorating(active, armedItemId);
        }),
    ];

    return () => cleanups.forEach((cleanup) => cleanup());
}

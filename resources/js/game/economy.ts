import type { EventBus } from './bus';
import type { Copy } from './copy';
import { userMessage } from './net/http';
import type { WalletUpdate, WorldNet } from './net/WorldNet';

/**
 * Connects the shop and inventory overlays to the server. The overlays only
 * emit requests on the bus; the answers come back as 'wallet', 'shop' and
 * 'economy-result'. Returns the cleanup and a way to load the wallet once the
 * world session exists.
 */
export function connectEconomy(
    net: WorldNet,
    bus: EventBus,
    copy: Copy,
): { loadWallet(): void; dispose(): void } {
    const fail = (error: unknown) =>
        bus.emit('economy-result', {
            ok: false,
            message: userMessage(error, copy),
        });
    const applied = ({ message, wallet }: WalletUpdate) => {
        bus.emit('wallet', wallet);
        bus.emit('economy-result', { ok: true, message });
    };

    const cleanups = [
        bus.on('load-shop', () => {
            net.shop()
                .then((items) => bus.emit('shop', { items }))
                .catch(fail);
        }),
        bus.on('purchase-item', ({ itemId }) => {
            net.purchase(itemId).then(applied).catch(fail);
        }),
        bus.on('claim-stipend', () => {
            net.claimStipend().then(applied).catch(fail);
        }),
    ];

    return {
        loadWallet() {
            // Without a wallet the overlays stay hidden; the game itself is unaffected.
            net.wallet()
                .then((wallet) => bus.emit('wallet', wallet))
                .catch(() => undefined);
        },
        dispose() {
            cleanups.forEach((cleanup) => cleanup());
        },
    };
}

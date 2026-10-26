import type { EventBus } from './bus';
import type { Copy } from './copy';
import { RefusedError, userMessage } from './net/http';
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

    const loadShop = () => {
        net.shop()
            .then(({ items, open }) => bus.emit('shop', { items, open }))
            .catch(fail);
    };

    /** The key of each purchase sent and not yet answered, by item. */
    const pending = new Map<number, string>();

    const cleanups = [
        bus.on('load-shop', loadShop),
        bus.on('purchase-item', ({ itemId }) => {
            // One key per purchase: until the server answers, a double click or a retry
            // resends it and buys nothing more. After an answer, the next click buys another.
            const key = pending.get(itemId) ?? crypto.randomUUID();
            pending.set(itemId, key);

            net.purchase(itemId, key)
                .then((update) => {
                    pending.delete(itemId);
                    applied(update);
                })
                .catch((error: unknown) => {
                    // Refused: nothing was bought. Lost on the way: the retry keeps the key.
                    if (error instanceof RefusedError) pending.delete(itemId);

                    fail(error);

                    // Closed while the panel was open: refresh it so every Buy button says so.
                    if (
                        error instanceof RefusedError &&
                        error.reason === 'shop_closed'
                    ) {
                        loadShop();
                    }
                });
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

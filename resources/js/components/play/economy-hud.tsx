import { useEffect, useState } from 'react';
import InventoryPanel from '@/components/play/inventory-panel';
import ShopPanel from '@/components/play/shop-panel';
import { Button } from '@/components/ui/button';
import type { Copy, EventBus, GameEvents, Wallet } from '@/game';
import { useGameBus } from '@/hooks/use-game-bus';

const RESULT_MS = 5000;

/**
 * Coins, the daily stipend, and the buttons that open the shop and inventory.
 * Hidden until the game has loaded the wallet.
 */
export default function EconomyHud({
    bus,
    copy,
}: {
    bus: EventBus;
    copy: Copy;
}) {
    const [wallet, setWallet] = useState<Wallet | null>(null);
    const [panel, setPanel] = useState<'shop' | 'inventory' | null>(null);
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<GameEvents['economy-result'] | null>(
        null,
    );

    useGameBus(bus, 'wallet', setWallet);
    useGameBus(bus, 'economy-result', (next) => {
        setBusy(false);
        setResult(next);
    });

    useEffect(() => {
        if (!result) return;

        const timer = setTimeout(() => setResult(null), RESULT_MS);

        return () => clearTimeout(timer);
    }, [result]);

    if (!wallet) return null;

    const request = <K extends 'purchase-item' | 'claim-stipend'>(
        event: K,
        payload: GameEvents[K],
    ) => {
        setBusy(true);
        setResult(null);
        bus.emit(event, payload);
    };

    const resultLine = result && (
        <p
            role="status"
            className={`rounded-md px-3 py-2 text-sm ${result.ok ? 'bg-emerald-950/80' : 'bg-red-950/80'}`}
            data-test="economy-result"
        >
            {result.message}
        </p>
    );

    return (
        <>
            <div className="absolute top-3 right-3 flex max-w-xs flex-col items-end gap-2">
                <div className="flex items-center gap-2">
                    <span
                        className="rounded-full bg-black/45 px-3 py-1 text-sm font-medium tabular-nums backdrop-blur"
                        data-test="coin-balance"
                    >
                        <span aria-hidden className="mr-1 text-amber-300">
                            ●
                        </span>
                        {wallet.balance}
                        <span className="sr-only"> coins</span>
                    </span>
                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => {
                            setPanel('shop');
                            bus.emit('load-shop', {});
                        }}
                        data-test="open-shop"
                    >
                        Shop
                    </Button>
                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => setPanel('inventory')}
                        data-test="open-inventory"
                    >
                        Belongings
                    </Button>
                </div>

                {wallet.stipend.available && (
                    <div
                        className="flex flex-col gap-2 rounded-md bg-black/60 p-3 text-sm backdrop-blur"
                        data-test="stipend-offer"
                    >
                        <p>{copy.stipend_offer}</p>
                        <Button
                            size="sm"
                            disabled={busy}
                            onClick={() => request('claim-stipend', {})}
                            data-test="claim-stipend"
                        >
                            Accept {wallet.stipend.amount} coins
                        </Button>
                    </div>
                )}

                {panel === null && resultLine}
            </div>

            <ShopPanel
                bus={bus}
                copy={copy}
                wallet={wallet}
                open={panel === 'shop'}
                onClose={() => setPanel(null)}
                busy={busy}
                onBuy={(item) => request('purchase-item', { itemId: item.id })}
                result={resultLine}
            />
            <InventoryPanel
                copy={copy}
                wallet={wallet}
                open={panel === 'inventory'}
                onClose={() => setPanel(null)}
            />
        </>
    );
}

import { useEffect, useState } from 'react';
import InventoryPanel from '@/components/play/inventory-panel';
import ShopPanel from '@/components/play/shop-panel';
import { OnTray } from '@/components/play/signal-tray';
import AmberReadout from '@/components/radio/amber-readout';
import RadioKey from '@/components/radio/radio-key';
import SignalCard from '@/components/radio/signal-card';
import type { Copy, EventBus, GameEvents, Wallet } from '@/game';
import { useGameBus } from '@/hooks/use-game-bus';

const RESULT_MS = 5000;

/**
 * The coin readout, the Shop and Belongings keys, and the daily stipend.
 * Renders into the set's key grid; hidden until the game has loaded the wallet.
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
        <SignalCard
            role="status"
            tone={result.ok ? 'amber' : 'red'}
            data-test="economy-result"
        >
            {result.message}
        </SignalCard>
    );

    return (
        <>
            <AmberReadout
                label="Coins"
                className="col-span-2 text-lg"
                data-test="coin-balance"
            >
                {wallet.balance}
            </AmberReadout>
            <RadioKey
                onClick={() => {
                    setPanel('shop');
                    bus.emit('load-shop', {});
                }}
                data-test="open-shop"
            >
                Shop
            </RadioKey>
            <RadioKey
                onClick={() => setPanel('inventory')}
                data-test="open-inventory"
            >
                Belongings
            </RadioKey>

            <OnTray>
                {wallet.stipend.available && (
                    <SignalCard
                        tone="amber"
                        className="grid gap-2"
                        data-test="stipend-offer"
                    >
                        <p>{copy.stipend_offer}</p>
                        <RadioKey
                            size="sm"
                            className="justify-self-start"
                            disabled={busy}
                            onClick={() => request('claim-stipend', {})}
                            data-test="claim-stipend"
                        >
                            Accept {wallet.stipend.amount} coins
                        </RadioKey>
                    </SignalCard>
                )}
                {panel === null && resultLine}
            </OnTray>

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

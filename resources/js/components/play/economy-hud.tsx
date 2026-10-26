import { useRef, useState } from 'react';
import InventoryPanel from '@/components/play/inventory-panel';
import ShopPanel from '@/components/play/shop-panel';
import { OnTray } from '@/components/play/signal-tray';
import AmberReadout from '@/components/radio/amber-readout';
import RadioKey from '@/components/radio/radio-key';
import SignalCard, { ResultCard } from '@/components/radio/signal-card';
import type { Copy, EventBus, GameEvents, Wallet } from '@/game';
import { useFleeting } from '@/hooks/use-fleeting';
import { useFixture, useGameBus } from '@/hooks/use-game-bus';

const RESULT_MS = 5000;

/**
 * The coin readout, the Belongings key, the daily stipend, and the shop panel.
 * The shop is a place: its panel opens at a shop counter on the map, and closes
 * when the player leaves that room. Renders into the set's key grid; hidden
 * until the game has loaded the wallet.
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
    const [result, setResult] =
        useFleeting<GameEvents['economy-result']>(RESULT_MS);

    // The room the shop was opened in; leaving it closes the panel.
    const shopRoom = useRef<string | null>(null);
    const room = useRef<string | null>(null);

    useGameBus(bus, 'room', (next) => {
        room.current = next.slug;

        if (shopRoom.current !== null && shopRoom.current !== next.slug) {
            shopRoom.current = null;
            setPanel((open) => (open === 'shop' ? null : open));
        }
    });
    useFixture(bus, 'shop', () => {
        shopRoom.current = room.current;
        setPanel('shop');
        bus.emit('load-shop', {});
    });
    useGameBus(bus, 'wallet', setWallet);
    useGameBus(bus, 'economy-result', (next) => {
        setBusy(false);
        setResult(next);
    });

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
        <ResultCard result={result} data-test="economy-result" />
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
                onClose={() => {
                    shopRoom.current = null;
                    setPanel(null);
                }}
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

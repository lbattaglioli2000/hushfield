import type { ReactNode } from 'react';
import { useState } from 'react';
import ItemCard from '@/components/play/item-card';
import RadioDialog from '@/components/radio/radio-dialog';
import RadioKey from '@/components/radio/radio-key';
import SignalCard from '@/components/radio/signal-card';
import type { Copy, EventBus, GameEvents, Item, Wallet } from '@/game';
import { useGameBus } from '@/hooks/use-game-bus';

export default function ShopPanel({
    bus,
    copy,
    wallet,
    open,
    onClose,
    busy,
    onBuy,
    result,
}: {
    bus: EventBus;
    copy: Copy;
    wallet: Wallet;
    open: boolean;
    onClose: () => void;
    busy: boolean;
    onBuy: (item: Item) => void;
    /** The outcome of the last purchase, shown above the shelves. */
    result: ReactNode;
}) {
    const [shop, setShop] = useState<GameEvents['shop'] | null>(null);

    useGameBus(bus, 'shop', setShop);

    const owned = new Set(wallet.inventory.map((item) => item.id));

    const action = (item: Item) => {
        if (owned.has(item.id)) {
            return (
                <RadioKey size="sm" variant="glass" disabled className="w-full">
                    Owned
                </RadioKey>
            );
        }

        if (item.members_only) {
            return (
                <RadioKey size="sm" variant="glass" disabled className="w-full">
                    {copy.members_only}
                </RadioKey>
            );
        }

        if (!shop?.open) {
            return (
                <RadioKey size="sm" variant="glass" disabled className="w-full">
                    Closed
                </RadioKey>
            );
        }

        return (
            <RadioKey
                size="sm"
                className="w-full"
                disabled={busy || item.price > wallet.balance}
                onClick={() => onBuy(item)}
                data-test={`buy-${item.slug}`}
            >
                Buy · {item.price} coins
            </RadioKey>
        );
    };

    return (
        <RadioDialog
            open={open}
            onOpenChange={(next) => !next && onClose()}
            band={`Shop · ${wallet.balance} coins`}
            title={copy.shop_title}
            description={copy.shop_intro}
            className="sm:max-w-2xl"
            data-test="shop-panel"
        >
            {/* While closed the notice says it all; a refused purchase would only repeat it. */}
            {shop?.open !== false && result}
            {shop && !shop.open && (
                <SignalCard data-test="shop-closed">
                    {copy.shop_closed}
                </SignalCard>
            )}
            {shop === null ? (
                <p className="text-bakelite/60">Opening…</p>
            ) : (
                <ul className="grid max-h-[60vh] gap-3 overflow-y-auto pr-1 sm:grid-cols-2">
                    {shop.items.map((item) => (
                        <ItemCard
                            key={item.id}
                            item={item}
                            footer={action(item)}
                        />
                    ))}
                </ul>
            )}
        </RadioDialog>
    );
}

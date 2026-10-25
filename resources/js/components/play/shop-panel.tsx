import type { ReactNode } from 'react';
import { useState } from 'react';
import ItemCard from '@/components/play/item-card';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
                <Button
                    size="sm"
                    variant="secondary"
                    disabled
                    className="w-full"
                >
                    Owned
                </Button>
            );
        }

        if (item.members_only) {
            return (
                <Button
                    size="sm"
                    variant="secondary"
                    disabled
                    className="w-full"
                >
                    {copy.members_only}
                </Button>
            );
        }

        if (!shop?.open) {
            return (
                <Button
                    size="sm"
                    variant="secondary"
                    disabled
                    className="w-full"
                >
                    Closed
                </Button>
            );
        }

        return (
            <Button
                size="sm"
                className="w-full"
                disabled={busy || item.price > wallet.balance}
                onClick={() => onBuy(item)}
                data-test={`buy-${item.slug}`}
            >
                Buy · {item.price} coins
            </Button>
        );
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="sm:max-w-2xl" data-test="shop-panel">
                <DialogHeader>
                    <DialogTitle>{copy.shop_title}</DialogTitle>
                    <DialogDescription>
                        {copy.shop_intro} You have {wallet.balance} coins.
                    </DialogDescription>
                </DialogHeader>
                {/* While closed the notice says it all; a refused purchase would only repeat it. */}
                {shop?.open !== false && result}
                {shop && !shop.open && (
                    <p
                        className="rounded-md bg-muted px-3 py-2 text-sm"
                        data-test="shop-closed"
                    >
                        {copy.shop_closed}
                    </p>
                )}
                {shop === null ? (
                    <p className="text-sm text-muted-foreground">Opening…</p>
                ) : (
                    <ul className="grid max-h-[60vh] gap-3 overflow-y-auto sm:grid-cols-2">
                        {shop.items.map((item) => (
                            <ItemCard
                                key={item.id}
                                item={item}
                                footer={action(item)}
                            />
                        ))}
                    </ul>
                )}
            </DialogContent>
        </Dialog>
    );
}

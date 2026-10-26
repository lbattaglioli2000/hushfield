import type { ReactNode } from 'react';
import { useState } from 'react';
import ItemCard from '@/components/play/item-card';
import RadioDialog from '@/components/radio/radio-dialog';
import RadioKey from '@/components/radio/radio-key';
import SignalCard from '@/components/radio/signal-card';
import type { Copy, EventBus, GameEvents, Item, Wallet } from '@/game';
import { useGameBus } from '@/hooks/use-game-bus';

/**
 * The shelves as families: each item with its other colours, in the order the
 * shop lists them, the family's own item first.
 */
function families(items: Item[]): Item[][] {
    const byFamily = new Map<number, Item[]>();

    for (const item of items) {
        byFamily.set(item.family, [...(byFamily.get(item.family) ?? []), item]);
    }

    return [...byFamily.values()].map((family) =>
        family.sort(
            (a, b) =>
                Number(b.id === b.family) - Number(a.id === a.family) ||
                a.id - b.id,
        ),
    );
}

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
    /** The colour picked in each family, by family. */
    const [picked, setPicked] = useState<Record<number, number>>({});

    useGameBus(bus, 'shop', setShop);

    const owned = new Map(
        wallet.inventory.map(({ item, pieces }) => [item.id, pieces.length]),
    );

    const action = (item: Item) => {
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
                    {families(shop.items).map((family) => {
                        const item =
                            family.find(
                                ({ id }) => id === picked[family[0].family],
                            ) ?? family[0];
                        const count = owned.get(item.id) ?? 0;

                        return (
                            <ItemCard
                                key={item.family}
                                item={item}
                                badge={
                                    count > 0 ? `You own ${count}` : undefined
                                }
                                colours={
                                    family.length > 1 && (
                                        <Swatches
                                            family={family}
                                            picked={item}
                                            onPick={(colour) =>
                                                setPicked((current) => ({
                                                    ...current,
                                                    [colour.family]: colour.id,
                                                }))
                                            }
                                        />
                                    )
                                }
                                footer={action(item)}
                            />
                        );
                    })}
                </ul>
            )}
        </RadioDialog>
    );
}

/** One swatch per colour of a family; picking one shows and sells that colour. */
function Swatches({
    family,
    picked,
    onPick,
}: {
    family: Item[];
    picked: Item;
    onPick: (colour: Item) => void;
}) {
    return (
        <div
            role="radiogroup"
            aria-label="Colour"
            className="flex flex-wrap items-center gap-2"
        >
            {family.map((colour) => (
                <button
                    key={colour.id}
                    type="button"
                    role="radio"
                    aria-checked={colour.id === picked.id}
                    aria-label={colour.colour ?? colour.name}
                    title={colour.colour ?? colour.name}
                    onClick={() => onPick(colour)}
                    className={`size-6 rounded-full border border-black/30 shadow-[inset_0_-2px_0_rgb(0_0_0/0.25)] outline-none focus-visible:ring-2 focus-visible:ring-amber ${colour.id === picked.id ? 'ring-2 ring-amber ring-offset-2 ring-offset-glass' : ''} ${colour.swatch ? '' : 'bg-metal'}`}
                    style={
                        colour.swatch
                            ? { backgroundColor: colour.swatch }
                            : undefined
                    }
                    data-test={`swatch-${colour.slug}`}
                />
            ))}
            <span className="text-xs text-bakelite/70">{picked.colour}</span>
        </div>
    );
}

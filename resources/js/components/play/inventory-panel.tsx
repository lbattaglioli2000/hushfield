import type { ReactNode } from 'react';
import ItemCard from '@/components/play/item-card';
import RadioDialog from '@/components/radio/radio-dialog';
import RadioKey from '@/components/radio/radio-key';
import type { Copy, Wallet } from '@/game';

/** Slots a resident wears an item in; furniture goes in their home instead. */
const WORN_SLOTS = ['head', 'face', 'body', 'feet', 'hand', 'pin'];

export default function InventoryPanel({
    copy,
    wallet,
    open,
    onClose,
    busy,
    onWear,
    result,
    books,
}: {
    copy: Copy;
    wallet: Wallet;
    open: boolean;
    onClose: () => void;
    busy: boolean;
    /** Puts a piece on (true) or takes it off. */
    onWear: (piece: number, on: boolean) => void;
    /** The outcome of the last request, shown above the belongings. */
    result: ReactNode;
    /** The player's books, listed under their other belongings. */
    books?: ReactNode;
}) {
    return (
        <RadioDialog
            open={open}
            onOpenChange={(next) => !next && onClose()}
            band={`Belongings · ${wallet.balance} coins`}
            title={copy.inventory_title}
            className="sm:max-w-2xl"
            data-test="inventory-panel"
        >
            {result}
            {wallet.inventory.length === 0 ? (
                <p className="text-bakelite/60">{copy.inventory_empty}</p>
            ) : (
                <ul className="grid max-h-[60vh] gap-3 overflow-y-auto pr-1 sm:grid-cols-2">
                    {wallet.inventory.map(({ item, pieces }) => {
                        // One piece of an item is worn at a time: that one, or the first.
                        const worn = pieces.find((piece) =>
                            wallet.worn.includes(piece),
                        );

                        return (
                            <ItemCard
                                key={item.id}
                                item={item}
                                badge={
                                    pieces.length > 1
                                        ? `×${pieces.length}`
                                        : undefined
                                }
                                footer={
                                    WORN_SLOTS.includes(item.slot) && (
                                        <RadioKey
                                            size="sm"
                                            variant={worn ? 'glass' : undefined}
                                            className="w-full"
                                            disabled={busy}
                                            onClick={() =>
                                                onWear(
                                                    worn ?? pieces[0],
                                                    worn === undefined,
                                                )
                                            }
                                            data-test={`wear-${item.slug}`}
                                        >
                                            {worn ? 'Take off' : 'Wear'}
                                        </RadioKey>
                                    )
                                }
                            />
                        );
                    })}
                </ul>
            )}
            {books}
        </RadioDialog>
    );
}

import ItemCard from '@/components/play/item-card';
import RadioDialog from '@/components/radio/radio-dialog';
import type { Copy, Wallet } from '@/game';

export default function InventoryPanel({
    copy,
    wallet,
    open,
    onClose,
}: {
    copy: Copy;
    wallet: Wallet;
    open: boolean;
    onClose: () => void;
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
            {wallet.inventory.length === 0 ? (
                <p className="text-bakelite/60">{copy.inventory_empty}</p>
            ) : (
                <ul className="grid max-h-[60vh] gap-3 overflow-y-auto pr-1 sm:grid-cols-2">
                    {wallet.inventory.map(({ item, pieces }) => (
                        <ItemCard
                            key={item.id}
                            item={item}
                            badge={
                                pieces.length > 1
                                    ? `×${pieces.length}`
                                    : undefined
                            }
                        />
                    ))}
                </ul>
            )}
        </RadioDialog>
    );
}

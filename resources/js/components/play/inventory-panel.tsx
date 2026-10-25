import ItemCard from '@/components/play/item-card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="sm:max-w-2xl" data-test="inventory-panel">
                <DialogHeader>
                    <DialogTitle>{copy.inventory_title}</DialogTitle>
                    <DialogDescription>
                        {wallet.balance} coins
                    </DialogDescription>
                </DialogHeader>
                {wallet.inventory.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {copy.inventory_empty}
                    </p>
                ) : (
                    <ul className="grid max-h-[60vh] gap-3 overflow-y-auto sm:grid-cols-2">
                        {wallet.inventory.map((item) => (
                            <ItemCard key={item.id} item={item} />
                        ))}
                    </ul>
                )}
            </DialogContent>
        </Dialog>
    );
}

import type { ReactNode } from 'react';
import type { Item, ItemSlot } from '@/game';

const SLOT_LABELS: Record<ItemSlot, string> = {
    head: 'Head',
    face: 'Face',
    body: 'Body',
    feet: 'Feet',
    hand: 'Hand',
    pin: 'Pin',
    background: 'Background',
    furniture: 'Furniture',
};

/** The rarity's colour, until items have artwork. */
const RARITY_SWATCH: Record<Item['rarity'], string> = {
    common: 'bg-stone-400',
    uncommon: 'bg-emerald-500',
    rare: 'bg-sky-500',
    peculiar: 'bg-amber-400',
};

/** One item as the shop and the inventory show it; `footer` holds the price or actions. */
export default function ItemCard({
    item,
    footer,
}: {
    item: Item;
    footer?: ReactNode;
}) {
    return (
        <li
            className="flex flex-col gap-2 rounded-lg border bg-card p-3"
            data-test={`item-${item.slug}`}
        >
            <div className="flex items-start gap-3">
                <span
                    aria-hidden
                    className={`mt-0.5 size-9 shrink-0 rounded-md ${RARITY_SWATCH[item.rarity]}`}
                />
                <div className="min-w-0">
                    <p className="leading-tight font-medium">{item.name}</p>
                    <p className="text-xs text-muted-foreground capitalize">
                        {SLOT_LABELS[item.slot]} · {item.rarity}
                    </p>
                </div>
            </div>
            {item.description && (
                <p className="text-sm text-muted-foreground">
                    {item.description}
                </p>
            )}
            {footer && <div className="mt-auto">{footer}</div>}
        </li>
    );
}

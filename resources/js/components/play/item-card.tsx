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
    common: 'bg-metal',
    uncommon: 'bg-[#7fa36b]',
    rare: 'bg-[#6f9bb8]',
    peculiar: 'bg-amber',
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
            className="flex flex-col gap-2 rounded-md border border-bakelite/10 bg-bakelite/[0.03] p-3"
            data-test={`item-${item.slug}`}
        >
            <div className="flex items-start gap-3">
                <span
                    aria-hidden
                    className={`mt-0.5 size-9 shrink-0 rounded-sm shadow-[inset_0_-3px_0_rgb(0_0_0/0.25)] ${RARITY_SWATCH[item.rarity]}`}
                />
                <div className="min-w-0">
                    <p className="font-sans text-base leading-tight font-bold tracking-wide amber-glow uppercase">
                        {item.name}
                    </p>
                    <p className="text-[0.65rem] tracking-[0.15em] text-bakelite/55 uppercase">
                        {SLOT_LABELS[item.slot]} · {item.rarity}
                    </p>
                </div>
            </div>
            {item.description && (
                <p className="text-xs leading-relaxed text-bakelite/75">
                    {item.description}
                </p>
            )}
            {footer && <div className="mt-auto pt-1">{footer}</div>}
        </li>
    );
}

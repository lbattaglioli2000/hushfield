<?php

namespace App\Actions\Items;

use App\Domain\Items\ItemSlot;
use App\Exceptions\ModerationActionRefused;
use App\Models\Item;
use Illuminate\Support\Str;

/**
 * Staff add a colour of a furniture item: a new item grouped under it, named
 * and described like it and standing on the same tiles (see Item::INHERITED),
 * with its own price, art and place in the shop. It starts off the shelves,
 * so its art can be uploaded before anyone buys it.
 */
final readonly class AddItemVariant
{
    public function __invoke(Item $parent, string $label, string $swatch, int $price): Item
    {
        if ($parent->variant_of !== null || $parent->slot !== ItemSlot::Furniture) {
            throw ModerationActionRefused::notVariantParent();
        }

        $slug = Str::slug("{$parent->slug} {$label}");

        if (Item::query()->where('slug', $slug)->exists()) {
            throw ModerationActionRefused::variantExists($slug);
        }

        return Item::query()->create([
            'slug' => $slug,
            'variant_of' => $parent->id,
            'variant_label' => $label,
            'swatch' => strtolower($swatch),
            'name' => $parent->name,
            'description' => $parent->description,
            'rarity' => $parent->rarity,
            'price' => $price,
            'in_shop' => false,
            'members_only' => $parent->members_only,
        ]);
    }
}

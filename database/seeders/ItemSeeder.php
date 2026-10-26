<?php

namespace Database\Seeders;

use App\Domain\Items\ItemRarity;
use App\Domain\Items\ItemSlot;
use App\Models\Item;
use Illuminate\Database\Seeder;

/**
 * The starter catalog. Safe to run again (including in production): items are
 * matched by slug, and existing items are left as staff last edited them.
 */
class ItemSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->items() as $item) {
            Item::query()->firstOrCreate(['slug' => $item['slug']], $item);
        }
    }

    /**
     * Furniture says how many tiles it covers (wide along x, deep along y), how
     * tall its placeholder block stands in pixels, and whether it lies flat on the floor like a rug.
     *
     * @return list<array{slug: string, name: string, description: string, slot: ItemSlot, rarity: ItemRarity, price: int, in_shop: bool, members_only?: bool, wide?: int, deep?: int, height?: int, floor?: bool}>
     */
    private function items(): array
    {
        return [
            ['slug' => 'wool-cap', 'name' => 'Wool Cap', 'description' => 'Warm. Knitted by someone who asked not to be named.', 'slot' => ItemSlot::Head, 'rarity' => ItemRarity::Common, 'price' => 20, 'in_shop' => true],
            ['slug' => 'round-spectacles', 'name' => 'Round Spectacles', 'description' => 'For seeing things more clearly than is strictly advisable.', 'slot' => ItemSlot::Face, 'rarity' => ItemRarity::Common, 'price' => 30, 'in_shop' => true],
            ['slug' => 'municipal-raincoat', 'name' => 'Municipal Raincoat', 'description' => 'Issued for weather that has not yet been scheduled.', 'slot' => ItemSlot::Body, 'rarity' => ItemRarity::Uncommon, 'price' => 60, 'in_shop' => true],
            ['slug' => 'quiet-boots', 'name' => 'Quiet Boots', 'description' => 'They make no sound at all. Nobody is sure how.', 'slot' => ItemSlot::Feet, 'rarity' => ItemRarity::Uncommon, 'price' => 45, 'in_shop' => true],
            ['slug' => 'brass-lantern', 'name' => 'Brass Lantern', 'description' => 'Always lit. Please do not check how.', 'slot' => ItemSlot::Hand, 'rarity' => ItemRarity::Rare, 'price' => 120, 'in_shop' => true],
            ['slug' => 'resident-pin', 'name' => 'Resident Pin', 'description' => 'Proof of residency. Wear it so the town remembers you.', 'slot' => ItemSlot::Pin, 'rarity' => ItemRarity::Common, 'price' => 10, 'in_shop' => true],
            ['slug' => 'lighthouse-backdrop', 'name' => 'Lighthouse at Dusk', 'description' => 'The lighthouse has been on this hill for longer than the hill.', 'slot' => ItemSlot::Background, 'rarity' => ItemRarity::Rare, 'price' => 150, 'in_shop' => true, 'members_only' => true],
            ['slug' => 'issued-armchair', 'name' => 'Issued Armchair', 'description' => 'Standard Hearthmore issue. Comfortable in a way that feels deliberate.', 'slot' => ItemSlot::Furniture, 'rarity' => ItemRarity::Common, 'price' => 25, 'in_shop' => true, 'wide' => 1, 'deep' => 1, 'height' => 28],
            ['slug' => 'small-table', 'name' => 'Small Table', 'description' => 'Exactly large enough for one cup and one unanswered letter.', 'slot' => ItemSlot::Furniture, 'rarity' => ItemRarity::Common, 'price' => 20, 'in_shop' => true, 'wide' => 1, 'deep' => 1, 'height' => 20],
            ['slug' => 'potted-fern', 'name' => 'Potted Fern', 'description' => 'It grows toward whoever is speaking.', 'slot' => ItemSlot::Furniture, 'rarity' => ItemRarity::Uncommon, 'price' => 35, 'in_shop' => true, 'wide' => 1, 'deep' => 1, 'height' => 34],
            ['slug' => 'tall-bookcase', 'name' => 'Tall Bookcase', 'description' => 'The books are all the same book. Nobody minds.', 'slot' => ItemSlot::Furniture, 'rarity' => ItemRarity::Uncommon, 'price' => 70, 'in_shop' => true, 'wide' => 2, 'deep' => 1, 'height' => 64],
            ['slug' => 'radio-cabinet', 'name' => 'Radio Cabinet', 'description' => 'Tuned permanently to Augustin Moss. There is no off switch.', 'slot' => ItemSlot::Furniture, 'rarity' => ItemRarity::Rare, 'price' => 140, 'in_shop' => true, 'wide' => 1, 'deep' => 1, 'height' => 36],
            ['slug' => 'hearthmore-love-seat', 'name' => 'Hearthmore Love Seat', 'description' => 'Seats two. The second seat is reserved, though nobody says for whom.', 'slot' => ItemSlot::Furniture, 'rarity' => ItemRarity::Uncommon, 'price' => 80, 'in_shop' => true, 'wide' => 2, 'deep' => 1, 'height' => 26],
            ['slug' => 'standard-bed', 'name' => 'Standard Bed', 'description' => 'Regulation length. You will fit. You will be made to fit.', 'slot' => ItemSlot::Furniture, 'rarity' => ItemRarity::Uncommon, 'price' => 90, 'in_shop' => true, 'wide' => 2, 'deep' => 2, 'height' => 22],
            ['slug' => 'faded-rug', 'name' => 'Faded Rug', 'description' => 'The pattern was brighter once. It is saving its strength.', 'slot' => ItemSlot::Furniture, 'rarity' => ItemRarity::Common, 'price' => 30, 'in_shop' => true, 'wide' => 2, 'deep' => 2, 'height' => 2, 'floor' => true],
            ['slug' => 'moth-wing-cloak', 'name' => 'Moth-Wing Cloak', 'description' => 'Not sold. Given, occasionally, to those the moths approve of.', 'slot' => ItemSlot::Body, 'rarity' => ItemRarity::Peculiar, 'price' => 0, 'in_shop' => false],
        ];
    }
}

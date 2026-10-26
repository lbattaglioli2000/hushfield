<?php

namespace Database\Factories;

use App\Domain\Items\ItemRarity;
use App\Domain\Items\ItemSlot;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'slug' => $slug,
            'name' => str($slug)->replace('-', ' ')->title()->toString(),
            'description' => null,
            'slot' => fake()->randomElement(ItemSlot::cases()),
            'rarity' => ItemRarity::Common,
            'price' => 50,
            'in_shop' => true,
            'members_only' => false,
        ];
    }

    public function furniture(): static
    {
        return $this->state(['slot' => ItemSlot::Furniture]);
    }

    public function notInShop(): static
    {
        return $this->state(['in_shop' => false]);
    }

    public function membersOnly(): static
    {
        return $this->state(['members_only' => true]);
    }
}

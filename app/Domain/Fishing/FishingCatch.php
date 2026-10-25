<?php

namespace App\Domain\Fishing;

use App\Domain\Items\ItemRarity;

/**
 * One thing that can come up on the line, from config('hushfield.fishing.catches').
 * Its name and description are player copy, in lang/en/hushfield.php "catches".
 */
final readonly class FishingCatch
{
    public function __construct(
        public string $key,
        public ItemRarity $rarity,
        public int $coins,
        public int $weight,
    ) {}

    public function name(): string
    {
        return (string) __("hushfield.catches.{$this->key}.name");
    }

    public function description(): string
    {
        return (string) __("hushfield.catches.{$this->key}.description");
    }

    /** Rare and peculiar catches are worth telling the room about. */
    public function isAnnounced(): bool
    {
        return in_array($this->rarity, [ItemRarity::Rare, ItemRarity::Peculiar], true);
    }

    /**
     * @return array{key: string, name: string, description: string, rarity: string, coins: int}
     */
    public function toPayload(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name(),
            'description' => $this->description(),
            'rarity' => $this->rarity->value,
            'coins' => $this->coins,
        ];
    }
}

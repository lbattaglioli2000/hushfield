<?php

namespace App\Domain\Fishing;

use App\Domain\Items\ItemRarity;
use LogicException;

/**
 * Everything the water can give, with its odds and its price.
 */
final class CatchTable
{
    /**
     * @return array<string, FishingCatch>
     */
    public function all(): array
    {
        $catches = [];

        /** @var array<string, array{weight: int, rarity: string, coins: int}> $table */
        $table = config('hushfield.fishing.catches');

        foreach ($table as $key => $row) {
            $catches[$key] = new FishingCatch($key, ItemRarity::from($row['rarity']), (int) $row['coins'], (int) $row['weight']);
        }

        return $catches;
    }

    public function find(string $key): ?FishingCatch
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Picks a catch at random, weighted by each catch's odds.
     */
    public function roll(): FishingCatch
    {
        $catches = $this->all();
        $pick = random_int(1, array_sum(array_map(fn (FishingCatch $catch): int => $catch->weight, $catches)));

        foreach ($catches as $catch) {
            $pick -= $catch->weight;

            if ($pick <= 0) {
                return $catch;
            }
        }

        return end($catches) ?: throw new LogicException('The fishing catch table is empty.');
    }
}

<?php

namespace App\Domain\Housing;

use App\Models\Item;

/**
 * The tiles a piece of furniture covers: $wide along x and $deep along y from
 * its back corner at ($x, $y), like a building. Mirrors footprintOf in
 * resources/js/game/shared/furniture.ts.
 */
final readonly class Footprint
{
    public function __construct(
        public int $x,
        public int $y,
        public int $wide,
        public int $deep,
    ) {}

    /**
     * An item standing at ($x, $y), turned $rotation quarter turns. A quarter
     * turn swaps how wide and how deep it is; the back corner stays put.
     */
    public static function of(Item $item, int $x, int $y, int $rotation): self
    {
        return $rotation % 2 === 0
            ? new self($x, $y, $item->wide, $item->deep)
            : new self($x, $y, $item->deep, $item->wide);
    }

    public function covers(int $x, int $y): bool
    {
        return $x >= $this->x && $x < $this->x + $this->wide
            && $y >= $this->y && $y < $this->y + $this->deep;
    }

    public function overlaps(self $other): bool
    {
        return $this->x < $other->x + $other->wide && $other->x < $this->x + $this->wide
            && $this->y < $other->y + $other->deep && $other->y < $this->y + $this->deep;
    }

    /**
     * @return list<array{int, int}>
     */
    public function tiles(): array
    {
        $tiles = [];

        for ($y = $this->y; $y < $this->y + $this->deep; $y++) {
            for ($x = $this->x; $x < $this->x + $this->wide; $x++) {
                $tiles[] = [$x, $y];
            }
        }

        return $tiles;
    }
}

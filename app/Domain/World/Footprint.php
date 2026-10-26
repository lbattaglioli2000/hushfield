<?php

namespace App\Domain\World;

/**
 * A block of tiles on a map: $wide along x and $deep along y from its back
 * corner at ($x, $y). Doors, fixtures, buildings and furniture each stand on
 * one, so "what covers this tile" is answered the same way for all of them.
 * Mirrors resources/js/game/shared/footprint.ts.
 */
readonly class Footprint
{
    public function __construct(
        public int $x,
        public int $y,
        public int $wide = 1,
        public int $deep = 1,
    ) {}

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
     * Steps from a (possibly fractional) position to the nearest covered tile,
     * diagonals counting as one: 0 on the footprint, 1 right beside it.
     */
    public function distanceTo(float $x, float $y): float
    {
        return max(
            max(0, $this->x - $x, $x - ($this->x + $this->wide - 1)),
            max(0, $this->y - $y, $y - ($this->y + $this->deep - 1)),
        );
    }

    /**
     * The same footprint turned $rotation quarter turns about its back corner,
     * which swaps how wide and how deep it is on every odd turn.
     */
    public function turned(int $rotation): self
    {
        return $rotation % 2 === 0 ? new self($this->x, $this->y, $this->wide, $this->deep) : new self($this->x, $this->y, $this->deep, $this->wide);
    }

    /**
     * The covered tiles, row by row.
     *
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

    /**
     * The middle tile (the later of the two middles along an even side).
     *
     * @return array{int, int}
     */
    public function centre(): array
    {
        return [$this->x + intdiv($this->wide, 2), $this->y + intdiv($this->deep, 2)];
    }
}

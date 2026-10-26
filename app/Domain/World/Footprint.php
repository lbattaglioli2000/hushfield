<?php

namespace App\Domain\World;

/**
 * A block of tiles on a map: $wide along x and $deep along y from its back
 * corner at ($x, $y). Doors, fixtures, buildings and furniture each stand on
 * one, so "what covers this tile" is answered the same way for all of them.
 *
 * A footprint may have a shape: a mask of the block, one row per tile along
 * y (the first row is the back row), one character per tile along x, '#' for
 * a covered tile and '.' for an empty one. An L-shaped sofa 2 × 2 is
 * ['##', '#.']. Empty tiles are not covered at all: they may be walked on and
 * built on. Without a mask the whole block is covered.
 * Mirrors resources/js/game/shared/footprint.ts.
 */
readonly class Footprint
{
    public const string FILLED = '#';

    public const string EMPTY = '.';

    /**
     * @param  list<string>|null  $mask  $deep rows of $wide characters; null covers the whole block.
     */
    public function __construct(
        public int $x,
        public int $y,
        public int $wide = 1,
        public int $deep = 1,
        public ?array $mask = null,
    ) {}

    public function covers(int $x, int $y): bool
    {
        return $x >= $this->x && $x < $this->x + $this->wide
            && $y >= $this->y && $y < $this->y + $this->deep
            && $this->fills($x - $this->x, $y - $this->y);
    }

    public function overlaps(self $other): bool
    {
        $boxes = $this->x < $other->x + $other->wide && $other->x < $this->x + $this->wide
            && $this->y < $other->y + $other->deep && $other->y < $this->y + $this->deep;

        if (! $boxes || ($this->mask === null && $other->mask === null)) {
            return $boxes;
        }

        return array_any($this->tiles(), fn (array $tile): bool => $other->covers(...$tile));
    }

    /**
     * Steps from a (possibly fractional) position to the nearest covered tile,
     * diagonals counting as one: 0 on the footprint, 1 right beside it.
     */
    public function distanceTo(float $x, float $y): float
    {
        if ($this->mask === null) {
            return max(
                max(0, $this->x - $x, $x - ($this->x + $this->wide - 1)),
                max(0, $this->y - $y, $y - ($this->y + $this->deep - 1)),
            );
        }

        // A shape covering nothing (which validation never lets through) is out of reach.
        return array_reduce(
            $this->tiles(),
            fn (float $nearest, array $tile): float => min($nearest, max(abs($tile[0] - $x), abs($tile[1] - $y))),
            INF,
        );
    }

    /**
     * The same footprint turned $rotation quarter turns clockwise seen from
     * above, about its back corner: the block swaps how wide and how deep it
     * is on every odd turn, and its mask turns with it.
     */
    public function turned(int $rotation): self
    {
        $turned = $this;

        for ($turn = 0; $turn < (($rotation % 4) + 4) % 4; $turn++) {
            $turned = $turned->quarterTurned();
        }

        return $turned;
    }

    /**
     * The covered tiles, row by row.
     *
     * @return list<array{int, int}>
     */
    public function tiles(): array
    {
        $tiles = [];

        for ($y = 0; $y < $this->deep; $y++) {
            for ($x = 0; $x < $this->wide; $x++) {
                if ($this->fills($x, $y)) {
                    $tiles[] = [$this->x + $x, $this->y + $y];
                }
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

    /**
     * What is wrong with a mask for a block $wide × $deep, or null if nothing:
     * it must be that size, cover at least one tile, leave no outer row or
     * column empty (the block would be bigger than the shape), and hold
     * together tile to tile, without diagonal-only joins.
     *
     * @param  list<string>  $mask
     */
    public static function maskProblem(array $mask, int $wide, int $deep): ?string
    {
        if (count($mask) !== $deep || array_any($mask, fn (string $row): bool => preg_match('/^[#.]{'.$wide.'}$/', $row) !== 1)) {
            return "The shape must be {$wide} tiles wide and {$deep} deep.";
        }

        $tiles = (new self(0, 0, $wide, $deep, $mask))->tiles();

        if ($tiles === []) {
            return 'The shape must cover at least one tile.';
        }

        $columns = array_unique(array_column($tiles, 0));
        $rows = array_unique(array_column($tiles, 1));

        if (! in_array(0, $columns, true) || ! in_array($wide - 1, $columns, true) || ! in_array(0, $rows, true) || ! in_array($deep - 1, $rows, true)) {
            return 'The shape leaves an outer row or column empty; make the piece smaller instead.';
        }

        $seen = [implode(',', $tiles[0]) => true];
        $queue = [$tiles[0]];

        while ($queue !== []) {
            [$x, $y] = array_pop($queue);

            foreach ([[0, -1], [1, 0], [0, 1], [-1, 0]] as [$dx, $dy]) {
                $key = ($x + $dx).','.($y + $dy);

                if (! isset($seen[$key]) && $x + $dx >= 0 && $x + $dx < $wide && $y + $dy >= 0 && $y + $dy < $deep && $mask[$y + $dy][$x + $dx] === self::FILLED) {
                    $seen[$key] = true;
                    $queue[] = [$x + $dx, $y + $dy];
                }
            }
        }

        return count($seen) === count($tiles) ? null : 'The shape must hold together: every tile must touch another along an edge.';
    }

    /** Whether the tile ($x, $y) from the back corner is covered. */
    private function fills(int $x, int $y): bool
    {
        return $this->mask === null || $this->mask[$y][$x] === self::FILLED;
    }

    /**
     * One quarter turn clockwise seen from above: what faced +y faces −x. The
     * tile ($x, $y) of the turned block was ($y, $deep − 1 − $x) before it.
     */
    private function quarterTurned(): self
    {
        $mask = null;

        if ($this->mask !== null) {
            $mask = [];

            for ($y = 0; $y < $this->wide; $y++) {
                $row = '';

                for ($x = 0; $x < $this->deep; $x++) {
                    $row .= $this->mask[$this->deep - 1 - $x][$y];
                }

                $mask[] = $row;
            }
        }

        return new self($this->x, $this->y, $this->deep, $this->wide, $mask);
    }
}

<?php

namespace App\Domain\World;

/**
 * A building standing on a map: a block of tiles nobody can walk onto except
 * its door, an exit along its front edge that leads to the room inside.
 *
 * The footprint runs $wide tiles along x and $deep along y from its back
 * corner at ($x, $y). The client draws it $height pixels tall, from the art
 * named by $name or as a placeholder block.
 */
final readonly class MapBuilding
{
    public function __construct(
        public string $name,
        public int $x,
        public int $y,
        public int $wide,
        public int $deep,
        public int $height,
        public MapExit $door,
    ) {}

    public function covers(int $x, int $y): bool
    {
        return $x >= $this->x && $x < $this->x + $this->wide
            && $y >= $this->y && $y < $this->y + $this->deep;
    }

    /**
     * The tile just outside the middle of the door, where players stand when they come back out.
     *
     * @return array{int, int}
     */
    public function doorstep(): array
    {
        [$x, $y] = $this->door->centre();

        // A door on the south-west face opens towards +y; on the south-east face, towards +x.
        return $this->door->deep === 1 && $this->door->y === $this->y + $this->deep - 1
            ? [$x, $y + 1]
            : [$x + 1, $y];
    }
}

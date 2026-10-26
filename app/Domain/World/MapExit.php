<?php

namespace App\Domain\World;

/**
 * A door on a map: walking onto any of its tiles takes the player to another
 * room, at the arrival point named by $arrive (or a random spawn).
 *
 * The door covers $wide tiles along x and $deep along y from ($x, $y); most
 * doors are one tile, wide ones a row or column of tiles.
 */
final readonly class MapExit
{
    public function __construct(
        public string $name,
        public int $x,
        public int $y,
        public string $to,
        public ?string $arrive,
        public int $wide = 1,
        public int $deep = 1,
    ) {}

    public function covers(int $x, int $y): bool
    {
        return $x >= $this->x && $x < $this->x + $this->wide
            && $y >= $this->y && $y < $this->y + $this->deep;
    }

    /**
     * The door's tiles, row by row.
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
     * The door's middle tile (the later of the two middles on an even width).
     *
     * @return array{int, int}
     */
    public function centre(): array
    {
        return [$this->x + intdiv($this->wide, 2), $this->y + intdiv($this->deep, 2)];
    }

    /**
     * @return array{name: string, x: int, y: int, wide: int, deep: int, to: string, arrive: string|null}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'x' => $this->x, 'y' => $this->y, 'wide' => $this->wide, 'deep' => $this->deep, 'to' => $this->to, 'arrive' => $this->arrive];
    }

    /**
     * Grids cached before doors had a size are one-tile doors.
     *
     * @param  array{name: string, x: int, y: int, wide?: int, deep?: int, to: string, arrive: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['x'], $data['y'], $data['to'], $data['arrive'], $data['wide'] ?? 1, $data['deep'] ?? 1);
    }
}

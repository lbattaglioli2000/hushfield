<?php

namespace App\Domain\World;

/**
 * A door on a map: walking onto any of its tiles takes the player to another
 * room, at the arrival point named by $arrive (or a random spawn).
 *
 * Most doors are one tile; wide ones are a row or column of tiles.
 */
final readonly class MapExit extends Footprint
{
    public function __construct(
        public string $name,
        int $x,
        int $y,
        public string $to,
        public ?string $arrive,
        int $wide = 1,
        int $deep = 1,
    ) {
        parent::__construct($x, $y, $wide, $deep);
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

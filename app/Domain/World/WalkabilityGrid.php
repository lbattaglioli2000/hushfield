<?php

namespace App\Domain\World;

use InvalidArgumentException;

/**
 * Immutable walkable/blocked grid for one version of one room map.
 */
final readonly class WalkabilityGrid
{
    /**
     * @param  string  $cells  Row-major string of "1" (walkable) and "0" (blocked), width × height long.
     * @param  list<array{int, int}>  $spawns
     */
    public function __construct(
        public int $width,
        public int $height,
        public string $cells,
        public array $spawns,
    ) {
        if (strlen($cells) !== $width * $height) {
            throw new InvalidArgumentException('Grid cell count does not match its dimensions.');
        }
    }

    public function isWalkable(int $x, int $y): bool
    {
        return $x >= 0 && $y >= 0 && $x < $this->width && $y < $this->height
            && $this->cells[$y * $this->width + $x] === '1';
    }

    /**
     * @return array{width: int, height: int, cells: string, spawns: list<array{int, int}>}
     */
    public function toArray(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'cells' => $this->cells,
            'spawns' => $this->spawns,
        ];
    }

    /**
     * @param  array{width: int, height: int, cells: string, spawns: list<array{int, int}>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['width'], $data['height'], $data['cells'], $data['spawns']);
    }
}

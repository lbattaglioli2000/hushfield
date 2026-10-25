<?php

namespace App\Domain\World;

use InvalidArgumentException;

/**
 * Immutable walkable/blocked grid for one version of one room map, with the
 * points on it the server needs: spawns, exits (doors) and arrival points.
 */
final readonly class WalkabilityGrid
{
    /**
     * @param  string  $cells  Row-major string of "1" (walkable) and "0" (blocked), width × height long.
     * @param  list<array{int, int}>  $spawns
     * @param  array<string, MapExit>  $exits  Keyed by exit name.
     * @param  array<string, array{int, int}>  $arrivals  Keyed by arrival point name.
     */
    public function __construct(
        public int $width,
        public int $height,
        public string $cells,
        public array $spawns,
        public array $exits = [],
        public array $arrivals = [],
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

    public function isExit(int $x, int $y): bool
    {
        foreach ($this->exits as $exit) {
            if ($exit->x === $x && $exit->y === $y) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where a player entering this map appears: the named arrival point, or a random spawn.
     *
     * @return array{int, int}
     */
    public function entryPoint(?string $arrival): array
    {
        return $this->arrivals[$arrival ?? ''] ?? $this->spawns[array_rand($this->spawns)];
    }

    /**
     * @return array{width: int, height: int, cells: string, spawns: list<array{int, int}>, exits: list<array{name: string, x: int, y: int, to: string, arrive: string|null}>, arrivals: array<string, array{int, int}>}
     */
    public function toArray(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'cells' => $this->cells,
            'spawns' => $this->spawns,
            'exits' => array_values(array_map(fn (MapExit $exit): array => $exit->toArray(), $this->exits)),
            'arrivals' => $this->arrivals,
        ];
    }

    /**
     * @param  array{width: int, height: int, cells: string, spawns: list<array{int, int}>, exits?: list<array{name: string, x: int, y: int, to: string, arrive: string|null}>, arrivals?: array<string, array{int, int}>}  $data
     */
    public static function fromArray(array $data): self
    {
        $exits = [];

        foreach ($data['exits'] ?? [] as $exit) {
            $exits[$exit['name']] = MapExit::fromArray($exit);
        }

        return new self($data['width'], $data['height'], $data['cells'], $data['spawns'], $exits, $data['arrivals'] ?? []);
    }
}

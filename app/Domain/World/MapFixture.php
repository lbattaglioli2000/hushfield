<?php

namespace App\Domain\World;

/**
 * Something on a map a player can use, such as a shop counter. The client
 * opens it when the player walks onto its tile or taps it.
 */
final readonly class MapFixture
{
    public function __construct(
        public string $name,
        public int $x,
        public int $y,
        public FixtureKind $kind,
    ) {}

    /**
     * @return array{name: string, x: int, y: int, kind: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'x' => $this->x, 'y' => $this->y, 'kind' => $this->kind->value];
    }

    /**
     * @param  array{name: string, x: int, y: int, kind: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['x'], $data['y'], FixtureKind::from($data['kind']));
    }
}

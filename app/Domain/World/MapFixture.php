<?php

namespace App\Domain\World;

/**
 * Something on a map a player can use, such as a shop counter: one tile. The
 * client uses it when the player walks onto it; what it is for, and how near
 * the server wants the player to be, comes from its kind.
 */
final readonly class MapFixture extends Footprint
{
    public function __construct(
        public string $name,
        int $x,
        int $y,
        public FixtureKind $kind,
    ) {
        parent::__construct($x, $y);
    }

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

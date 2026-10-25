<?php

namespace App\Domain\World;

/**
 * A parsed map file. Name, kind, capacity, the frequencies and the town map
 * position come from the map's custom properties in Tiled; null means the map
 * does not set them. Buildings are already walled off in the grid; the list
 * says where they stand.
 */
final readonly class ImportedMap
{
    /**
     * @param  array{int, int}|null  $townPosition  The room's grid cell on the town map.
     * @param  list<MapBuilding>  $buildings
     */
    public function __construct(
        public string $version,
        public WalkabilityGrid $grid,
        public ?string $name = null,
        public RoomKind $kind = RoomKind::Public,
        public ?int $capacity = null,
        public ?float $frequency = null,
        public ?float $homeFrequency = null,
        public ?array $townPosition = null,
        public array $buildings = [],
    ) {}
}

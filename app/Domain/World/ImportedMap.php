<?php

namespace App\Domain\World;

/**
 * A parsed map file. Name, kind and capacity come from the map's custom
 * properties in Tiled; null means the map does not set them.
 */
final readonly class ImportedMap
{
    public function __construct(
        public string $version,
        public WalkabilityGrid $grid,
        public ?string $name = null,
        public RoomKind $kind = RoomKind::Public,
        public ?int $capacity = null,
    ) {}
}

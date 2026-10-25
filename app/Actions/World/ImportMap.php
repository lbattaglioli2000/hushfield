<?php

namespace App\Actions\World;

use App\Domain\World\ImportedMap;
use App\Domain\World\MapFiles;
use App\Domain\World\TiledMapParser;
use App\Domain\World\WalkabilityGridRepository;
use App\Models\Room;

/**
 * Validates a committed Tiled export, caches its walkability grid, and points the room at the new map version.
 */
final readonly class ImportMap
{
    public function __construct(
        private MapFiles $files,
        private TiledMapParser $parser,
        private WalkabilityGridRepository $grids,
    ) {}

    public function __invoke(string $slug): ImportedMap
    {
        $map = $this->parser->parse($this->files->read($slug));
        $this->grids->store($slug, $map->version, $map->grid);

        $room = Room::query()->firstOrNew(['slug' => $slug], [
            'name' => str($slug)->replace('-', ' ')->title()->toString(),
            'capacity_per_instance' => (int) config('hushfield.world.capacity_per_instance'),
        ]);

        $room->fill([
            'map_version' => $map->version,
            'spawn_x' => $map->grid->spawns[0][0],
            'spawn_y' => $map->grid->spawns[0][1],
        ])->save();

        $room->instances()->firstOrCreate(['number' => 1], ['is_open' => true]);

        return $map;
    }
}

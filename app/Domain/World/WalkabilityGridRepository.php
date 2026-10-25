<?php

namespace App\Domain\World;

use App\Models\Room;
use Illuminate\Redis\RedisManager;

/**
 * Serves walkability grids from worker memory, then Redis, then the map file.
 *
 * Registered as a singleton. The in-memory cache is safe under Octane because
 * grids are immutable and keyed by room slug and map version.
 */
final class WalkabilityGridRepository
{
    /** @var array<string, WalkabilityGrid> */
    private array $memory = [];

    public function __construct(
        private readonly RedisManager $redis,
        private readonly MapFiles $files,
        private readonly TiledMapParser $parser,
    ) {}

    public function forRoom(Room $room): WalkabilityGrid
    {
        if ($room->map_version === null) {
            throw new InvalidMapException("Room \"{$room->slug}\" has no imported map. Run hushfield:import-map {$room->slug}.");
        }

        $key = self::key($room->slug, $room->map_version);

        return $this->memory[$key] ??= $this->fromRedis($key) ?? $this->rebuild($room, $key);
    }

    public function store(string $slug, string $version, WalkabilityGrid $grid): void
    {
        $this->redis->connection('world')->set(self::key($slug, $version), json_encode($grid->toArray(), JSON_THROW_ON_ERROR));
    }

    public static function key(string $slug, string $version): string
    {
        return "walkable:{$slug}:{$version}";
    }

    private function fromRedis(string $key): ?WalkabilityGrid
    {
        $cached = $this->redis->connection('world')->get($key);

        if (! is_string($cached)) {
            return null;
        }

        /** @var array{width: int, height: int, cells: string, spawns: list<array{int, int}>} $data */
        $data = json_decode($cached, true, flags: JSON_THROW_ON_ERROR);

        return WalkabilityGrid::fromArray($data);
    }

    private function rebuild(Room $room, string $key): WalkabilityGrid
    {
        $map = $this->parser->parse($this->files->read($room->slug));

        if ($map->version !== $room->map_version) {
            throw new InvalidMapException("The map file for \"{$room->slug}\" changed since it was imported. Run hushfield:import-map {$room->slug}.");
        }

        $this->store($room->slug, $map->version, $map->grid);

        return $map->grid;
    }
}

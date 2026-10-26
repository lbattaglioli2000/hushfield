<?php

use App\Domain\World\ImportedMap;
use App\Domain\World\InvalidMapException;
use App\Domain\World\MapFiles;
use App\Domain\World\Pathfinder;
use App\Domain\World\RoomKind;
use App\Domain\World\TiledMapParser;
use App\Domain\World\WalkabilityGridRepository;
use App\Models\Room;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;

test('importing a map creates the room, its first instance, and caches the grid', function () {
    $this->artisan('hushfield:import-map', ['slug' => 'test-square'])
        ->expectsOutputToContain('Imported test-square at version')
        ->assertSuccessful();

    $room = Room::query()->where('slug', 'test-square')->sole();
    $expectedVersion = substr(hash('sha256', (string) file_get_contents(base_path('tests/fixtures/maps/test-square.json'))), 0, 16);

    expect($room->map_version)->toBe($expectedVersion)
        ->and($room->name)->toBe('Test Square')
        ->and([$room->spawn_x, $room->spawn_y])->toBe([1, 1])
        ->and($room->capacity_per_instance)->toBe(40)
        ->and($room->instances()->pluck('number')->all())->toBe([1])
        ->and(Redis::connection('world')->exists(WalkabilityGridRepository::key('test-square', $expectedVersion)))->toBe(1);
});

test('re-importing keeps the room and its instances', function () {
    $room = importRoom();
    $room->update(['name' => 'The Square', 'capacity_per_instance' => 12]);

    importRoom();

    expect(Room::query()->count())->toBe(1)
        ->and($room->fresh()->name)->toBe('The Square')
        ->and($room->fresh()->capacity_per_instance)->toBe(12)
        ->and($room->instances()->count())->toBe(1);
});

test('invalid maps fail the import and change nothing', function (string $slug) {
    $this->artisan('hushfield:import-map', ['slug' => $slug])->assertFailed();

    expect(Room::query()->count())->toBe(0);
})->with(['spawn-blocked', 'base64-walkable', 'does-not-exist', '../maps/test-square']);

test('grids are served from Redis, then rebuilt from the file when Redis loses them', function () {
    $room = importRoom();
    Redis::connection('world')->flushdb();
    $this->app->forgetInstance(WalkabilityGridRepository::class);

    $grid = app(WalkabilityGridRepository::class)->forRoom($room);

    expect($grid->isWalkable(1, 1))->toBeTrue()
        ->and($grid->isWalkable(7, 3))->toBeFalse()
        ->and(Redis::connection('world')->exists(WalkabilityGridRepository::key('test-square', $room->map_version)))->toBe(1);
});

test('a map file changed since import is refused until it is re-imported', function () {
    $dir = storage_path('framework/testing/maps-'.uniqid());
    File::ensureDirectoryExists($dir);
    File::copy(base_path('tests/fixtures/maps/test-square.json'), "{$dir}/test-square.json");
    config(['hushfield.maps.path' => $dir]);

    try {
        $room = importRoom();
        Redis::connection('world')->flushdb();
        $this->app->forgetInstance(WalkabilityGridRepository::class);
        File::append("{$dir}/test-square.json", "\n");

        expect(fn () => app(WalkabilityGridRepository::class)->forRoom($room))
            ->toThrow(InvalidMapException::class, 'changed since it was imported');
    } finally {
        File::deleteDirectory($dir);
    }
});

test('rooms without an imported map cannot be joined', function () {
    $room = Room::factory()->create();

    expect(fn () => app(WalkabilityGridRepository::class)->forRoom($room))
        ->toThrow(InvalidMapException::class, 'has no imported map');
});

test('every committed map imports with its own place on the town map, every door is wide, leads to a real room, lands in front of the door back and can be walked to across its width, and every building lets players back out of its front door', function () {
    config(['hushfield.maps.path' => resource_path('maps')]);
    $this->artisan('hushfield:import-map')->assertSuccessful();

    $files = app(MapFiles::class);
    /** @var array<string, ImportedMap> $maps */
    $maps = collect($files->slugs())->mapWithKeys(fn (string $slug) => [$slug => (new TiledMapParser)->parse($files->read($slug))])->all();
    $maxSteps = (int) config('hushfield.movement.max_path_steps');

    expect(Room::query()->pluck('slug')->sort()->values()->all())->toBe(collect(array_keys($maps))->sort()->values()->all())
        ->and(Room::query()->where('slug', 'hearthmore-residences')->sole()->only('frequency', 'home_frequency'))->toBe(['frequency' => 104.8, 'home_frequency' => 88.3]);

    // A yard is reached through its house's back door, and plays on the house's station.
    $places = collect($maps)->reject(fn (ImportedMap $map): bool => $map->kind === RoomKind::Yard);
    $cells = $places->map(fn (ImportedMap $map): ?string => $map->townPosition === null ? null : implode(',', $map->townPosition));

    expect($cells->filter()->unique()->count())->toBe($places->count(), 'every room but a yard has its own square on the town map')
        ->and($maps['hearthmore-yard']->homeFrequency)->toBe(88.3)
        ->and(Room::query()->where('slug', 'whsh')->sole()->only('town_x', 'town_y'))->toBe(['town_x' => 5, 'town_y' => 3]);

    foreach ($maps as $slug => $map) {
        expect($map->frequency ?? ($map->kind === RoomKind::Yard ? $map->homeFrequency : null))->not->toBeNull("{$slug} has no frequency on the dial");

        foreach ($map->grid->exits as $exit) {
            $door = "{$slug} → {$exit->name}";

            expect(isset($maps[$exit->to]))->toBeTrue("{$door} leads to a room with no map")
                ->and(isset($maps[$exit->to]->grid->arrivals[(string) $exit->arrive]))->toBeTrue("{$door} lands on no arrival");

            // Doors are wide enough to walk into with the keys without lining up first:
            // three tiles between outdoor rooms. A building has a single doorway, one tile in and out.
            $there = $maps[$exit->to];
            $wayBack = collect($there->grid->exits)->first(fn ($back) => $back->to === $slug);
            $intoBuilding = collect([...$map->buildings, ...$there->buildings])->contains(fn ($building) => $building->door === $exit || $building->door === $wayBack);

            $span = max($exit->wide, $exit->deep);

            expect(min($exit->wide, $exit->deep))->toBe(1, "{$door} is a row or column of tiles")
                ->and($span === ($intoBuilding ? 1 : 3))->toBeTrue("{$door} is {$span} wide");

            // The player lands just in front of the middle of the door back, clear of it.
            [$cx, $cy] = $wayBack?->centre() ?? [-9, -9];
            [$ax, $ay] = $there->grid->arrivals[(string) $exit->arrive];

            expect(abs($ax - $cx) + abs($ay - $cy))->toBe(1, "{$door} does not land in front of the door back");

            foreach ($map->grid->spawns as $spawn) {
                foreach ($exit->tiles() as $tile) {
                    expect(app(Pathfinder::class)->find($map->grid, $spawn, $tile, $maxSteps))
                        ->not->toBeNull("{$door} at ({$tile[0]}, {$tile[1]}) cannot be walked to from the spawn at ({$spawn[0]}, {$spawn[1]})");
                }
            }
        }

        foreach ($map->buildings as $building) {
            $inside = $maps[$building->door->to]->grid;
            $wayOut = collect($inside->exits)->filter(fn ($exit) => $exit->to === $slug)->map(fn ($exit) => $map->grid->arrivals[(string) $exit->arrive] ?? null)->values()->all();

            expect($wayOut)->toBe([$building->doorstep()], "{$slug} → {$building->name}: the way out does not land just outside the front door");
        }
    }
});

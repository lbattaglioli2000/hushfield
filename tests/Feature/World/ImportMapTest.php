<?php

use App\Domain\World\InvalidMapException;
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

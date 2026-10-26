<?php

use App\Domain\World\FixtureKind;
use App\Domain\World\InvalidMapException;
use App\Domain\World\RoomKind;
use App\Domain\World\TiledMapParser;
use App\Domain\World\WalkabilityGrid;

function fixtureMap(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 2)."/fixtures/maps/{$name}.json");
}

it('builds the walkability grid and spawns from a valid map', function () {
    $map = (new TiledMapParser)->parse(fixtureMap('test-square'));

    expect($map->grid->width)->toBe(10)
        ->and($map->grid->height)->toBe(6)
        // Spawn object at (48, 48) px with 32 px tiles is tile (1, 1).
        ->and($map->grid->spawns)->toBe([[1, 1]])
        // (0, 0) uses a horizontally flipped tile; the flip flag must not make it blocked.
        ->and($map->grid->isWalkable(0, 0))->toBeTrue()
        ->and($map->grid->isWalkable(9, 0))->toBeTrue()
        ->and($map->grid->isWalkable(7, 3))->toBeFalse()
        ->and($map->grid->isWalkable(8, 4))->toBeTrue()
        ->and($map->grid->isWalkable(9, 5))->toBeFalse()
        ->and($map->grid->isWalkable(10, 0))->toBeFalse();
});

it('rejects maps without the required layers or with invalid spawns', function (string $fixture, string $message) {
    expect(fn () => (new TiledMapParser)->parse(fixtureMap($fixture)))
        ->toThrow(InvalidMapException::class, $message);
})->with([
    'missing walkable layer' => ['no-walkable', 'The map needs a tilelayer named "walkable".'],
    'missing spawns layer' => ['no-spawns', 'The map needs a objectgroup named "spawns".'],
    'spawn on a blocked tile' => ['spawn-blocked', 'Spawn at tile (1, 1) is not walkable.'],
    'base64 layer encoding' => ['base64-walkable', 'must use CSV layer format, not base64'],
]);

it('rejects maps that are not fixed-size isometric JSON', function (callable $mutate, string $message) {
    $map = json_decode(fixtureMap('test-square'), true);

    expect(fn () => (new TiledMapParser)->parse(is_string($result = $mutate($map)) ? $result : json_encode($result)))
        ->toThrow(InvalidMapException::class, $message);
})->with([
    'not JSON' => [fn () => '{"layers": [', 'not valid JSON'],
    'orthogonal' => [fn (array $map) => [...$map, 'orientation' => 'orthogonal'], 'isometric'],
    'infinite' => [fn (array $map) => [...$map, 'infinite' => true], 'Infinite'],
    'tile count mismatch' => [fn (array $map) => [...$map, 'width' => 11], 'one tile per map cell'],
]);

it('versions a map by its content', function () {
    $parser = new TiledMapParser;
    $original = fixtureMap('test-square');

    $edited = json_decode($original, true);
    $edited['layers'][1]['data'][5] = 0;
    $edited = json_encode($edited, JSON_PRETTY_PRINT);

    $version = $parser->parse($original)->version;

    expect($parser->parse($original)->version)->toBe($version)
        ->and($parser->parse($edited)->version)->not->toBe($version)
        // Even a change that leaves the grid identical is a new file, so a new version.
        ->and($parser->parse($original."\n")->version)->not->toBe($version)
        ->and($version)->not->toBeEmpty();
});

it('reads doors, arrival points and room properties', function () {
    $map = (new TiledMapParser)->parse(fixtureMap('test-home'));

    expect($map->name)->toBe('Test Residences')
        ->and($map->kind)->toBe(RoomKind::Residence)
        ->and($map->capacity)->toBe(3)
        ->and($map->townPosition)->toBe([1, 1])
        ->and($map->grid->exits['door']->toArray())->toBe(['name' => 'door', 'x' => 2, 'y' => 4, 'wide' => 1, 'deep' => 1, 'to' => 'test-lane', 'arrive' => 'home'])
        ->and($map->grid->arrivals)->toBe(['door' => [2, 3]])
        ->and($map->grid->entryPoint('door'))->toBe([2, 3])
        ->and($map->grid->entryPoint('cellar'))->toBe([2, 2])
        ->and((new TiledMapParser)->parse(fixtureMap('test-square'))->kind)->toBe(RoomKind::Public)
        // A map without town_x and town_y is off the town map's grid, not refused.
        ->and((new TiledMapParser)->parse(fixtureMap('test-square'))->townPosition)->toBeNull();
});

it('rejects doors and arrival points that would trap or bounce players', function (callable $mutate, string $message) {
    $map = json_decode(fixtureMap('test-home'), true);
    $layers = collect($map['layers'])->keyBy('name')->all();
    $mutate($layers);
    $map['layers'] = array_values($layers);

    expect(fn () => (new TiledMapParser)->parse(json_encode($map)))
        ->toThrow(InvalidMapException::class, $message);
})->with([
    'door without a target' => [function (array &$layers) {
        $layers['exits']['objects'][0]['properties'] = [];
    }, 'Exit "door" needs a "to" property'],
    'arrival on the door' => [function (array &$layers) {
        $layers['arrivals']['objects'][0]['y'] = 4 * 32 + 16;
    }, 'Arrival "door" is on an exit'],
    'spawn on the door' => [function (array &$layers) {
        $layers['spawns']['objects'][0]['y'] = 4 * 32 + 16;
    }, 'Spawn at tile (2, 4) is on an exit.'],
    'two doors with one name' => [function (array &$layers) {
        $layers['exits']['objects'][] = $layers['exits']['objects'][0];
    }, 'Two exits are named "door".'],
    'arrival on the far end of a wide door' => [function (array &$layers) {
        $layers['exits']['objects'][0] = [...$layers['exits']['objects'][0], 'x' => 0, 'y' => 3 * 32, 'width' => 3 * 32, 'height' => 32, 'point' => false];
    }, 'Arrival "door" is on an exit'],
    'wide door off whole tiles' => [function (array &$layers) {
        $layers['exits']['objects'][0] = [...$layers['exits']['objects'][0], 'x' => 1 * 32 + 16, 'y' => 4 * 32, 'width' => 3 * 32, 'height' => 32, 'point' => false];
    }, 'Exit "door" must be a point or a rectangle snapped to whole tiles.'],
    'wide door running off the map' => [function (array &$layers) {
        $layers['exits']['objects'][0] = [...$layers['exits']['objects'][0], 'x' => 3 * 32, 'y' => 4 * 32, 'width' => 3 * 32, 'height' => 32, 'point' => false];
    }, 'Exit "door" at tile (5, 4) is not walkable.'],
]);

it('reads a wide door drawn as one rectangle: every tile is the door, and it walks through as one', function () {
    $map = json_decode(fixtureMap('test-home'), true);
    $layers = collect($map['layers'])->keyBy('name')->all();
    // Tiled rectangles on isometric maps are in tile-height pixels: three tiles along x from (1, 4).
    $layers['exits']['objects'][0] = [...$layers['exits']['objects'][0], 'x' => 32.0, 'y' => 128.0, 'width' => 96.0, 'height' => 32.0];
    unset($layers['exits']['objects'][0]['point']);
    $map['layers'] = array_values($layers);

    $grid = (new TiledMapParser)->parse(json_encode($map))->grid;
    $door = $grid->exits['door'];

    expect($grid->exits)->toHaveCount(1)
        ->and($door->toArray())->toBe(['name' => 'door', 'x' => 1, 'y' => 4, 'wide' => 3, 'deep' => 1, 'to' => 'test-lane', 'arrive' => 'home'])
        ->and($door->tiles())->toBe([[1, 4], [2, 4], [3, 4]])
        ->and($door->centre())->toBe([2, 4])
        ->and([$grid->isExit(1, 4), $grid->isExit(2, 4), $grid->isExit(3, 4)])->each->toBeTrue()
        ->and([$grid->isExit(0, 4), $grid->isExit(4, 4), $grid->isExit(2, 3)])->each->toBeFalse()
        // The cached grid keeps the door's size.
        ->and(WalkabilityGrid::fromArray($grid->toArray())->exits['door']->tiles())->toBe($door->tiles());
});

it('reads where the room and its homes sit on the dial', function () {
    $map = json_decode(fixtureMap('test-home'), true);
    $map['properties'][] = ['name' => 'frequency', 'type' => 'float', 'value' => 104.8];
    $map['properties'][] = ['name' => 'home_frequency', 'type' => 'float', 'value' => 88.3];
    $parsed = (new TiledMapParser)->parse(json_encode($map));

    expect($parsed->frequency)->toBe(104.8)
        ->and($parsed->homeFrequency)->toBe(88.3)
        ->and((new TiledMapParser)->parse(fixtureMap('test-square'))->frequency)->toBeNull();
});

it('reads fixtures players can use, and rejects ones it does not understand', function () {
    $map = (new TiledMapParser)->parse(fixtureMap('test-market'));

    expect($map->grid->fixtures['counter']->toArray())->toBe(['name' => 'counter', 'x' => 3, 'y' => 1, 'kind' => 'shop'])
        ->and($map->grid->hasFixture(FixtureKind::Shop))->toBeTrue()
        ->and((new TiledMapParser)->parse(fixtureMap('test-square'))->grid->hasFixture(FixtureKind::Shop))->toBeFalse();

    $bad = json_decode(fixtureMap('test-market'), true);
    $bad['layers'][3]['objects'][0]['properties'][0]['value'] = 'bakery';

    expect(fn () => (new TiledMapParser)->parse(json_encode($bad)))
        ->toThrow(InvalidMapException::class, 'Fixture "counter" needs a "kind" property: shop, fishing.');
});

it('rejects room properties it does not understand', function (array $property, string $message) {
    $map = json_decode(fixtureMap('test-home'), true);
    $map['properties'] = [$property];

    expect(fn () => (new TiledMapParser)->parse(json_encode($map)))
        ->toThrow(InvalidMapException::class, $message);
})->with([
    'unknown kind' => [['name' => 'kind', 'type' => 'string', 'value' => 'castle'], '"kind" must be "public" or "residence"'],
    'zero capacity' => [['name' => 'capacity', 'type' => 'int', 'value' => 0], '"capacity" must be a positive integer'],
    'frequency below the band' => [['name' => 'frequency', 'type' => 'float', 'value' => 87.4], '"frequency" must be an FM frequency'],
    'frequency above the band' => [['name' => 'frequency', 'type' => 'float', 'value' => 108.6], '"frequency" must be an FM frequency'],
    'frequency between channels' => [['name' => 'frequency', 'type' => 'float', 'value' => 94.25], '"frequency" must be an FM frequency'],
    'frequency as text' => [['name' => 'home_frequency', 'type' => 'string', 'value' => '88.3'], '"home_frequency" must be an FM frequency'],
    'town_x without town_y' => [['name' => 'town_x', 'type' => 'int', 'value' => 2], '"town_x" and "town_y" must both be whole numbers'],
    'negative town_y' => [['name' => 'town_y', 'type' => 'int', 'value' => -1], '"town_x" and "town_y" must both be whole numbers'],
]);

it('walls off a building except for its door, which stays an exit', function () {
    $map = (new TiledMapParser)->parse(fixtureMap('test-street'));
    [$shed] = $map->buildings;
    $walkable = fn (int $x, int $y): bool => $map->grid->isWalkable($x, $y);

    expect([$shed->name, $shed->x, $shed->y, $shed->wide, $shed->deep, $shed->height, $shed->door->name])->toBe(['shed', 2, 0, 4, 3, 64, 'shed'])
        // The shed covers x 2–5, y 0–2: its corners are walls, the tiles just outside it are not.
        ->and([$walkable(2, 0), $walkable(5, 0), $walkable(2, 2), $walkable(5, 2), $walkable(4, 1)])->each->toBeFalse()
        ->and([$walkable(1, 0), $walkable(6, 0), $walkable(2, 3), $walkable(6, 2)])->each->toBeTrue()
        ->and($walkable(3, 2))->toBeTrue()
        ->and($map->grid->isExit(3, 2))->toBeTrue()
        ->and($shed->doorstep())->toBe([3, 3]);
});

it('opens every tile of a wide building door, and brings players out in front of its middle', function () {
    $map = json_decode(fixtureMap('test-street'), true);
    $layers = collect($map['layers'])->keyBy('name')->all();
    // Two tiles along the shed's front row, (3, 2) and (4, 2).
    $layers['exits']['objects'][0] = [...$layers['exits']['objects'][0], 'x' => 96.0, 'y' => 64.0, 'width' => 64.0, 'height' => 32.0];
    unset($layers['exits']['objects'][0]['point']);
    $map['layers'] = array_values($layers);

    $parsed = (new TiledMapParser)->parse(json_encode($map));
    [$shed] = $parsed->buildings;

    expect([$parsed->grid->isWalkable(3, 2), $parsed->grid->isWalkable(4, 2)])->each->toBeTrue()
        ->and([$parsed->grid->isWalkable(2, 2), $parsed->grid->isWalkable(5, 2), $parsed->grid->isWalkable(4, 1)])->each->toBeFalse()
        ->and($shed->doorstep())->toBe([4, 3]);
});

it('rejects buildings that run off the map, stand on places players need, or hide their door', function (callable $mutate, string $message) {
    $map = json_decode(fixtureMap('test-street'), true);
    $layers = collect($map['layers'])->keyBy('name')->all();
    $shed = &$layers['buildings']['objects'][0];
    $mutate($shed, $layers);
    $map['layers'] = array_values($layers);

    expect(fn () => (new TiledMapParser)->parse(json_encode($map)))
        ->toThrow(InvalidMapException::class, $message);
})->with([
    'off the right edge' => [function (array &$shed) {
        $shed['x'] = 5 * 32;
    }, 'Building "shed" runs off the map.'],
    'off the top edge' => [function (array &$shed) {
        $shed['y'] = -32;
    }, 'Building "shed" runs off the map.'],
    'over an arrival' => [function (array &$shed) {
        $shed['height'] = 4 * 32;
    }, 'Building "shed" stands on arrival "shed" at tile (3, 3).'],
    'over another exit' => [function (array &$shed, array &$layers) {
        $layers['exits']['objects'][] = ['id' => 9, 'name' => 'side', 'x' => 2 * 32 + 16, 'y' => 16, 'point' => true, 'properties' => [['name' => 'to', 'type' => 'string', 'value' => 'test-lane']]];
    }, 'Building "shed" stands on exit "side" at tile (2, 0).'],
    'door on the back wall' => [function (array &$shed, array &$layers) {
        $layers['exits']['objects'][0]['y'] = 16;
        $layers['arrivals']['objects'][0]['y'] = 3 * 32 + 16;
    }, 'door "shed" must be on its front edge'],
    'wide door turning the corner onto the back wall' => [function (array &$shed, array &$layers) {
        $layers['exits']['objects'][0] = [...$layers['exits']['objects'][0], 'x' => 4 * 32, 'y' => 32, 'width' => 64, 'height' => 64, 'point' => false];
    }, 'door "shed" must be on its front edge'],
    'wide door running past the building' => [function (array &$shed, array &$layers) {
        $layers['exits']['objects'][0] = [...$layers['exits']['objects'][0], 'x' => 5 * 32, 'y' => 64, 'width' => 64, 'height' => 32, 'point' => false];
    }, 'door "shed" must be on its front edge'],
    'door not on the building' => [function (array &$shed) {
        $shed['height'] = 2 * 32;
    }, 'door "shed" must be on its front edge'],
    'no door' => [function (array &$shed) {
        $shed['properties'] = [['name' => 'height', 'type' => 'int', 'value' => 64]];
    }, 'Building "shed" needs a "door" property'],
    'not on whole tiles' => [function (array &$shed) {
        $shed['width'] = 100;
    }, 'Building "shed" must be a rectangle snapped to whole tiles.'],
    'overlapping another building' => [function (array &$shed, array &$layers) {
        $layers['buildings']['objects'][] = [...$shed, 'id' => 9, 'name' => 'annex', 'x' => 0.0, 'width' => 96.0];
    }, 'Buildings "shed" and "annex" overlap.'],
]);

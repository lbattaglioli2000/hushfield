<?php

use App\Domain\World\InvalidMapException;
use App\Domain\World\RoomKind;
use App\Domain\World\TiledMapParser;

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
        ->and($map->grid->exits['door']->toArray())->toBe(['name' => 'door', 'x' => 2, 'y' => 4, 'to' => 'test-lane', 'arrive' => 'home'])
        ->and($map->grid->arrivals)->toBe(['door' => [2, 3]])
        ->and($map->grid->entryPoint('door'))->toBe([2, 3])
        ->and($map->grid->entryPoint('cellar'))->toBe([2, 2])
        ->and((new TiledMapParser)->parse(fixtureMap('test-square'))->kind)->toBe(RoomKind::Public);
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
]);

it('reads where the room and its homes sit on the dial', function () {
    $map = json_decode(fixtureMap('test-home'), true);
    $map['properties'][] = ['name' => 'frequency', 'type' => 'float', 'value' => 104.8];
    $map['properties'][] = ['name' => 'home_frequency', 'type' => 'float', 'value' => 88.3];
    $parsed = (new TiledMapParser)->parse(json_encode($map));

    expect($parsed->frequency)->toBe(104.8)
        ->and($parsed->homeFrequency)->toBe(88.3)
        ->and((new TiledMapParser)->parse(fixtureMap('test-square'))->frequency)->toBeNull();
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
]);

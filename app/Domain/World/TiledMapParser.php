<?php

namespace App\Domain\World;

use JsonException;

/**
 * Reads a Tiled isometric JSON export and builds the walkability grid.
 *
 * Requirements: an uncompressed tile layer named "walkable" (any non-zero
 * tile is walkable) and an object layer named "spawns" with at least one
 * object on a walkable tile.
 *
 * Optional: an object layer named "exits" whose points are doors (custom
 * properties "to", the target room slug, and "arrive", an arrival point name
 * in the target map), an object layer named "arrivals" of named points where
 * players come in through a door, an object layer named "fixtures" of named
 * points players can use (custom property "kind": "shop" for a shop
 * counter, "fishing" for a fishing spot), an object layer named "buildings" of
 * tile-aligned rectangles (see resources/maps/README.md), and map properties "name", "kind"
 * (public or residence), "capacity", "frequency" (the room's place on the
 * radio's tuning dial, in MHz), "home_frequency" (where a residence room's
 * homes sit on the dial) and "town_x" and "town_y" (the room's square on the
 * town map, in whole grid cells; a room without them is off the map).
 */
final class TiledMapParser
{
    /** Tiled stores flip and rotation flags in the top bits of each GID. */
    private const int GID_MASK = 0x0FFFFFFF;

    /** The FM band the tuning dial shows, in MHz. */
    private const float BAND_LOW = 87.5;

    private const float BAND_HIGH = 108.5;

    public function parse(string $contents): ImportedMap
    {
        try {
            /** @var mixed $map */
            $map = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidMapException("The map is not valid JSON: {$e->getMessage()}");
        }

        if (! is_array($map)) {
            throw new InvalidMapException('The map must be a JSON object.');
        }

        if (($map['orientation'] ?? null) !== 'isometric') {
            throw new InvalidMapException('The map must use isometric orientation.');
        }

        if (($map['infinite'] ?? false) === true) {
            throw new InvalidMapException('Infinite maps are not supported. Use a fixed-size map.');
        }

        $width = $this->positiveInt($map, 'width');
        $height = $this->positiveInt($map, 'height');
        $tileHeight = $this->positiveInt($map, 'tileheight');

        $layers = is_array($map['layers'] ?? null) ? $map['layers'] : [];

        $cells = $this->walkableCells($this->layer($layers, 'walkable', 'tilelayer'), $width, $height);
        $grid = new WalkabilityGrid($width, $height, $cells, []);
        $spawns = $this->spawns($this->layer($layers, 'spawns', 'objectgroup'), $tileHeight, $grid);
        $exits = $this->exits($this->optionalLayer($layers, 'exits'), $tileHeight, $grid);
        $grid = new WalkabilityGrid($width, $height, $cells, $spawns, $exits);
        $arrivals = $this->arrivals($this->optionalLayer($layers, 'arrivals'), $tileHeight, $grid);
        $fixtures = $this->fixtures($this->optionalLayer($layers, 'fixtures'), $tileHeight, $grid);

        foreach ($spawns as [$x, $y]) {
            if ($grid->isExit($x, $y)) {
                throw new InvalidMapException("Spawn at tile ({$x}, {$y}) is on an exit.");
            }
        }

        $buildings = $this->buildings($this->optionalLayer($layers, 'buildings'), $tileHeight, new WalkabilityGrid($width, $height, $cells, $spawns, $exits, $arrivals, $fixtures));

        // Walls block walking; only the door lets players onto a building's footprint.
        foreach ($buildings as $building) {
            for ($y = $building->y; $y < $building->y + $building->deep; $y++) {
                for ($x = $building->x; $x < $building->x + $building->wide; $x++) {
                    if ($x !== $building->door->x || $y !== $building->door->y) {
                        $cells[$y * $width + $x] = '0';
                    }
                }
            }
        }

        $properties = $this->properties($map);
        $kind = RoomKind::tryFrom((string) ($properties['kind'] ?? RoomKind::Public->value))
            ?? throw new InvalidMapException('The map property "kind" must be "public" or "residence".');
        $capacity = $properties['capacity'] ?? null;

        if ($capacity !== null && (! is_int($capacity) || $capacity < 1)) {
            throw new InvalidMapException('The map property "capacity" must be a positive integer.');
        }

        return new ImportedMap(
            version: substr(hash('sha256', $contents), 0, 16),
            grid: new WalkabilityGrid($width, $height, $cells, $spawns, $exits, $arrivals, $fixtures),
            name: is_string($properties['name'] ?? null) ? $properties['name'] : null,
            kind: $kind,
            capacity: $capacity,
            frequency: $this->frequency($properties, 'frequency'),
            homeFrequency: $this->frequency($properties, 'home_frequency'),
            townPosition: $this->townPosition($properties),
            buildings: array_values($buildings),
        );
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array{int, int}|null
     */
    private function townPosition(array $properties): ?array
    {
        $x = $properties['town_x'] ?? null;
        $y = $properties['town_y'] ?? null;

        if ($x === null && $y === null) {
            return null;
        }

        if (! is_int($x) || ! is_int($y) || $x < 0 || $y < 0) {
            throw new InvalidMapException('The map properties "town_x" and "town_y" must both be whole numbers, 0 or more.');
        }

        return [$x, $y];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function frequency(array $properties, string $name): ?float
    {
        $value = $properties[$name] ?? null;

        if ($value === null) {
            return null;
        }

        $valid = (is_int($value) || is_float($value))
            && $value >= self::BAND_LOW && $value <= self::BAND_HIGH
            && abs(round($value, 1) - $value) < 1e-9;

        if (! $valid) {
            throw new InvalidMapException("The map property \"{$name}\" must be an FM frequency in MHz, such as 94.2.");
        }

        return (float) $value;
    }

    /**
     * @param  array<mixed>  $layers
     * @return array<mixed>
     */
    private function optionalLayer(array $layers, string $name): array
    {
        return $this->findLayer($layers, $name, 'objectgroup') ?? [];
    }

    /**
     * @param  array<mixed>  $layer
     * @return array<string, MapExit>
     */
    private function exits(array $layer, int $tileHeight, WalkabilityGrid $grid): array
    {
        $exits = [];

        foreach ($this->namedPoints($layer, 'exit', $tileHeight, $grid) as $name => [$tile, $properties]) {
            $to = $properties['to'] ?? null;

            if (! is_string($to) || ! preg_match('/^[a-z0-9-]+$/', $to)) {
                throw new InvalidMapException("Exit \"{$name}\" needs a \"to\" property naming a room slug.");
            }

            $arrive = $properties['arrive'] ?? null;
            $exits[$name] = new MapExit($name, $tile[0], $tile[1], $to, is_string($arrive) && $arrive !== '' ? $arrive : null);
        }

        return $exits;
    }

    /**
     * @param  array<mixed>  $layer
     * @return array<string, array{int, int}>
     */
    private function arrivals(array $layer, int $tileHeight, WalkabilityGrid $grid): array
    {
        $arrivals = [];

        foreach ($this->namedPoints($layer, 'arrival', $tileHeight, $grid) as $name => [$tile]) {
            if ($grid->isExit(...$tile)) {
                throw new InvalidMapException("Arrival \"{$name}\" is on an exit; players would leave as soon as they arrived.");
            }

            $arrivals[$name] = $tile;
        }

        return $arrivals;
    }

    /**
     * @param  array<mixed>  $layer
     * @return array<string, MapFixture>
     */
    private function fixtures(array $layer, int $tileHeight, WalkabilityGrid $grid): array
    {
        $fixtures = [];

        foreach ($this->namedPoints($layer, 'fixture', $tileHeight, $grid) as $name => [$tile, $properties]) {
            $kind = FixtureKind::tryFrom(is_string($properties['kind'] ?? null) ? $properties['kind'] : '')
                ?? throw new InvalidMapException("Fixture \"{$name}\" needs a \"kind\" property: ".implode(', ', array_column(FixtureKind::cases(), 'value')).'.');

            if ($grid->isExit(...$tile)) {
                throw new InvalidMapException("Fixture \"{$name}\" is on an exit.");
            }

            $fixtures[$name] = new MapFixture($name, $tile[0], $tile[1], $kind);
        }

        return $fixtures;
    }

    /**
     * @param  array<mixed>  $layer
     * @return array<string, MapBuilding>
     */
    private function buildings(array $layer, int $tileHeight, WalkabilityGrid $grid): array
    {
        $objects = is_array($layer['objects'] ?? null) ? $layer['objects'] : [];
        $buildings = [];

        foreach ($objects as $object) {
            $name = is_array($object) ? ($object['name'] ?? null) : null;

            if (! is_array($object) || ! is_string($name) || ! preg_match('/^[a-z0-9-]+$/', $name)) {
                throw new InvalidMapException('Every building needs a name: a slug such as "library", which also names its art.');
            }

            if (isset($buildings[$name])) {
                throw new InvalidMapException("Two buildings are named \"{$name}\".");
            }

            // Rectangles on isometric maps are measured in tile-height pixels on both axes, like points.
            $box = [];

            foreach (['x', 'y', 'width', 'height'] as $key) {
                $value = $object[$key] ?? null;

                if (! is_numeric($value) || fmod((float) $value, $tileHeight) !== 0.0 || ($object['point'] ?? false) === true || isset($object['polygon']) || isset($object['ellipse'])) {
                    throw new InvalidMapException("Building \"{$name}\" must be a rectangle snapped to whole tiles.");
                }

                $box[$key] = intdiv((int) $value, $tileHeight);
            }

            if ($box['width'] < 1 || $box['height'] < 1 || $box['x'] < 0 || $box['y'] < 0
                || $grid->width < $box['x'] + $box['width'] || $grid->height < $box['y'] + $box['height']) {
                throw new InvalidMapException("Building \"{$name}\" runs off the map.");
            }

            $properties = $this->properties($object);
            $height = $properties['height'] ?? null;

            if (! is_int($height) || $height < 1) {
                throw new InvalidMapException("Building \"{$name}\" needs a \"height\" property: how tall it stands, in pixels.");
            }

            $door = $grid->exits[is_string($properties['door'] ?? null) ? $properties['door'] : ''] ?? null;

            if ($door === null) {
                throw new InvalidMapException("Building \"{$name}\" needs a \"door\" property naming one of the map's exits.");
            }

            $building = new MapBuilding($name, $box['x'], $box['y'], $box['width'], $box['height'], $height, $door);
            $this->ensureClear($building, $grid, $buildings);

            // Only the two faces nearest the viewer are drawn, so a door anywhere else could not be seen.
            if (! $building->covers($door->x, $door->y)
                || ($door->x !== $building->x + $building->wide - 1 && $door->y !== $building->y + $building->deep - 1)) {
                throw new InvalidMapException("Building \"{$name}\"'s door \"{$door->name}\" must be on its front edge, the last row or column of its footprint.");
            }

            $buildings[$name] = $building;
        }

        return $buildings;
    }

    /**
     * A footprint may not cover anything players need to stand on, nor another building.
     *
     * @param  array<string, MapBuilding>  $others
     */
    private function ensureClear(MapBuilding $building, WalkabilityGrid $grid, array $others): void
    {
        $points = [
            ...array_map(fn (array $tile): array => ['a spawn', ...$tile], $grid->spawns),
            ...array_map(fn (MapExit $exit): array => ["exit \"{$exit->name}\"", $exit->x, $exit->y], array_filter($grid->exits, fn (MapExit $exit): bool => $exit !== $building->door)),
            ...array_map(fn (string $name, array $tile): array => ["arrival \"{$name}\"", ...$tile], array_keys($grid->arrivals), $grid->arrivals),
            ...array_map(fn (MapFixture $fixture): array => ["fixture \"{$fixture->name}\"", $fixture->x, $fixture->y], $grid->fixtures),
        ];

        foreach ($points as [$what, $x, $y]) {
            if ($building->covers($x, $y)) {
                throw new InvalidMapException("Building \"{$building->name}\" stands on {$what} at tile ({$x}, {$y}).");
            }
        }

        foreach ($others as $other) {
            $overlaps = $building->x < $other->x + $other->wide && $other->x < $building->x + $building->wide
                && $building->y < $other->y + $other->deep && $other->y < $building->y + $building->deep;

            if ($overlaps) {
                throw new InvalidMapException("Buildings \"{$other->name}\" and \"{$building->name}\" overlap.");
            }
        }
    }

    /**
     * Points with unique names, each on a walkable tile.
     *
     * @param  array<mixed>  $layer
     * @return array<string, array{array{int, int}, array<string, mixed>}>
     */
    private function namedPoints(array $layer, string $what, int $tileHeight, WalkabilityGrid $grid): array
    {
        $objects = is_array($layer['objects'] ?? null) ? $layer['objects'] : [];
        $points = [];

        foreach ($objects as $object) {
            $name = is_array($object) ? ($object['name'] ?? null) : null;

            if (! is_array($object) || ! is_string($name) || $name === '' || ! is_numeric($object['x'] ?? null) || ! is_numeric($object['y'] ?? null)) {
                throw new InvalidMapException("Every {$what} needs a name and an x and y position.");
            }

            if (isset($points[$name])) {
                throw new InvalidMapException("Two {$what}s are named \"{$name}\".");
            }

            $tile = [(int) floor($object['x'] / $tileHeight), (int) floor($object['y'] / $tileHeight)];

            if (! $grid->isWalkable(...$tile)) {
                throw new InvalidMapException(ucfirst($what)." \"{$name}\" at tile ({$tile[0]}, {$tile[1]}) is not walkable.");
            }

            $points[$name] = [$tile, $this->properties($object)];
        }

        return $points;
    }

    /**
     * Tiled custom properties as name => value.
     *
     * @param  array<mixed>  $element
     * @return array<string, mixed>
     */
    private function properties(array $element): array
    {
        $properties = [];

        foreach (is_array($element['properties'] ?? null) ? $element['properties'] : [] as $property) {
            if (is_array($property) && is_string($property['name'] ?? null)) {
                $properties[$property['name']] = $property['value'] ?? null;
            }
        }

        return $properties;
    }

    /**
     * @param  array<mixed>  $layers
     * @return array<mixed>
     */
    private function layer(array $layers, string $name, string $type): array
    {
        return $this->findLayer($layers, $name, $type)
            ?? throw new InvalidMapException("The map needs a {$type} named \"{$name}\".");
    }

    /**
     * Finds a layer by name, looking inside group layers too.
     *
     * @param  array<mixed>  $layers
     * @return array<mixed>|null
     */
    private function findLayer(array $layers, string $name, string $type): ?array
    {
        foreach ($layers as $layer) {
            if (! is_array($layer)) {
                continue;
            }

            if (($layer['name'] ?? null) === $name) {
                if (($layer['type'] ?? null) !== $type) {
                    throw new InvalidMapException("The \"{$name}\" layer must be a {$type}.");
                }

                return $layer;
            }

            if (($layer['type'] ?? null) === 'group' && is_array($layer['layers'] ?? null)) {
                $found = $this->findLayer($layer['layers'], $name, $type);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $layer
     */
    private function walkableCells(array $layer, int $width, int $height): string
    {
        if (isset($layer['encoding']) && $layer['encoding'] !== 'csv') {
            throw new InvalidMapException('The "walkable" layer must use CSV layer format, not base64.');
        }

        $data = $layer['data'] ?? null;

        if (! is_array($data) || count($data) !== $width * $height) {
            throw new InvalidMapException('The "walkable" layer must have one tile per map cell.');
        }

        $cells = '';

        foreach ($data as $gid) {
            if (! is_int($gid)) {
                throw new InvalidMapException('The "walkable" layer contains a non-numeric tile.');
            }

            $cells .= ($gid & self::GID_MASK) !== 0 ? '1' : '0';
        }

        if (! str_contains($cells, '1')) {
            throw new InvalidMapException('The "walkable" layer has no walkable tiles.');
        }

        return $cells;
    }

    /**
     * Tiled positions objects on isometric maps in tile-height pixel units on both axes.
     *
     * @param  array<mixed>  $layer
     * @return list<array{int, int}>
     */
    private function spawns(array $layer, int $tileHeight, WalkabilityGrid $grid): array
    {
        $objects = is_array($layer['objects'] ?? null) ? $layer['objects'] : [];
        $spawns = [];

        foreach ($objects as $object) {
            if (! is_array($object) || ! is_numeric($object['x'] ?? null) || ! is_numeric($object['y'] ?? null)) {
                throw new InvalidMapException('Every spawn object needs an x and y position.');
            }

            $tile = [(int) floor($object['x'] / $tileHeight), (int) floor($object['y'] / $tileHeight)];

            if (! $grid->isWalkable(...$tile)) {
                throw new InvalidMapException("Spawn at tile ({$tile[0]}, {$tile[1]}) is not walkable.");
            }

            $spawns[] = $tile;
        }

        if ($spawns === []) {
            throw new InvalidMapException('The "spawns" layer needs at least one spawn point.');
        }

        return $spawns;
    }

    /**
     * @param  array<mixed>  $map
     */
    private function positiveInt(array $map, string $key): int
    {
        $value = $map[$key] ?? null;

        if (! is_int($value) || $value < 1) {
            throw new InvalidMapException("The map needs a positive integer \"{$key}\".");
        }

        return $value;
    }
}

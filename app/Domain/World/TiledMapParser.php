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
 * players come in through a door, and map properties "name", "kind"
 * (public or residence) and "capacity".
 */
final class TiledMapParser
{
    /** Tiled stores flip and rotation flags in the top bits of each GID. */
    private const int GID_MASK = 0x0FFFFFFF;

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

        foreach ($spawns as [$x, $y]) {
            if ($grid->isExit($x, $y)) {
                throw new InvalidMapException("Spawn at tile ({$x}, {$y}) is on an exit.");
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
            grid: new WalkabilityGrid($width, $height, $cells, $spawns, $exits, $arrivals),
            name: is_string($properties['name'] ?? null) ? $properties['name'] : null,
            kind: $kind,
            capacity: $capacity,
        );
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

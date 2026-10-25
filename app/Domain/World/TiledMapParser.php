<?php

namespace App\Domain\World;

use JsonException;

/**
 * Reads a Tiled isometric JSON export and builds the walkability grid.
 *
 * Requirements: an uncompressed tile layer named "walkable" (any non-zero
 * tile is walkable) and an object layer named "spawns" with at least one
 * object on a walkable tile.
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

        /** @var list<array<string, mixed>> $layers */
        $layers = is_array($map['layers'] ?? null) ? $map['layers'] : [];

        $cells = $this->walkableCells($this->layer($layers, 'walkable', 'tilelayer'), $width, $height);
        $grid = new WalkabilityGrid($width, $height, $cells, []);
        $spawns = $this->spawns($this->layer($layers, 'spawns', 'objectgroup'), $tileHeight, $grid);

        return new ImportedMap(
            version: substr(hash('sha256', $contents), 0, 16),
            grid: new WalkabilityGrid($width, $height, $cells, $spawns),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $layers
     * @return array<string, mixed>
     */
    private function layer(array $layers, string $name, string $type): array
    {
        foreach ($layers as $layer) {
            if (($layer['name'] ?? null) === $name) {
                if (($layer['type'] ?? null) !== $type) {
                    throw new InvalidMapException("The \"{$name}\" layer must be a {$type}.");
                }

                return $layer;
            }

            if (($layer['type'] ?? null) === 'group' && is_array($layer['layers'] ?? null)) {
                try {
                    return $this->layer($layer['layers'], $name, $type);
                } catch (InvalidMapException) {
                    continue;
                }
            }
        }

        throw new InvalidMapException("The map needs a {$type} named \"{$name}\".");
    }

    /**
     * @param  array<string, mixed>  $layer
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
     * @param  array<string, mixed>  $layer
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

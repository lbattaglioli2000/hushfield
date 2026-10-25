<?php

namespace App\Domain\World;

/**
 * Locates committed Tiled exports under resources/maps.
 */
final class MapFiles
{
    public function pathFor(string $slug): string
    {
        return rtrim((string) config('hushfield.maps.path'), '/')."/{$slug}.json";
    }

    public function read(string $slug): string
    {
        $path = $this->pathFor($slug);

        if (! preg_match('/^[a-z0-9-]+$/', $slug) || ! is_file($path)) {
            throw new InvalidMapException("No map file exists for \"{$slug}\".");
        }

        return (string) file_get_contents($path);
    }
}

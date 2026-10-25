<?php

namespace App\Console\Commands;

use App\Actions\World\ImportMap;
use App\Domain\World\InvalidMapException;
use App\Domain\World\MapFiles;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hushfield:import-map {slug? : The map file name under resources/maps, without .json. Omit to import every map}')]
#[Description('Validate Tiled map exports, cache their walkability grids, and point their rooms at the new versions')]
final class ImportMapCommand extends Command
{
    public function handle(ImportMap $importMap, MapFiles $files): int
    {
        $slug = $this->argument('slug');
        $failed = false;

        foreach (is_string($slug) ? [$slug] : $files->slugs() as $map) {
            try {
                $imported = $importMap($map);
            } catch (InvalidMapException $e) {
                $this->error("{$map}: {$e->getMessage()}");
                $failed = true;

                continue;
            }

            $grid = $imported->grid;
            $this->info("Imported {$map} at version {$imported->version} ({$grid->width}×{$grid->height}, ".count($grid->spawns).' spawns, '.count($grid->exits).' exits).');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

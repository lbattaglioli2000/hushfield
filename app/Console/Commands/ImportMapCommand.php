<?php

namespace App\Console\Commands;

use App\Actions\World\ImportMap;
use App\Domain\World\InvalidMapException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hushfield:import-map {slug : The map file name under resources/maps, without .json}')]
#[Description('Validate a Tiled map export, cache its walkability grid, and point the room at the new version')]
final class ImportMapCommand extends Command
{
    public function handle(ImportMap $importMap): int
    {
        try {
            $map = $importMap((string) $this->argument('slug'));
        } catch (InvalidMapException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Imported {$this->argument('slug')} at version {$map->version} ({$map->grid->width}×{$map->grid->height}, ".count($map->grid->spawns).' spawns).');

        return self::SUCCESS;
    }
}

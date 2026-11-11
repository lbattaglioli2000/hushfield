<?php

namespace App\Console\Commands;

use App\Actions\World\PackMaps;
use App\Domain\World\InvalidMapException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** The admin's "Pack maps for Tiled" (Live rooms) does the same on the queue. */
#[Signature('hushfield:pack-maps')]
#[Description('Zip the Tiled maps with the pictures Tiled shows onto the private disk, and print a link to send the designer')]
final class PackMapsCommand extends Command
{
    public function handle(PackMaps $packMaps): int
    {
        try {
            $pack = $packMaps();
        } catch (InvalidMapException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Packed {$pack['files']} files into {$pack['path']} on the private disk.");
        $this->line($pack['url'] !== null
            ? 'Download it for the next '.PackMaps::LINK_HOURS." hours: {$pack['url']}"
            : "It's at ".Storage::disk('private')->path($pack['path']).'.');

        return self::SUCCESS;
    }
}

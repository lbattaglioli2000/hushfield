<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * The maps as the designer opens them in Tiled: every map with the pictures
 * it shows beside it (ground.png and props/), which the build writes and git
 * ignores. See docs/art/maps-in-tiled.md.
 */
#[Signature('hushfield:pack-maps')]
#[Description('Zip the Tiled maps with the pictures Tiled shows onto the private disk, and print a link to send the designer')]
final class PackMapsCommand extends Command
{
    /** How long the download link works, in hours. */
    private const int LINK_HOURS = 24;

    public function handle(): int
    {
        $maps = rtrim((string) config('hushfield.maps.path'), '/');

        if (! is_file("{$maps}/ground.png")) {
            $this->error('The maps have no pictures for Tiled beside them yet. Run php artisan hushfield:build-assets first.');

            return self::FAILURE;
        }

        $files = [...glob("{$maps}/*.json") ?: [], "{$maps}/ground.png", ...glob("{$maps}/props/*.png") ?: []];
        $zipPath = (string) tempnam(sys_get_temp_dir(), 'maps');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);

        foreach ($files as $file) {
            // One folder, laid out as resources/maps is, so each map finds its pictures.
            $zip->addFile($file, 'hushfield-maps/'.substr($file, strlen($maps) + 1));
        }

        $zip->close();

        $disk = Storage::disk('private');
        $path = $disk->putFileAs('tiled', $zipPath, 'hushfield-maps-'.now()->format('Y-m-d-His').'.zip');
        unlink($zipPath);

        if ($path === false) {
            $this->error("The zip couldn't be saved to the private disk.");

            return self::FAILURE;
        }

        $this->info('Packed '.count($files)." files into {$path} on the private disk.");
        $this->line($disk->providesTemporaryUrls()
            ? 'Download it for the next '.self::LINK_HOURS.' hours: '.$disk->temporaryUrl($path, now()->addHours(self::LINK_HOURS))
            : "It's at {$disk->path($path)}.");

        return self::SUCCESS;
    }
}

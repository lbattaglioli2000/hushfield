<?php

namespace App\Actions\World;

use App\Domain\World\InvalidMapException;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * The maps as the designer opens them in Tiled: every map with the pictures
 * it shows beside it (ground.png and props/), which the build writes and git
 * ignores, zipped onto the private disk. See docs/art/maps-in-tiled.md.
 */
final class PackMaps
{
    /** How long the download link works, in hours. */
    public const int LINK_HOURS = 24;

    /**
     * @return array{path: string, files: int, url: string|null} url is null on a disk without temporary links (a local folder).
     *
     * @throws InvalidMapException when the build hasn't written the pictures yet
     */
    public function __invoke(): array
    {
        $maps = rtrim((string) config('hushfield.maps.path'), '/');

        if (! is_file("{$maps}/ground.png")) {
            throw new InvalidMapException('The maps have no pictures for Tiled beside them yet. Run php artisan hushfield:build-assets first.');
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
            throw new RuntimeException("The zip couldn't be saved to the private disk.");
        }

        return [
            'path' => $path,
            'files' => count($files),
            'url' => $disk->providesTemporaryUrls() ? $disk->temporaryUrl($path, now()->addHours(self::LINK_HOURS)) : null,
        ];
    }
}

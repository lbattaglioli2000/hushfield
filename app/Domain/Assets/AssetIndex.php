<?php

namespace App\Domain\Assets;

use Illuminate\Support\Facades\Log;

/**
 * The built assets the app is running with: bootstrap/cache/assets.json,
 * written by hushfield:build-assets. Every build and deploy runs that
 * command, but if a release ever starts without its index, or without the
 * files it names, this builds them on the spot rather than serve no art.
 */
final class AssetIndex
{
    public function __construct(private readonly AssetBuilder $builder) {}

    /** @return array{version: string, files: array<string, string>} */
    public function current(): array
    {
        $path = (string) config('hushfield.assets.index');
        $index = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (is_array($index) && is_string($index['version'] ?? null) && is_array($index['files'] ?? null)
            && is_string($index['files']['tiles'] ?? null) && is_file($this->path($index['files']['tiles']))) {
            /** @var array{version: string, files: array<string, string>} $index */
            return $index;
        }

        Log::warning('No built assets: building them now. hushfield:build-assets should run on every build.');

        return $this->builder->build(maps: false);
    }

    /** Where a built file lies on disk, by its name in the index. */
    public function path(string $file): string
    {
        return config('hushfield.assets.output').'/'.$file;
    }
}

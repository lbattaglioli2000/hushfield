<?php

namespace App\Domain\Assets;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Copies built art to the public bucket (the "public" disk, under assets/),
 * where browsers fetch it from once HUSHFIELD_ASSET_URL points there. A
 * publish uploads only files the bucket doesn't have and never deletes, so a
 * tab still running an older build keeps finding its files; prune() clears
 * what no build has named for a while. Until the running build is
 * published, the manifest keeps pointing at the app (see published()).
 */
final readonly class AssetPublisher
{
    /** Where in the bucket built files go, and each build's index. */
    public const string PREFIX = 'assets';

    public function __construct(private AssetIndex $assets) {}

    /** @return array{uploaded: int, skipped: int, version: string} */
    public function publish(): array
    {
        $index = $this->assets->current();
        $disk = $this->disk();
        $uploaded = 0;

        foreach ($index['files'] as $file) {
            $key = self::PREFIX."/{$file}";

            if ($disk->exists($key)) {
                continue;
            }

            $disk->put($key, (string) file_get_contents($this->assets->path($file)), [
                'CacheControl' => 'public, max-age=31536000, immutable',
                'ContentType' => 'image/png',
            ]);
            $uploaded++;
        }

        // The index last: once it is there, every file it names is too.
        $disk->put(self::PREFIX."/index/{$index['version']}.json", (string) json_encode($index), [
            'CacheControl' => 'no-cache',
            'ContentType' => 'application/json',
        ]);
        Cache::forever($this->publishedKey($index['version']), true);

        return ['uploaded' => $uploaded, 'skipped' => count($index['files']) - $uploaded, 'version' => $index['version']];
    }

    /** Where a build's files are fetched from: the bucket once it is published there, the app until then. */
    public function baseUrl(string $version): string
    {
        $bucket = (string) config('hushfield.assets.base_url');

        return $bucket !== '' && $this->published($version)
            ? rtrim($bucket, '/').'/'.self::PREFIX
            : asset('assets');
    }

    /** Whether this build's files are all in the bucket. */
    public function published(string $version): bool
    {
        return Cache::get($this->publishedKey($version)) === true;
    }

    /**
     * Deletes files no build published within `keep_days` names, once they are
     * that old themselves, and those builds' indexes. The running build's
     * files always stay.
     *
     * @return list<string> the keys deleted
     */
    public function prune(): array
    {
        $disk = $this->disk();
        $cutoff = now()->subDays((int) config('hushfield.assets.keep_days'))->getTimestamp();
        $keep = array_values($this->assets->current()['files']);
        $old = [];

        foreach ($disk->files(self::PREFIX.'/index') as $key) {
            if ($disk->lastModified($key) >= $cutoff) {
                $index = json_decode((string) $disk->get($key), true);
                $keep = [...$keep, ...array_values(is_array($index) && is_array($index['files'] ?? null) ? $index['files'] : [])];
            } else {
                $old[] = $key;
            }
        }

        $keep = array_flip(array_map(fn (mixed $file): string => self::PREFIX.'/'.(is_string($file) ? $file : ''), $keep));
        $deleted = [];

        foreach ($disk->allFiles(self::PREFIX) as $key) {
            if (str_starts_with($key, self::PREFIX.'/index/') ? in_array($key, $old, true) : (! isset($keep[$key]) && $disk->lastModified($key) < $cutoff)) {
                $deleted[] = $key;
            }
        }

        $disk->delete($deleted);

        return $deleted;
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('hushfield.assets.disk'));
    }

    private function publishedKey(string $version): string
    {
        return "hushfield:assets:published:{$version}";
    }
}

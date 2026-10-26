<?php

namespace App\Http\Controllers\World;

use App\Domain\Assets\AssetIndex;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every game asset URL comes from here, read from the index
 * hushfield:build-assets writes. Each file's name carries its content hash,
 * so this is the one response that must be revalidated: no-cache, with an
 * ETag. Building, wall and furniture art is listed by slug and part (see
 * resources/maps/README.md, "Art"); anything without art is drawn as a
 * placeholder.
 */
final class AssetManifestController extends Controller
{
    public function __invoke(Request $request, AssetIndex $assets): JsonResponse
    {
        $index = $assets->current();
        $base = rtrim((string) (config('hushfield.assets.base_url') ?: asset('assets')), '/');

        /** @var array<string, string> $images */
        $images = config('hushfield.assets.images');

        $buildings = [];
        $walls = [];
        $items = [];

        foreach ($index['files'] as $name => $file) {
            $url = "{$base}/{$file}";
            $parts = explode('/', $name);

            match ($parts[0]) {
                'buildings' => $buildings[$parts[1]] = $url,
                'walls' => $walls[$parts[1]][$parts[2]] = $url,
                'items' => $items[$parts[1]][$parts[2]] = $url,
                default => null,
            };
        }

        $response = response()->json([
            'version' => $index['version'],
            'images' => array_map(fn (string $name): string => "{$base}/{$index['files'][$name]}", $images),
            'buildings' => (object) $buildings,
            'walls' => (object) array_map(fn (array $parts): object => (object) $parts, $walls),
            'items' => (object) array_map(fn (array $parts): object => (object) $parts, $items),
        ])->header('Cache-Control', 'no-cache');

        $response->setEtag(hash('xxh128', (string) $response->getContent()));
        $response->isNotModified($request);

        return $response;
    }
}

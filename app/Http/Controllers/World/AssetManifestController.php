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
 * ETag. Building, furniture and wall art arrives with step 3 of
 * docs/assets-plan.md; until then everything is drawn as a placeholder.
 */
final class AssetManifestController extends Controller
{
    public function __invoke(Request $request, AssetIndex $assets): JsonResponse
    {
        $index = $assets->current();
        $base = rtrim((string) (config('hushfield.assets.base_url') ?: asset('assets')), '/');

        /** @var array<string, string> $images */
        $images = config('hushfield.assets.images');

        $response = response()->json([
            'version' => $index['version'],
            'images' => array_map(fn (string $name): string => "{$base}/{$index['files'][$name]}", $images),
            'buildings' => (object) [],
            'furniture' => (object) [],
            'walls' => (object) [],
        ])->header('Cache-Control', 'no-cache');

        $response->setEtag(hash('xxh128', (string) $response->getContent()));
        $response->isNotModified($request);

        return $response;
    }
}

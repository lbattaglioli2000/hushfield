<?php

namespace App\Http\Controllers\World;

use App\Domain\Assets\AssetIndex;
use App\Domain\Assets\AssetPublisher;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every game asset URL comes from here, read from the index
 * hushfield:build-assets writes. Each file's name carries its content hash,
 * so this is the one response that must be revalidated: no-cache, with an
 * ETag. Building and wall art is listed by slug and part, and building
 * animations by building and part, with where they lie on it (see
 * resources/maps/README.md, "Art"); furniture art comes with each item
 * (Item::toPayload), as it may be uploaded. Anything without art is drawn
 * as a placeholder. The avatar rig comes with the base body and hair
 * (docs/assets-plan.md §14).
 */
final class AssetManifestController extends Controller
{
    public function __invoke(Request $request, AssetIndex $assets, AssetPublisher $publisher): JsonResponse
    {
        $index = $assets->current();
        $base = $publisher->baseUrl($index['version']);

        /** @var array<string, string> $images */
        $images = config('hushfield.assets.images');

        $buildings = [];
        $animations = [];
        $walls = [];
        $avatar = ['base' => [], 'hair' => []];

        foreach ($index['files'] as $name => $file) {
            $url = "{$base}/{$file}";
            $parts = explode('/', $name);

            match (true) {
                $parts[0] === 'buildings' && count($parts) === 2 => $buildings[$parts[1]] = $url,
                $parts[0] === 'buildings' => $animations[$parts[1]][$parts[2]] = ['url' => $url, ...($index['animations'][$name] ?? [])],
                $parts[0] === 'walls' => $walls[$parts[1]][$parts[2]] = $url,
                $parts[0] === 'avatars' && $parts[1] === 'base' => $avatar['base'][$parts[2]] = $url,
                $parts[0] === 'avatars' && $parts[1] === 'hair' => $avatar['hair'][$parts[2]][$parts[3]] = $url,
                default => null,
            };
        }

        $response = response()->json([
            'version' => $index['version'],
            'images' => array_map(fn (string $name): string => "{$base}/{$index['files'][$name]}", $images),
            'buildings' => (object) $buildings,
            'animations' => (object) array_map(fn (array $parts): object => (object) $parts, $animations),
            'walls' => (object) array_map(fn (array $parts): object => (object) $parts, $walls),
            // The rig, and the base body and hair styles by facing; wearables' art comes with each resident.
            'avatar' => isset($index['avatar']) ? [...$index['avatar'], 'base' => (object) $avatar['base'], 'hair' => (object) $avatar['hair']] : null,
        ])->header('Cache-Control', 'no-cache');

        $response->setEtag(hash('xxh128', (string) $response->getContent()));
        $response->isNotModified($request);

        return $response;
    }
}

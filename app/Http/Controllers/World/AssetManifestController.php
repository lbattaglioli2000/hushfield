<?php

namespace App\Http\Controllers\World;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Every game asset URL comes from here, so assets can move to a CDN by changing HUSHFIELD_ASSET_URL.
 * The images themselves are listed in config/hushfield.php.
 */
final class AssetManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $version = (string) config('hushfield.assets.version');
        $base = rtrim((string) (config('hushfield.assets.base_url') ?: asset('assets')), '/')."/v{$version}";

        /** @var array<string, string> $images */
        $images = config('hushfield.assets.images');

        return response()->json([
            'version' => $version,
            'images' => array_map(fn (string $file): string => "{$base}/{$file}", $images),
        ])->header('Cache-Control', 'no-cache');
    }
}

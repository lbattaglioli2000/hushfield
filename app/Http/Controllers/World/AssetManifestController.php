<?php

namespace App\Http\Controllers\World;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Every game asset URL comes from here, so assets can move to a CDN by changing HUSHFIELD_ASSET_URL.
 */
final class AssetManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $version = (string) config('hushfield.assets.version');
        $base = rtrim((string) (config('hushfield.assets.base_url') ?: asset('assets')), '/')."/v{$version}";

        return response()->json([
            'version' => $version,
            'images' => [
                'tiles' => "{$base}/tiles.png",
                'resident' => "{$base}/resident.png",
                'marker' => "{$base}/marker.png",
                'hover' => "{$base}/hover.png",
            ],
        ])->header('Cache-Control', 'no-cache');
    }
}

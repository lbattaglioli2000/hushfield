<?php

namespace App\Http\Controllers\World;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Every game asset URL comes from here, so assets can move to a CDN by changing HUSHFIELD_ASSET_URL.
 * The images themselves are listed in config/hushfield.php. Building art is
 * whatever PNGs are under buildings/, by building slug; buildings without art
 * are drawn as placeholder blocks.
 */
final class AssetManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $version = (string) config('hushfield.assets.version');
        $base = rtrim((string) (config('hushfield.assets.base_url') ?: asset('assets')), '/')."/v{$version}";

        /** @var array<string, string> $images */
        $images = config('hushfield.assets.images');

        $buildings = [];

        foreach (glob(public_path("assets/v{$version}/buildings/*.png")) ?: [] as $file) {
            $buildings[basename($file, '.png')] = "{$base}/buildings/".basename($file);
        }

        return response()->json([
            'version' => $version,
            'images' => array_map(fn (string $file): string => "{$base}/{$file}", $images),
            'buildings' => (object) $buildings,
        ])->header('Cache-Control', 'no-cache');
    }
}

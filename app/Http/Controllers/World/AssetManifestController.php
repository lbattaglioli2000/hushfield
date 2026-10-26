<?php

namespace App\Http\Controllers\World;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Every game asset URL comes from here, so assets can move to a CDN by changing HUSHFIELD_ASSET_URL.
 * The images themselves are listed in config/hushfield.php. Building art is
 * whatever PNGs are under buildings/, by building slug, and furniture art
 * whatever PNGs are under furniture/, by file name ({slug} or
 * {slug}-{rotation}, see resources/js/game/shared/furniture.ts). Anything
 * without art is drawn as a placeholder block.
 */
final class AssetManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $version = (string) config('hushfield.assets.version');
        $base = rtrim((string) (config('hushfield.assets.base_url') ?: asset('assets')), '/')."/v{$version}";

        /** @var array<string, string> $images */
        $images = config('hushfield.assets.images');

        $art = function (string $folder) use ($version, $base): object {
            $found = [];

            foreach (glob(public_path("assets/v{$version}/{$folder}/*.png")) ?: [] as $file) {
                $found[basename($file, '.png')] = "{$base}/{$folder}/".basename($file);
            }

            return (object) $found;
        };

        return response()->json([
            'version' => $version,
            'images' => array_map(fn (string $file): string => "{$base}/{$file}", $images),
            'buildings' => $art('buildings'),
            'furniture' => $art('furniture'),
        ])->header('Cache-Control', 'no-cache');
    }
}

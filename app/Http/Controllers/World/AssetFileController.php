<?php

namespace App\Http\Controllers\World;

use App\Domain\Assets\AssetIndex;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the built assets the current index names. Each name carries its
 * content hash, so a URL never changes what it serves and may be cached for
 * good. A file from an older build is gone; the client refetches the
 * manifest and tries again.
 */
final class AssetFileController extends Controller
{
    public function __invoke(AssetIndex $assets, string $path): BinaryFileResponse
    {
        abort_unless(in_array($path, $assets->current()['files'], true), 404);

        return response()->file($assets->path($path), [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}

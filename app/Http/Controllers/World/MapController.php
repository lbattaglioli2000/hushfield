<?php

namespace App\Http\Controllers\World;

use App\Domain\World\MapFiles;
use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\Response;

/**
 * Serves the exact map file the server imported, at a URL that changes with every version.
 */
final class MapController extends Controller
{
    public function __invoke(Room $room, string $version, MapFiles $files): Response
    {
        abort_unless($room->map_version === $version, 404);

        return response($files->read($room->slug), 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}

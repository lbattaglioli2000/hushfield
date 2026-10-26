<?php

namespace App\Http\Controllers\World;

use App\Actions\World\BuildSnapshot;
use App\Http\Controllers\Controller;
use App\Http\Responses\WorldResponse;
use App\Models\RoomInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SnapshotController extends Controller
{
    public function __invoke(Request $request, RoomInstance $instance, BuildSnapshot $buildSnapshot): JsonResponse
    {
        // The snapshot carries the protocol version itself, as it does inside a join's answer.
        return WorldResponse::json($buildSnapshot($request->user(), $instance)->toArray());
    }
}

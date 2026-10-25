<?php

namespace App\Http\Controllers\World;

use App\Actions\World\BuildSnapshot;
use App\Http\Controllers\Controller;
use App\Models\RoomInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SnapshotController extends Controller
{
    public function __invoke(Request $request, RoomInstance $instance, BuildSnapshot $buildSnapshot): JsonResponse
    {
        return response()->json($buildSnapshot($request->user(), $instance)->toArray());
    }
}

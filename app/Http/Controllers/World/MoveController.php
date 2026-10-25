<?php

namespace App\Http\Controllers\World;

use App\Actions\World\MovePlayer;
use App\Http\Controllers\Controller;
use App\Http\Requests\World\MoveRequest;
use App\Models\RoomInstance;
use Illuminate\Http\JsonResponse;

final class MoveController extends Controller
{
    public function __invoke(MoveRequest $request, RoomInstance $instance, MovePlayer $movePlayer): JsonResponse
    {
        $path = $movePlayer($request->user(), $instance, $request->integer('x'), $request->integer('y'));

        return response()->json([
            'protocol' => (int) config('hushfield.protocol'),
            ...$path->toArray(),
        ]);
    }
}

<?php

namespace App\Http\Controllers\World;

use App\Actions\World\BuildSnapshot;
use App\Actions\World\JoinRoom;
use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\RoomInstance;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class JoinRoomController extends Controller
{
    public function __invoke(Request $request, Room $room, JoinRoom $joinRoom, BuildSnapshot $buildSnapshot): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $preferred = $request->filled('instance_id') ? RoomInstance::query()->find($request->integer('instance_id')) : null;

        $result = $joinRoom($user, $room, $preferred);

        return response()->json([
            'notice' => $result->overflowed ? __('hushfield.instance_full') : null,
            'snapshot' => $buildSnapshot($user, $result->instance),
        ]);
    }
}

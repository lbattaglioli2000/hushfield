<?php

namespace App\Http\Controllers\World;

use App\Actions\World\BuildSnapshot;
use App\Actions\World\JoinRoom;
use App\Http\Controllers\Controller;
use App\Http\Requests\World\JoinRoomRequest;
use App\Http\Responses\WorldResponse;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class JoinRoomController extends Controller
{
    public function __invoke(JoinRoomRequest $request, Room $room, JoinRoom $joinRoom, BuildSnapshot $buildSnapshot): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $joinRoom($user, $room, $request->preferredInstance());

        return WorldResponse::json([
            'notice' => $result->overflowed ? __('hushfield.instance_full') : null,
            'snapshot' => $buildSnapshot($user, $result->instance)->toArray(),
        ]);
    }
}

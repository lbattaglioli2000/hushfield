<?php

namespace App\Http\Controllers\World;

use App\Actions\World\JoinRoom;
use App\Http\Controllers\Controller;
use App\Http\Requests\World\JoinRoomRequest;
use App\Http\Responses\JoinResponse;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class JoinRoomController extends Controller
{
    public function __invoke(JoinRoomRequest $request, Room $room, JoinRoom $joinRoom, JoinResponse $response): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $response->for($user, $joinRoom($user, $room, $request->preferredInstance()));
    }
}

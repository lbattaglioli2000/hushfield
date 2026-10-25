<?php

namespace App\Http\Controllers\World;

use App\Actions\World\UseExit;
use App\Http\Controllers\Controller;
use App\Http\Responses\JoinResponse;
use App\Models\RoomInstance;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UseExitController extends Controller
{
    public function __invoke(Request $request, RoomInstance $instance, string $exit, UseExit $useExit, JoinResponse $response): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $response->for($user, $useExit($user, $instance, $exit));
    }
}

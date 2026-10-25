<?php

namespace App\Http\Controllers\World;

use App\Actions\Presence\RecordHeartbeat;
use App\Domain\World\Clock;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HeartbeatController extends Controller
{
    public function __invoke(Request $request, RecordHeartbeat $recordHeartbeat): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $recordHeartbeat($user->worldSession);

        return response()->json([
            'protocol' => (int) config('hushfield.protocol'),
            'server_time_ms' => Clock::nowMs(),
        ]);
    }
}

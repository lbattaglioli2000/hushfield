<?php

namespace App\Http\Controllers\Housing;

use App\Domain\Presence\InstanceMembers;
use App\Http\Controllers\Controller;
use App\Http\Responses\WorldResponse;
use App\Models\RoomInstance;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The instance's furniture arrangement, for someone inside it: what clients
 * fetch when told it changed (FurnitureChanged).
 */
final class FurnitureController extends Controller
{
    public function __invoke(Request $request, RoomInstance $instance, InstanceMembers $members): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $members->ensureContains($instance->id, $user->id);

        return WorldResponse::json(['furniture' => $instance->furniturePayload()]);
    }
}

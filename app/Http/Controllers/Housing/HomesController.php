<?php

namespace App\Http\Controllers\Housing;

use App\Domain\Housing\HomeVisitors;
use App\Domain\Housing\Residences;
use App\Domain\Presence\InstanceMembers;
use App\Http\Controllers\Controller;
use App\Http\Responses\WorldResponse;
use App\Models\Home;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The resident's own home, and the homes they may visit (open to everyone, or
 * to friends when they are one), busiest first.
 */
final class HomesController extends Controller
{
    private const int LISTED = 50;

    public function __invoke(Request $request, Residences $residences, InstanceMembers $members): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $residence = $residences->room();

        if ($residence === null) {
            return WorldResponse::json(['residence' => null, 'own' => null, 'open' => []]);
        }

        $open = Home::query()
            ->with('owner')
            ->whereKeyNot($user->home->id ?? 0)
            ->where(fn ($query) => $query
                ->where('visitors', HomeVisitors::Everyone)
                ->orWhere(fn ($query) => $query
                    ->where('visitors', HomeVisitors::Friends)
                    ->whereIn('user_id', $user->friends()->select('users.id'))))
            ->latest('updated_at')
            ->limit(self::LISTED)
            ->get();
        $counts = $members->counts($open->pluck('room_instance_id')->map(intval(...))->all());

        return WorldResponse::json([
            'residence' => $residence->slug,
            'own' => $user->home === null ? null : ['instance_id' => $user->home->room_instance_id, 'visitors' => $user->home->visitors->value],
            'open' => $open
                ->map(fn (Home $home): array => [
                    'instance_id' => $home->room_instance_id,
                    'owner' => $home->owner->username,
                    'friends_only' => $home->visitors === HomeVisitors::Friends,
                    'visitors' => $counts[$home->room_instance_id] ?? 0,
                ])
                ->sortByDesc('visitors')
                ->values()
                ->all(),
        ]);
    }
}

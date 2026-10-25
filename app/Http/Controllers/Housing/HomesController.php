<?php

namespace App\Http\Controllers\Housing;

use App\Domain\Housing\Residences;
use App\Domain\Presence\InstanceMembers;
use App\Http\Controllers\Controller;
use App\Http\Responses\WorldResponse;
use App\Models\Home;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The resident's own home and the homes open to visitors, busiest first.
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

        $open = Home::query()->with('owner')->where('is_open', true)->whereKeyNot($user->home->id ?? 0)->latest('updated_at')->limit(self::LISTED)->get();
        $counts = $members->counts($open->pluck('room_instance_id')->map(intval(...))->all());

        return WorldResponse::json([
            'residence' => $residence->slug,
            'own' => $user->home === null ? null : ['instance_id' => $user->home->room_instance_id, 'is_open' => $user->home->is_open],
            'open' => $open
                ->map(fn (Home $home): array => [
                    'instance_id' => $home->room_instance_id,
                    'owner' => $home->owner->username,
                    'visitors' => $counts[$home->room_instance_id] ?? 0,
                ])
                ->sortByDesc('visitors')
                ->values()
                ->all(),
        ]);
    }
}

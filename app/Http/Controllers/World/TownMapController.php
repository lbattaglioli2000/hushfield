<?php

namespace App\Http\Controllers\World;

use App\Domain\Presence\InstanceMembers;
use App\Domain\World\RoomKind;
use App\Domain\World\WalkabilityGridRepository;
use App\Http\Controllers\Controller;
use App\Http\Responses\WorldResponse;
use App\Models\Room;
use App\Models\RoomInstance;
use Illuminate\Http\JsonResponse;

/**
 * The town map: every room a resident can travel to, where it sits (from its
 * map's town_x and town_y), the rooms its doors join it to, and how many are in
 * it. The residence room is one room on the map; its homes are not listed or
 * counted, since travelling there takes a resident to their own.
 */
final class TownMapController extends Controller
{
    public function __invoke(WalkabilityGridRepository $grids, InstanceMembers $members): JsonResponse
    {
        // Yards are reached through their house's back door, not from the map.
        $rooms = Room::query()->where('is_active', true)->whereNotNull('map_version')->whereNot('kind', RoomKind::Yard)->orderBy('name')->get()->keyBy('slug');

        // A door joins two rooms whichever side it is on.
        /** @var array<string, list<string>> $links */
        $links = array_fill_keys($rooms->keys()->all(), []);

        foreach ($rooms as $slug => $room) {
            foreach ($grids->forRoom($room)->exits as $exit) {
                if ($exit->to !== $slug && $rooms->has($exit->to)) {
                    $links[$slug][] = $exit->to;
                    $links[$exit->to][] = $slug;
                }
            }
        }

        $instances = RoomInstance::query()
            ->whereIn('room_id', $rooms->reject(fn (Room $room): bool => $room->isResidence())->pluck('id'))
            ->where('is_open', true)
            ->get();
        $counts = $members->countsFor($instances);
        $occupants = $instances->groupBy('room_id')->map(fn ($open): int => $open->sum(fn (RoomInstance $instance): int => $counts[$instance->id]));

        return WorldResponse::json([
            'rooms' => $rooms->values()->map(fn (Room $room): array => [
                'slug' => $room->slug,
                'name' => $room->name,
                'kind' => $room->kind->value,
                'frequency' => $room->frequency,
                'position' => $room->town_x === null || $room->town_y === null ? null : ['x' => $room->town_x, 'y' => $room->town_y],
                'links' => collect($links[$room->slug])->unique()->sort()->values()->all(),
                'occupants' => $room->isResidence() ? null : $occupants->get($room->id, 0),
            ])->all(),
        ]);
    }
}

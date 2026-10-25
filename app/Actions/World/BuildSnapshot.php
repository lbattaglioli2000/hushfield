<?php

namespace App\Actions\World;

use App\Domain\Presence\InstanceMembers;
use App\Domain\World\Clock;
use App\Domain\World\PositionStore;
use App\Domain\World\StoredPath;
use App\Exceptions\WorldActionRefused;
use App\Models\RoomInstance;
use App\Models\User;

/**
 * Everything a client needs to draw an instance from scratch. Clients apply
 * live events on top, and fetch a fresh snapshot after every reconnect.
 */
final readonly class BuildSnapshot
{
    public function __construct(
        private InstanceMembers $members,
        private PositionStore $positions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(User $viewer, RoomInstance $instance): array
    {
        if (! $this->members->contains($instance->id, $viewer->id)) {
            throw WorldActionRefused::notInRoom();
        }

        $room = $instance->room;
        $memberIds = $this->members->all($instance->id);
        $paths = $this->positions->many($memberIds);
        $now = Clock::nowMs();

        $players = User::query()
            ->with('avatar')
            ->whereIn('id', $memberIds)
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'username' => $user->username,
                'avatar' => $user->avatar?->toPayload(),
                ...($paths[$user->id] ?? StoredPath::standingAt($room->spawn_x, $room->spawn_y, $now))->toArray(),
            ])
            ->values()
            ->all();

        return [
            'protocol' => (int) config('hushfield.protocol'),
            'server_time_ms' => $now,
            'instance' => [
                'id' => $instance->id,
                'number' => $instance->number,
                'label' => $instance->label(),
                'channel' => $instance->channelName(),
            ],
            'room' => [
                'slug' => $room->slug,
                'name' => $room->name,
                'map_version' => $room->map_version,
                'map_url' => route('world.maps.show', ['room' => $room->slug, 'version' => $room->map_version]),
            ],
            'players' => $players,
        ];
    }
}

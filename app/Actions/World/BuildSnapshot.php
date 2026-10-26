<?php

namespace App\Actions\World;

use App\Domain\Assets\AssetIndex;
use App\Domain\Presence\InstanceMembers;
use App\Domain\World\Clock;
use App\Domain\World\PlayerState;
use App\Domain\World\PositionStore;
use App\Domain\World\Snapshot;
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
        private AssetIndex $assets,
    ) {}

    public function __invoke(User $viewer, RoomInstance $instance): Snapshot
    {
        $this->members->ensureContains($instance->id, $viewer->id);

        $memberIds = $this->members->all($instance->id);
        $now = Clock::nowMs();
        $paths = $this->positions->manyOrSpawn($memberIds, $instance->room, $now);

        $players = array_values(User::query()
            ->with('avatar')
            ->whereIn('id', $memberIds)
            ->get()
            ->map(fn (User $user): PlayerState => new PlayerState($user, $paths[$user->id]))
            ->all());

        return new Snapshot($instance, $now, $players, $this->assets->current()['version']);
    }
}

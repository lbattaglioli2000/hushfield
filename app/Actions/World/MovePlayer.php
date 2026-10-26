<?php

namespace App\Actions\World;

use App\Domain\Housing\HomeFloor;
use App\Domain\Presence\InstanceMembers;
use App\Domain\World\Clock;
use App\Domain\World\Pathfinder;
use App\Domain\World\PositionStore;
use App\Domain\World\StoredPath;
use App\Events\World\PlayerMoved;
use App\Events\WorldBroadcast;
use App\Exceptions\WorldActionRefused;
use App\Models\RoomInstance;
use App\Models\User;

/**
 * Computes a path from wherever the player is right now to the clicked tile,
 * stores it with a start time, and broadcasts it once.
 */
final readonly class MovePlayer
{
    public function __construct(
        private InstanceMembers $members,
        private PositionStore $positions,
        private HomeFloor $floors,
        private Pathfinder $pathfinder,
    ) {}

    public function __invoke(User $user, RoomInstance $instance, int $x, int $y): StoredPath
    {
        $this->members->ensureContains($instance->id, $user->id);

        $room = $instance->room;
        $grid = $this->floors->grid($instance);
        $now = Clock::nowMs();
        $position = $this->positions->currentOrSpawn($user->id, $room, $now)->positionAt($now);

        // Mid-step, finish the current step first so the walker never jumps:
        // the new path begins with the step already under way, backdated to when it started.
        $midStep = ! $position->finished && $position->progress > 0;
        $start = $midStep ? $position->to : $position->tile;
        $startedAt = $midStep ? $now - (int) round($position->segmentElapsedMs) : $now;

        $route = $this->pathfinder->find($grid, $start, [$x, $y], (int) config('hushfield.movement.max_path_steps'));

        if ($route === null) {
            throw WorldActionRefused::unreachable();
        }

        $path = new StoredPath(
            $midStep ? [$position->from, ...$route] : $route,
            $startedAt,
            (float) config('hushfield.movement.speed_tiles_per_sec'),
        );

        $this->positions->put($user->id, $path);

        WorldBroadcast::toOthers(new PlayerMoved($instance->id, $user->id, $path));

        return $path;
    }
}

<?php

use App\Actions\Presence\SweepGhosts;
use App\Domain\Presence\InstanceMembers;
use App\Domain\World\PositionStore;
use App\Events\World\PlayerLeft;
use App\Models\WorldSession;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    fakeWorldEvents();
    $this->room = importRoom();
});

test('ghosts not seen for 60 seconds are swept out of the world and fresh sessions stay', function () {
    $this->freezeTime();
    $ghost = resident();
    $fresh = resident();
    ['instance' => $instance] = enterWorld($ghost, $this->room);
    enterWorld($fresh, $this->room);

    $this->travel(30)->seconds();
    WorldSession::query()->where('user_id', $fresh->id)->update(['last_seen_at' => now()]);
    $this->travel(31)->seconds();

    expect(app(SweepGhosts::class)())->toBe(1)
        ->and(WorldSession::query()->pluck('user_id')->all())->toBe([$fresh->id])
        ->and(app(InstanceMembers::class)->all($instance->id))->toBe([$fresh->id])
        ->and(app(PositionStore::class)->get($ghost->id))->toBeNull()
        ->and(app(PositionStore::class)->get($fresh->id))->not->toBeNull()
        ->and($ghost->fresh()->last_room_instance_id)->toBeNull();

    Event::assertDispatched(PlayerLeft::class, fn (PlayerLeft $event): bool => $event->userId === $ghost->id
        && $event->broadcastOn()->name === "presence-room.{$instance->id}");
    Event::assertDispatchedTimes(PlayerLeft::class, 1);
});

test('a session seen exactly 60 seconds ago is not yet a ghost', function () {
    $this->freezeTime();
    enterWorld(resident(), $this->room);

    $this->travel(60)->seconds();

    expect(app(SweepGhosts::class)())->toBe(0)
        ->and(WorldSession::query()->count())->toBe(1);
});

test('players left in an instance without a world session are swept, including deleted accounts', function () {
    $present = resident();
    $orphan = resident();
    $deleted = resident();
    ['instance' => $instance] = enterWorld($present, $this->room);
    enterWorld($orphan, $this->room);
    enterWorld($deleted, $this->room);

    // The session row disappears without LeaveRoom running: an interrupted request, or the account being deleted.
    WorldSession::query()->where('user_id', $orphan->id)->delete();
    $deletedId = $deleted->id;
    $deleted->delete();

    expect(app(SweepGhosts::class)())->toBe(2)
        ->and(app(InstanceMembers::class)->all($instance->id))->toBe([$present->id])
        ->and(app(PositionStore::class)->get($orphan->id))->toBeNull()
        ->and(app(PositionStore::class)->get($deletedId))->toBeNull()
        ->and($orphan->fresh()->last_room_instance_id)->toBeNull();

    Event::assertDispatched(PlayerLeft::class, fn (PlayerLeft $event): bool => $event->userId === $orphan->id);
    Event::assertDispatched(PlayerLeft::class, fn (PlayerLeft $event): bool => $event->userId === $deletedId);
});

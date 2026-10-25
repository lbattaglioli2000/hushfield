<?php

use App\Actions\Presence\EndWorldSession;
use App\Actions\World\CloseEmptyInstances;
use App\Actions\World\CloseInstance;
use App\Actions\World\GatherIntoFirstInstance;
use App\Actions\World\JoinRoom;
use App\Domain\Presence\InstanceMembers;
use App\Domain\World\InstanceAllocator;
use App\Events\Presence\InstanceReassigned;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    fakeWorldEvents();
    $this->room = importRoom();
    $this->staff = User::factory()->staff()->create();
    $this->members = app(InstanceMembers::class);
    $this->one = $this->room->instances()->where('number', 1)->firstOrFail();
    $this->two = $this->room->instances()->create(['number' => 2, 'is_open' => true]);
});

test('closing an instance empties it and tells each occupant where to go', function () {
    $a = resident();
    $b = resident();
    ['token' => $tokenA] = enterWorld($a, $this->room, $this->two);
    ['token' => $tokenB] = enterWorld($b, $this->room, $this->two);

    $moved = app(CloseInstance::class)($this->two, $this->staff, $this->one);

    expect($moved)->toBe(2)
        ->and($this->two->fresh()->is_open)->toBeFalse()
        ->and($this->members->count($this->two->id))->toBe(0);

    foreach ([$tokenA, $tokenB] as $token) {
        Event::assertDispatched(InstanceReassigned::class, fn (InstanceReassigned $event): bool => $event->broadcastOn()->name === "private-world-session.{$token}"
            && $event->broadcastWith()['target_instance_id'] === $this->one->id);
    }

    $this->assertDatabaseHas('activity_log', ['event' => 'instance.closed', 'subject_id' => $this->two->id, 'causer_id' => $this->staff->id]);
});

test('emptying an instance can keep it open', function () {
    enterWorld(resident(), $this->room, $this->two);

    app(CloseInstance::class)($this->two, $this->staff, keepOpen: true);

    expect($this->two->fresh()->is_open)->toBeTrue()
        ->and($this->members->count($this->two->id))->toBe(0);
    Event::assertDispatched(InstanceReassigned::class, fn (InstanceReassigned $event): bool => $event->targetInstanceId === null);
});

test('gathering moves everyone towards instance 1', function () {
    $three = $this->room->instances()->create(['number' => 3, 'is_open' => true]);
    foreach ([$this->two, $this->two, $three] as $instance) {
        enterWorld(resident(), $this->room, $instance);
    }
    $stayer = resident();
    app(JoinRoom::class)($stayer, $this->room, $this->one);

    expect(app(GatherIntoFirstInstance::class)($this->room, $this->staff))->toBe(3)
        ->and($this->two->fresh()->is_open)->toBeFalse()
        ->and($three->fresh()->is_open)->toBeFalse()
        ->and($this->members->all($this->one->id))->toBe([$stayer->id]);

    Event::assertDispatchedTimes(InstanceReassigned::class, 3);
    Event::assertDispatched(InstanceReassigned::class, fn (InstanceReassigned $event): bool => $event->targetInstanceId === $this->one->id);
});

test('empty instances above 1 close after 10 minutes', function () {
    $this->freezeTime();
    $stale = $this->two;
    $stale->update(['empty_since' => now()->subMinutes(10)]);
    $recent = $this->room->instances()->create(['number' => 3, 'is_open' => true, 'empty_since' => now()->subMinutes(9)]);
    $occupied = $this->room->instances()->create(['number' => 4, 'is_open' => true, 'empty_since' => now()->subHour()]);
    $never = $this->room->instances()->create(['number' => 5, 'is_open' => true]);
    $this->one->update(['empty_since' => now()->subHour()]);
    app(JoinRoom::class)(resident(), $this->room, $occupied);
    // Joining clears empty_since; put it back to simulate a stale marker on an occupied instance.
    $occupied->update(['empty_since' => now()->subHour()]);

    expect(app(CloseEmptyInstances::class)())->toBe(1)
        ->and($stale->fresh()->is_open)->toBeFalse()
        ->and($recent->fresh()->is_open)->toBeTrue()
        ->and($occupied->fresh()->is_open)->toBeTrue()
        ->and($never->fresh()->is_open)->toBeTrue()
        ->and($this->one->fresh()->is_open)->toBeTrue();
});

test('empty instances are not closed while a join holds the room lock', function () {
    $this->two->update(['empty_since' => now()->subHour()]);
    $join = app(InstanceAllocator::class)->lockFor($this->room->id);
    $join->get();

    expect(app(CloseEmptyInstances::class)())->toBe(0)
        ->and($this->two->fresh()->is_open)->toBeTrue();

    $join->release();

    expect(app(CloseEmptyInstances::class)())->toBe(1)
        ->and($this->two->fresh()->is_open)->toBeFalse();
});

test('a join never lands in an instance that was closed after the client chose it', function () {
    $chosen = $this->two->fresh();
    $this->two->update(['is_open' => false]);

    $result = app(JoinRoom::class)(resident(), $this->room, $chosen);

    expect($result->instance->id)->toBe($this->one->id)
        ->and($this->members->count($this->two->id))->toBe(0);
});

test('the last resident leaving marks the instance empty', function () {
    $this->freezeSecond();
    $user = resident();
    ['instance' => $instance] = enterWorld($user, $this->room);

    expect($instance->fresh()->empty_since)->toBeNull();

    app(EndWorldSession::class)($user);

    expect($instance->fresh()->empty_since->equalTo(now()))->toBeTrue();
});

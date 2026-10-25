<?php

use App\Actions\Presence\StartWorldSession;
use App\Actions\World\CloseInstance;
use App\Actions\World\GatherIntoFirstInstance;
use App\Actions\World\JoinRoom;
use App\Domain\Presence\InstanceMembers;
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
    $tokenA = app(StartWorldSession::class)($a)->session_token;
    $tokenB = app(StartWorldSession::class)($b)->session_token;
    app(JoinRoom::class)($a, $this->room, $this->two);
    app(JoinRoom::class)($b, $this->room, $this->two);

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
    $user = resident();
    app(StartWorldSession::class)($user);
    app(JoinRoom::class)($user, $this->room, $this->two);

    app(CloseInstance::class)($this->two, $this->staff, keepOpen: true);

    expect($this->two->fresh()->is_open)->toBeTrue()
        ->and($this->members->count($this->two->id))->toBe(0);
    Event::assertDispatched(InstanceReassigned::class, fn (InstanceReassigned $event): bool => $event->targetInstanceId === null);
});

test('gathering moves everyone towards instance 1', function () {
    $three = $this->room->instances()->create(['number' => 3, 'is_open' => true]);
    foreach ([$this->two, $this->two, $three] as $instance) {
        $user = resident();
        app(StartWorldSession::class)($user);
        app(JoinRoom::class)($user, $this->room, $instance);
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

<?php

use App\Actions\World\JoinRoom;
use App\Domain\Presence\InstanceMembers;
use App\Domain\World\PositionStore;
use App\Events\World\PlayerJoined;
use App\Events\World\PlayerLeft;
use App\Features\JoinsEnabled;
use App\Features\WorldFlags;
use App\Models\Room;
use App\Models\RoomInstance;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    fakeWorldEvents();
    $this->room = importRoom();
    $this->members = app(InstanceMembers::class);
});

function joinOverHttp(User $user, Room $room, array $data = [], ?string $socket = null)
{
    return asResident($user, startSession($user), $socket)
        ->postJson(route('world.rooms.join', $room), $data);
}

/**
 * Fills an instance with residents who joined through the real action.
 */
function fillInstance(RoomInstance $instance, int $count): void
{
    foreach (User::factory()->count($count)->create() as $user) {
        app(JoinRoom::class)($user, $instance->room, $instance);
    }
}

test('joining puts the resident in instance 1 at a spawn and announces them to others', function () {
    $user = resident();

    $response = joinOverHttp($user, $this->room, socket: 'socket.42');
    $instance = $this->room->instances()->where('number', 1)->firstOrFail();

    $response->assertOk()
        ->assertJsonPath('notice', null)
        ->assertJsonPath('snapshot.instance.id', $instance->id)
        ->assertJsonPath('snapshot.instance.number', 1)
        ->assertJsonPath('snapshot.instance.channel', "room.{$instance->id}")
        ->assertJsonPath('snapshot.players.0.id', $user->id)
        ->assertJsonPath('snapshot.players.0.path', [[1, 1]]);

    expect($this->members->all($instance->id))->toBe([$user->id])
        ->and($user->fresh()->last_room_instance_id)->toBe($instance->id)
        ->and(app(PositionStore::class)->get($user->id)->path)->toBe([[1, 1]]);

    Event::assertDispatched(PlayerJoined::class, fn (PlayerJoined $event): bool => $event->broadcastOn()->name === "presence-room.{$instance->id}"
        && $event->socket === 'socket.42'
        && $event->broadcastWith()['player']['id'] === $user->id
        && $event->broadcastWith()['player']['username'] === $user->username
        && $event->broadcastWith()['player']['path'] === [[1, 1]]);
});

test('a resident joining a full instance lands in instance 2 with an overflow notice', function () {
    $this->room->update(['capacity_per_instance' => 3]);
    $first = $this->room->instances()->where('number', 1)->firstOrFail();
    fillInstance($first, 3);

    $response = joinOverHttp(resident(), $this->room);

    $response->assertOk()
        ->assertJsonPath('protocol', 1)
        ->assertJsonPath('notice', __('hushfield.instance_full'))
        ->assertJsonPath('snapshot.instance.number', 2);

    expect($this->members->count($first->id))->toBe(3)
        ->and($this->room->instances()->count())->toBe(2);
});

test('residents join the busiest open instance that still has room', function () {
    $this->room->update(['capacity_per_instance' => 5]);
    [$one, $two, $three] = [
        $this->room->instances()->where('number', 1)->firstOrFail(),
        $this->room->instances()->create(['number' => 2, 'is_open' => true]),
        $this->room->instances()->create(['number' => 3, 'is_open' => true]),
    ];
    fillInstance($one, 1);
    fillInstance($two, 3);
    fillInstance($three, 2);

    joinOverHttp(resident(), $this->room)->assertJsonPath('snapshot.instance.number', 2)->assertJsonPath('notice', null);
    joinOverHttp(resident(), $this->room)->assertJsonPath('snapshot.instance.number', 2);
    // Instance 2 is now full (5), so the next busiest, 3, is next.
    joinOverHttp(resident(), $this->room)->assertJsonPath('snapshot.instance.number', 3);
});

test('ties go to the lowest numbered instance', function () {
    $two = $this->room->instances()->create(['number' => 2, 'is_open' => true]);
    fillInstance($two, 2);
    fillInstance($this->room->instances()->where('number', 1)->firstOrFail(), 2);

    joinOverHttp(resident(), $this->room)->assertJsonPath('snapshot.instance.number', 1);
});

test('closed instances are skipped, then reopened before a new one is created', function () {
    $this->room->update(['capacity_per_instance' => 2]);
    $one = $this->room->instances()->where('number', 1)->firstOrFail();
    $closed = $this->room->instances()->create(['number' => 2, 'is_open' => false]);
    fillInstance($one, 2);

    joinOverHttp(resident(), $this->room)
        ->assertJsonPath('snapshot.instance.id', $closed->id)
        ->assertJsonPath('notice', __('hushfield.instance_full'));

    expect($closed->fresh()->is_open)->toBeTrue();
});

test('a preferred instance is honoured while it has room', function () {
    $this->room->update(['capacity_per_instance' => 2]);
    $one = $this->room->instances()->where('number', 1)->firstOrFail();
    $quiet = $this->room->instances()->create(['number' => 2, 'is_open' => true]);
    fillInstance($one, 1);

    joinOverHttp(resident(), $this->room, ['instance_id' => $quiet->id])->assertJsonPath('snapshot.instance.id', $quiet->id);
    joinOverHttp(resident(), $this->room, ['instance_id' => $quiet->id])->assertJsonPath('snapshot.instance.id', $quiet->id);
    // Now full: fall back to the normal rule.
    joinOverHttp(resident(), $this->room, ['instance_id' => $quiet->id])->assertJsonPath('snapshot.instance.id', $one->id);
});

test('a preferred instance in another room is ignored', function () {
    $other = importRoom('test-square-copy');
    $elsewhere = $other->instances()->firstOrFail();

    joinOverHttp(resident(), $this->room, ['instance_id' => $elsewhere->id])
        ->assertJsonPath('snapshot.instance.number', 1)
        ->assertJsonPath('snapshot.room.slug', 'test-square');

    expect($this->members->count($elsewhere->id))->toBe(0);
});

test('joining again leaves the previous instance first', function () {
    $user = resident();
    $two = $this->room->instances()->create(['number' => 2, 'is_open' => true]);
    $one = $this->room->instances()->where('number', 1)->firstOrFail();

    joinOverHttp($user, $this->room, ['instance_id' => $two->id])->assertOk();
    joinOverHttp($user, $this->room, ['instance_id' => $one->id])->assertOk();

    expect($this->members->contains($two->id, $user->id))->toBeFalse()
        ->and($this->members->contains($one->id, $user->id))->toBeTrue()
        ->and($two->fresh()->empty_since)->not->toBeNull();

    Event::assertDispatched(PlayerLeft::class, fn (PlayerLeft $event): bool => $event->broadcastOn()->name === "presence-room.{$two->id}"
        && $event->userId === $user->id);
});

test('the joins kill switch refuses new joins', function () {
    WorldFlags::set(JoinsEnabled::class, false);

    joinOverHttp(resident(), $this->room)
        ->assertStatus(503)
        ->assertJsonPath('reason', 'joins_paused');

    expect($this->members->count($this->room->instances()->firstOrFail()->id))->toBe(0);
    Event::assertNotDispatched(PlayerJoined::class);

    WorldFlags::set(JoinsEnabled::class, true);

    joinOverHttp(resident(), $this->room)->assertOk();
});

test('inactive rooms refuse joins', function () {
    $this->room->update(['is_active' => false]);

    joinOverHttp(resident(), $this->room)->assertStatus(503)->assertJsonPath('reason', 'joins_paused');
});

test('banned residents cannot join', function () {
    $user = resident();
    $token = startSession($user);
    $user->forceFill(['banned_until' => now()->addDay()])->save();

    asResident($user, $token)->postJson(route('world.rooms.join', $this->room))
        ->assertForbidden()
        ->assertJsonPath('reason', 'banned');
});

test('joins are rate limited', function () {
    $user = resident();
    asResident($user, startSession($user));

    foreach (range(1, 10) as $attempt) {
        $this->postJson(route('world.rooms.join', $this->room))->assertOk();
    }

    $this->postJson(route('world.rooms.join', $this->room))->assertTooManyRequests();
});

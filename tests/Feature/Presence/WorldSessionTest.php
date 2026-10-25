<?php

use App\Domain\Presence\InstanceMembers;
use App\Events\Presence\SessionSuperseded;
use App\Events\World\PlayerLeft;
use App\Models\User;
use App\Models\WorldSession;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    fakeWorldEvents();
    $this->room = importRoom();
});

test('starting a session issues a token', function () {
    $user = resident();

    $response = $this->actingAs($user)->postJson(route('world.session.start'));

    $response->assertCreated()
        ->assertJsonPath('protocol', 1)
        ->assertJsonPath('user_id', $user->id);

    expect($response->json('session_token'))->toBeString()->toHaveLength(48)
        ->and(WorldSession::query()->where('user_id', $user->id)->value('session_token'))->toBe($response->json('session_token'));
    Event::assertNotDispatched(SessionSuperseded::class);
});

test('a new session supersedes the old one, whose token then gets 409', function () {
    $user = resident();
    $this->actingAs($user);

    $old = $this->postJson(route('world.session.start'))->json('session_token');
    $instance = $this->withHeaders(worldHeaders($old))->postJson(route('world.rooms.join', $this->room))->json('snapshot.instance.id');

    $new = $this->postJson(route('world.session.start'))->json('session_token');

    expect($new)->not->toBe($old)
        ->and(WorldSession::query()->where('user_id', $user->id)->count())->toBe(1);

    Event::assertDispatched(SessionSuperseded::class, fn (SessionSuperseded $event): bool => $event->broadcastOn()->name === "private-world-session.{$old}");
    Event::assertDispatchedTimes(SessionSuperseded::class, 1);

    $this->withHeaders(worldHeaders($old))
        ->postJson(route('world.instances.move', $instance), ['x' => 3, 'y' => 1])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'session_moved')
        ->assertJsonPath('message', __('hushfield.session_moved'));

    $this->withHeaders(worldHeaders($new))
        ->postJson(route('world.instances.move', $instance), ['x' => 3, 'y' => 1])
        ->assertOk();
});

test('world requests without a token are refused', function () {
    $user = resident();
    startSession($user);

    $this->actingAs($user)->postJson(route('world.heartbeat'))
        ->assertStatus(409)
        ->assertJsonPath('reason', 'session_moved');
});

test('world requests after the session ended report it expired', function () {
    $user = resident();
    $token = startSession($user);
    WorldSession::query()->delete();

    asResident($user, $token)->postJson(route('world.heartbeat'))
        ->assertStatus(409)
        ->assertJsonPath('reason', 'session_expired');
});

test('one resident cannot use another resident\'s token', function () {
    $owner = resident();
    $token = startSession($owner);
    $intruder = resident();
    startSession($intruder);

    asResident($intruder, $token)->postJson(route('world.heartbeat'))
        ->assertStatus(409);
});

test('banned residents cannot start a session', function () {
    $user = resident(['banned_until' => now()->addDay()]);

    $this->actingAs($user)->postJson(route('world.session.start'))
        ->assertForbidden()
        ->assertJsonPath('reason', 'banned');

    expect(WorldSession::query()->count())->toBe(0);
});

test('an expired ban no longer blocks', function () {
    $user = resident(['banned_until' => now()->subMinute()]);

    $this->actingAs($user)->postJson(route('world.session.start'))->assertCreated();
});

test('a heartbeat marks the session as seen and reports server time', function () {
    $this->freezeTime();
    $user = resident();
    $token = startSession($user);

    $this->travel(45)->seconds();

    asResident($user, $token)->postJson(route('world.heartbeat'))
        ->assertOk()
        ->assertJsonPath('server_time_ms', now()->getTimestampMs());

    expect(WorldSession::query()->first()->last_seen_at->getTimestamp())->toBe(now()->getTimestamp());
});

test('ending the session leaves the room and drops the token', function () {
    $user = resident();
    ['token' => $token, 'instance' => $instance] = enterWorld($user, $this->room);

    asResident($user, $token, 'socket.1')->postJson(route('world.session.end'))
        ->assertNoContent();

    expect(WorldSession::query()->count())->toBe(0)
        ->and(app(InstanceMembers::class)->contains($instance->id, $user->id))->toBeFalse()
        ->and($user->fresh()->last_room_instance_id)->toBeNull();

    Event::assertDispatched(PlayerLeft::class, fn (PlayerLeft $event): bool => $event->broadcastOn()->name === "presence-room.{$instance->id}"
        && $event->userId === $user->id);

    $this->withHeaders(worldHeaders($token))->postJson(route('world.heartbeat'))->assertStatus(409);
});

test('staff are ordinary residents in the world', function () {
    $this->actingAs(User::factory()->staff()->create())->postJson(route('world.session.start'))->assertCreated();
});

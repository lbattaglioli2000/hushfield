<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

beforeEach(function () {
    fakeWorldEvents();
    $this->room = importRoom();

    // The null broadcaster authorizes nothing, so use a Pusher-protocol driver
    // (Reverb) with dummy credentials. Authorizing a channel makes no network call.
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    Broadcast::purge();
    require base_path('routes/channels.php');
});

function authorizeChannel(User $user, string $channel)
{
    return test()->actingAs($user)->post('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => $channel,
    ]);
}

test('members of an instance may join its presence channel', function () {
    $user = resident();
    ['instance' => $instance] = enterWorld($user, $this->room);

    $response = authorizeChannel($user, "presence-room.{$instance->id}")->assertOk();

    expect(json_decode($response->json('channel_data'), true))->toBe([
        'user_id' => (string) $user->id,
        'user_info' => [
            'id' => $user->id,
            'username' => $user->username,
            'avatar' => $user->avatar->toPayload(),
        ],
    ]);
});

test('residents outside the instance may not join its presence channel', function () {
    ['instance' => $instance] = enterWorld(resident(), $this->room);
    $outsider = resident();
    enterWorld($outsider, $this->room, $this->room->instances()->create(['number' => 2, 'is_open' => true]));

    authorizeChannel($outsider, "presence-room.{$instance->id}")->assertForbidden();
});

test('banned residents may not join the presence channel even while still listed as members', function () {
    $user = resident();
    ['instance' => $instance] = enterWorld($user, $this->room);
    $user->forceFill(['banned_until' => now()->addDay()])->save();

    authorizeChannel($user->fresh(), "presence-room.{$instance->id}")->assertForbidden();
});

test('guests may not join any channel', function () {
    ['instance' => $instance] = enterWorld(resident(), $this->room);

    $this->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "presence-room.{$instance->id}"])
        ->assertForbidden();
});

test('only the session owner may join the private session channel, and only for the current token', function () {
    $owner = resident();
    $old = startSession($owner);
    $current = startSession($owner);

    authorizeChannel($owner, "private-world-session.{$current}")->assertOk()->assertJsonStructure(['auth']);
    authorizeChannel($owner, "private-world-session.{$old}")->assertForbidden();
    authorizeChannel(resident(), "private-world-session.{$current}")->assertForbidden();
});

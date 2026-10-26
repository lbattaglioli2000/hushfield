<?php

use App\Domain\Housing\HomeVisitors;
use App\Domain\Presence\InstanceMembers;
use App\Events\Presence\InstanceReassigned;
use App\Events\Social\FriendsUpdated;
use App\Models\FriendRequest;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    fakeWorldEvents();
    $this->wren = resident(['username' => 'Wren']);
    $this->moth = resident(['username' => 'Moth']);
    $this->wrenToken = startSession($this->wren);
    $this->mothToken = startSession($this->moth);
});

function asWren()
{
    return asResident(test()->wren, test()->wrenToken);
}

function asMoth()
{
    return asResident(test()->moth, test()->mothToken);
}

function befriend(User $a, User $b): void
{
    Friendship::query()->insert([
        ['user_id' => $a->id, 'friend_id' => $b->id, 'created_at' => now()],
        ['user_id' => $b->id, 'friend_id' => $a->id, 'created_at' => now()],
    ]);
}

test('a request by name reaches the recipient, who accepts it, and both see each other', function () {
    asWren()->postJson(route('world.friends.requests.send'), ['username' => 'moth'])
        ->assertCreated()
        ->assertJsonPath('protocol', 2)
        ->assertJsonPath('message', __('hushfield.friend_request_sent'))
        ->assertJsonPath('friends.outgoing.0.user.username', 'Moth');

    Event::assertDispatched(FriendsUpdated::class, fn (FriendsUpdated $event): bool => $event->sessionToken === $this->mothToken
        && $event->message === 'Wren would like to be your friend.');

    $request = FriendRequest::query()->sole();

    asMoth()->getJson(route('world.friends'))->assertJsonPath('friends.incoming.0.user.username', 'Wren');

    asMoth()->postJson(route('world.friends.requests.accept', $request))
        ->assertOk()
        ->assertJsonPath('friends.friends.0.username', 'Wren')
        ->assertJsonPath('friends.incoming', []);

    expect($this->wren->isFriendsWith($this->moth))->toBeTrue()
        ->and($this->moth->isFriendsWith($this->wren))->toBeTrue()
        ->and(FriendRequest::query()->count())->toBe(0);

    Event::assertDispatched(FriendsUpdated::class, fn (FriendsUpdated $event): bool => $event->sessionToken === $this->wrenToken
        && str_starts_with((string) $event->message, 'Moth is now your friend.'));
});

test('asking someone who already asked you makes you friends at once', function () {
    FriendRequest::query()->create(['sender_id' => $this->moth->id, 'recipient_id' => $this->wren->id]);

    asWren()->postJson(route('world.friends.requests.send'), ['username' => 'Moth'])
        ->assertOk()
        ->assertJsonPath('friends.friends.0.username', 'Moth');

    expect($this->wren->isFriendsWith($this->moth))->toBeTrue()
        ->and(FriendRequest::query()->count())->toBe(0);
});

test('requests are refused for oneself, existing friends, unknown names, closed inboxes and across age brackets', function (Closure $setUp, string $username, int $status, string $reason) {
    $setUp();

    asWren()->postJson(route('world.friends.requests.send'), ['username' => $username])
        ->assertStatus($status)
        ->assertJsonPath('reason', $reason);

    expect(FriendRequest::query()->count())->toBe(0);
})->with([
    'oneself' => [fn () => null, 'Wren', 422, 'friend_self'],
    'already friends' => [fn () => befriend(test()->wren, test()->moth), 'Moth', 409, 'friend_already'],
    'nobody by that name' => [fn () => null, 'Nobody', 404, 'friend_unknown'],
    'requests turned off' => [fn () => test()->moth->forceFill(['friend_requests_allowed' => false])->save(), 'Moth', 403, 'friend_requests_closed'],
    // A minor who has turned requests on still cannot be asked by an adult; the refusal does not say why.
    'another age bracket' => [fn () => test()->moth->forceFill(['is_minor' => true, 'friend_requests_allowed' => true])->save(), 'Moth', 403, 'friend_requests_closed'],
]);

test('two minors who allow requests can become friends', function () {
    $this->wren->forceFill(['is_minor' => true])->save();
    $this->moth->forceFill(['is_minor' => true, 'friend_requests_allowed' => true])->save();

    asWren()->postJson(route('world.friends.requests.send'), ['username' => 'Moth'])->assertCreated();
});

test('only the recipient can accept, and either side can dismiss', function () {
    $request = FriendRequest::query()->create(['sender_id' => $this->wren->id, 'recipient_id' => $this->moth->id]);

    asWren()->postJson(route('world.friends.requests.accept', $request))
        ->assertNotFound()
        ->assertJsonPath('reason', 'friend_request_gone');

    asResident($stranger = resident(), startSession($stranger))->deleteJson(route('world.friends.requests.dismiss', $request))
        ->assertNotFound();

    asWren()->deleteJson(route('world.friends.requests.dismiss', $request))->assertOk()->assertJsonPath('friends.outgoing', []);

    expect(FriendRequest::query()->count())->toBe(0)
        ->and($this->wren->isFriendsWith($this->moth))->toBeFalse();
});

test('the friends list shows where online friends are and whether their room can be joined', function () {
    $room = importRoom('test-lane');
    $residence = importRoom('test-home');
    $closedHomeOwner = resident(['username' => 'Ash']);
    befriend($this->wren, $this->moth);
    befriend($this->wren, $closedHomeOwner);
    befriend($this->wren, resident(['username' => 'Offline']));
    ['instance' => $lane] = enterWorld($this->moth, $room);
    enterWorld($closedHomeOwner, $residence);

    $friends = collect(asWren()->getJson(route('world.friends'))->assertOk()->json('friends.friends'))->keyBy('username');

    expect($friends['Moth']['location'])->toBe(['room' => 'test-lane', 'instance_id' => $lane->id, 'label' => 'Test Lane (1)', 'can_join' => true])
        ->and($friends['Ash']['location']['label'])->toBe("Ash's residence")
        ->and($friends['Ash']['location']['can_join'])->toBeFalse()
        ->and($friends['Offline']['online'])->toBeFalse()
        ->and($friends['Offline']['location'])->toBeNull()
        // Online friends first.
        ->and($friends->keys()->last())->toBe('Offline');

    $closedHomeOwner->home->update(['visitors' => HomeVisitors::Friends]);

    expect(asWren()->getJson(route('world.friends'))->json('friends.friends.0.location.can_join'))->toBeTrue();
});

test('friends-only homes admit friends, are listed for them, and show out a friend who is removed', function () {
    importRoom('test-lane');
    $residence = importRoom('test-home');
    ['token' => $this->wrenToken] = enterWorld($this->wren, $residence);
    $home = $this->wren->home()->sole();
    asWren()->postJson(route('world.home.visitors'), ['visitors' => 'friends'])
        ->assertJsonPath('message', __('hushfield.home_friends'));

    asMoth()->postJson(route('world.rooms.join', $residence), ['instance_id' => $home->room_instance_id])
        ->assertForbidden()
        ->assertJsonPath('reason', 'home_closed');
    asMoth()->getJson(route('world.homes'))->assertJsonPath('open', []);

    befriend($this->wren, $this->moth);

    asMoth()->getJson(route('world.homes'))->assertJsonPath('open.0.owner', 'Wren')->assertJsonPath('open.0.friends_only', true);
    asMoth()->postJson(route('world.rooms.join', $residence), ['instance_id' => $home->room_instance_id])->assertOk();

    asWren()->deleteJson(route('world.friends.remove', $this->moth))
        ->assertOk()
        ->assertJsonPath('friends.friends', []);

    expect($this->moth->isFriendsWith($this->wren))->toBeFalse()
        ->and(app(InstanceMembers::class)->contains($home->room_instance_id, $this->moth->id))->toBeFalse()
        ->and(app(InstanceMembers::class)->contains($home->room_instance_id, $this->wren->id))->toBeTrue();

    Event::assertDispatched(InstanceReassigned::class, fn (InstanceReassigned $event): bool => $event->sessionToken === $this->mothToken);
});

test('residents turn friend requests on and off for themselves', function () {
    asWren()->postJson(route('world.friends.settings'), ['requests_allowed' => false])
        ->assertOk()
        ->assertJsonPath('friends.requests_allowed', false)
        ->assertJsonPath('message', __('hushfield.friend_requests_off'));

    expect($this->wren->fresh()->friend_requests_allowed)->toBeFalse();
});

test('pending requests are capped', function () {
    config(['hushfield.friends.max_pending_requests' => 1]);
    resident(['username' => 'Ash']);

    asWren()->postJson(route('world.friends.requests.send'), ['username' => 'Moth'])->assertCreated();
    asWren()->postJson(route('world.friends.requests.send'), ['username' => 'Ash'])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'friend_too_many_requests');
});

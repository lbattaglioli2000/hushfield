<?php

use App\Domain\World\PathClock;
use App\Domain\World\PositionStore;
use App\Events\World\PlayerMoved;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    fakeWorldEvents();
    $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00:00.000', 'UTC'));
    $this->room = importRoom();
    $this->user = resident();
    ['token' => $this->token, 'instance' => $this->instance] = enterWorld($this->user, $this->room);
    $this->positions = app(PositionStore::class);
});

function move(int $x, int $y, ?string $socket = null)
{
    return test()->actingAs(test()->user)
        ->withHeaders(worldHeaders(test()->token, $socket))
        ->postJson(route('world.instances.move', test()->instance), ['x' => $x, 'y' => $y]);
}

test('a move stores a path from the current tile and broadcasts it to others', function () {
    $response = move(4, 1, 'socket.7');

    $response->assertOk()
        ->assertJsonPath('protocol', 1)
        ->assertJsonPath('path', [[1, 1], [2, 1], [3, 1], [4, 1]])
        ->assertJsonPath('started_at_ms', now()->getTimestampMs())
        ->assertJsonPath('speed', 4);

    expect($this->positions->get($this->user->id)->path)->toBe([[1, 1], [2, 1], [3, 1], [4, 1]]);

    Event::assertDispatched(PlayerMoved::class, fn (PlayerMoved $event): bool => $event->broadcastOn()->name === "presence-room.{$this->instance->id}"
        && $event->socket === 'socket.7'
        && $event->broadcastWith()['user_id'] === $this->user->id
        && $event->broadcastWith()['path'] === [[1, 1], [2, 1], [3, 1], [4, 1]]);
});

test('moves to unreachable or blocked tiles are refused and leave the position unchanged', function (int $x, int $y) {
    move(2, 2)->assertOk();
    $before = $this->positions->get($this->user->id);

    move($x, $y)
        ->assertStatus(422)
        ->assertJsonPath('reason', 'unreachable')
        ->assertJsonPath('message', __('hushfield.unreachable'));

    expect($this->positions->get($this->user->id))->toEqual($before);
    Event::assertDispatchedTimes(PlayerMoved::class, 1);
})->with([
    'walled-off island' => [8, 4],
    'blocked tile' => [7, 3],
    'outside the map' => [40, 2],
]);

test('move coordinates are validated', function (array $body) {
    $this->actingAs($this->user)->withHeaders(worldHeaders($this->token))
        ->postJson(route('world.instances.move', $this->instance), $body)
        ->assertUnprocessable();
})->with([
    'missing y' => [['x' => 1]],
    'negative' => [['x' => -1, 'y' => 1]],
    'not a number' => [['x' => 'a', 'y' => 1]],
]);

test('redirecting mid-walk starts from where the walker actually is', function () {
    move(5, 1)->assertJsonPath('path', [[1, 1], [2, 1], [3, 1], [4, 1], [5, 1]]);

    // 375 ms at 4 tiles/s is 1.5 tiles: halfway between (2, 1) and (3, 1).
    $this->travel(375)->milliseconds();
    $now = now()->getTimestampMs();

    $response = move(3, 4);

    // The step already under way is finished first, so the new path is
    // backdated to when that step began (125 ms ago) and never jumps.
    $response->assertOk()
        ->assertJsonPath('path', [[2, 1], [3, 1], [3, 2], [3, 3], [3, 4]])
        ->assertJsonPath('started_at_ms', $now - 125);

    $stored = $this->positions->get($this->user->id);
    $position = $stored->positionAt($now);

    expect($position->x)->toEqualWithDelta(2.5, 1e-9)
        ->and($position->y)->toEqualWithDelta(1.0, 1e-9);
});

test('redirecting after the walk finished starts from the destination', function () {
    move(3, 1);
    $this->travel(2)->seconds();

    move(3, 3)->assertJsonPath('path', [[3, 1], [3, 2], [3, 3]])
        ->assertJsonPath('started_at_ms', now()->getTimestampMs());
});

test('redirecting exactly on a tile starts from that tile', function () {
    move(5, 1);
    $this->travel(500)->milliseconds();

    move(3, 3)->assertJsonPath('path', [[3, 1], [3, 2], [3, 3]])
        ->assertJsonPath('started_at_ms', now()->getTimestampMs());
});

test('a walk is timed so diagonal steps take sqrt(2) as long', function () {
    $path = move(3, 3)->json();

    expect($path['path'])->toBe([[1, 1], [2, 2], [3, 3]])
        ->and(PathClock::durationMs($path['path'], $path['speed']))->toEqualWithDelta(500 * M_SQRT2, 1e-6);
});

test('moves beyond the rate limit are dropped quietly', function () {
    foreach ([[2, 1], [3, 1], [4, 1], [5, 1], [6, 1]] as [$x, $y]) {
        move($x, $y)->assertOk();
    }

    move(1, 5)->assertStatus(202)->assertExactJson(['dropped' => true]);

    expect(last($this->positions->get($this->user->id)->path))->toBe([6, 1]);
    Event::assertDispatchedTimes(PlayerMoved::class, 5);

    $this->travel(1)->seconds();

    move(1, 5)->assertOk();
});

test('only members of the instance can move in it', function () {
    $other = $this->room->instances()->create(['number' => 2, 'is_open' => true]);

    $this->actingAs($this->user)->withHeaders(worldHeaders($this->token))
        ->postJson(route('world.instances.move', $other), ['x' => 2, 'y' => 2])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'not_in_room');
});

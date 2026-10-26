<?php

use App\Domain\Presence\InstanceMembers;
use App\Domain\World\PositionStore;
use App\Domain\World\StoredPath;
use App\Events\World\PlayerLeft;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    fakeWorldEvents();
    $this->lane = importRoom('test-lane');
    $this->residence = importRoom('test-home');
    $this->resident = resident();
    ['token' => $this->token, 'instance' => $this->instance] = enterWorld($this->resident, $this->lane);
});

function useExit(string $exit)
{
    return asResident(test()->resident, test()->token)
        ->postJson(route('world.instances.exit', ['instance' => test()->instance, 'exit' => $exit]));
}

test('walking through a door puts the resident at the arrival point of the room behind it', function () {
    standAt($this->resident, 5, 3);

    $response = useExit('home')->assertOk()
        ->assertJsonPath('protocol', 1)
        ->assertJsonPath('snapshot.room.slug', 'test-home')
        ->assertJsonPath('snapshot.room.kind', 'residence')
        ->assertJsonPath('snapshot.home.owner_id', $this->resident->id)
        // The "door" arrival point, not the spawn at (2, 2).
        ->assertJsonPath('snapshot.players.0.path', [[2, 3]]);

    $members = app(InstanceMembers::class);

    expect($members->contains($this->instance->id, $this->resident->id))->toBeFalse()
        ->and($members->contains($response->json('snapshot.instance.id'), $this->resident->id))->toBeTrue();

    Event::assertDispatched(PlayerLeft::class, fn (PlayerLeft $event): bool => $event->instanceId === $this->instance->id);
});

test('a building is walled off, and its door takes the resident inside', function () {
    ['token' => $this->token, 'instance' => $this->instance] = enterWorld($this->resident, importRoom('test-street'));
    $move = fn (int $x, int $y) => asResident($this->resident, $this->token)
        ->postJson(route('world.instances.move', $this->instance), ['x' => $x, 'y' => $y]);

    // The shed covers x 2–5, y 0–2, with its door at (3, 2).
    $move(4, 1)->assertStatus(422)->assertJsonPath('reason', 'unreachable');
    $move(3, 2)->assertOk();
    standAt($this->resident, 3, 2);

    useExit('shed')->assertOk()
        ->assertJsonPath('snapshot.room.slug', 'test-home')
        ->assertJsonPath('snapshot.players.0.path', [[2, 3]]);
});

test('a door one step away still opens, so a client a moment ahead of the server is not refused', function () {
    // Walking from (3, 3) to the door at (5, 3): one tile in, one to go.
    app(PositionStore::class)->put($this->resident->id, new StoredPath([[3, 3], [4, 3], [5, 3]], (int) now()->getTimestampMs() - 250, 4.0));

    useExit('home')->assertOk()->assertJsonPath('snapshot.room.slug', 'test-home');
});

test('a door is refused from across the room, or when the resident is walking elsewhere', function (Closure $place) {
    $place();

    useExit('home')->assertStatus(422)->assertJsonPath('reason', 'exit_too_far');

    expect(app(InstanceMembers::class)->contains($this->instance->id, $this->resident->id))->toBeTrue();
})->with([
    'across the room' => [fn () => standAt(test()->resident, 1, 1)],
    'next to it but heading away' => [fn () => app(PositionStore::class)->put(test()->resident->id, new StoredPath([[4, 3], [3, 3]], (int) now()->getTimestampMs(), 4.0))],
]);

test('doors to rooms that are not there, and doors that do not exist, are refused', function (string $exit) {
    standAt($this->resident, 0, 3);

    useExit($exit)->assertStatus(409)->assertJsonPath('reason', 'exit_closed');

    expect(app(InstanceMembers::class)->contains($this->instance->id, $this->resident->id))->toBeTrue();
})->with(['to a room never imported' => 'nowhere', 'unknown' => 'cellar']);

test('the import command imports every map when no slug is given, and fails on the broken ones', function () {
    $this->artisan('hushfield:import-map')
        ->expectsOutputToContain('(6×4, 1 spawns, 2 exits)')
        ->expectsOutputToContain('no-walkable: The map needs a tilelayer named "walkable".')
        ->assertFailed();
});

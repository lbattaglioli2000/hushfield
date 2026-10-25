<?php

use App\Actions\World\MovePlayer;
use Carbon\CarbonImmutable;

beforeEach(function () {
    fakeWorldEvents();
    $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00:00.000', 'UTC'));
    $this->room = importRoom();
});

test('the snapshot describes the instance and every player in it', function () {
    $me = resident();
    $other = resident();
    ['token' => $token, 'instance' => $instance] = enterWorld($me, $this->room);
    enterWorld($other, $this->room);
    $path = app(MovePlayer::class)($other, $instance, 4, 1);

    $this->travel(100)->milliseconds();

    $response = asResident($me, $token)->getJson(route('world.instances.snapshot', $instance));

    $response->assertOk()
        ->assertJsonPath('protocol', 1)
        ->assertJsonPath('server_time_ms', now()->getTimestampMs())
        ->assertJsonPath('instance.id', $instance->id)
        ->assertJsonPath('instance.number', 1)
        ->assertJsonPath('instance.channel', "room.{$instance->id}")
        ->assertJsonPath('room.slug', 'test-square')
        ->assertJsonPath('room.map_version', $this->room->map_version)
        ->assertJsonPath('room.map_url', route('world.maps.show', ['room' => 'test-square', 'version' => $this->room->map_version]))
        ->assertJsonCount(2, 'players');

    $players = collect($response->json('players'))->keyBy('id');

    expect($players[$me->id])->toMatchArray([
        'username' => $me->username,
        'avatar' => ['body_color' => $me->avatar->body_color, 'sprite_key' => 'resident'],
        'path' => [[1, 1]],
    ])
        ->and($players[$other->id])->toMatchArray([
            'username' => $other->username,
            'path' => $path->path,
            'started_at_ms' => $path->startedAtMs,
            'speed' => 4,
        ])
        ->and($players[$other->id]['path'])->toBe([[1, 1], [2, 1], [3, 1], [4, 1]])
        ->and($players[$me->id])->not->toHaveKeys(['email', 'birth_year', 'is_minor']);
});

test('the snapshot is only for members of the instance', function () {
    $outsider = resident();
    $token = startSession($outsider);
    ['instance' => $instance] = enterWorld(resident(), $this->room);

    asResident($outsider, $token)->getJson(route('world.instances.snapshot', $instance))
        ->assertStatus(409)
        ->assertJsonPath('reason', 'not_in_room');
});

test('the versioned map URL serves the imported file and 404s for any other version', function () {
    $this->get(route('world.maps.show', ['room' => 'test-square', 'version' => $this->room->map_version]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
        ->assertContent((string) file_get_contents(base_path('tests/fixtures/maps/test-square.json')));

    $this->get(route('world.maps.show', ['room' => 'test-square', 'version' => 'deadbeefdeadbeef']))->assertNotFound();
    $this->get(route('world.maps.show', ['room' => 'nowhere', 'version' => $this->room->map_version]))->assertNotFound();
});

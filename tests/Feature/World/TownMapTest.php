<?php

use App\Domain\Presence\InstanceMembers;
use App\Models\Room;

beforeEach(function () {
    fakeWorldEvents();
    $this->lane = importRoom('test-lane');
    $this->residence = importRoom('test-home');
    $this->resident = resident();
    ['token' => $this->token] = enterWorld($this->resident, $this->lane);
});

function townMap()
{
    return asResident(test()->resident, test()->token)->getJson(route('world.town'))->assertOk()->assertJsonPath('protocol', 2);
}

test('the town map lists every room with its place, the rooms its doors join, and who is in it, but no homes', function () {
    $this->lane->update(['frequency' => 99.1]);
    // Two more on the lane, one of them in its second instance, and three residents at home.
    enterWorld(resident(), $this->lane);
    $second = $this->lane->instances()->create(['number' => 2, 'is_open' => true]);
    app(InstanceMembers::class)->add($second->id, resident()->id);
    foreach (range(1, 3) as $ignored) {
        enterWorld(resident(), $this->residence);
    }
    Room::factory()->create(['slug' => 'closed-lane', 'is_active' => false]);

    townMap()->assertExactJson(['protocol' => 2, 'rooms' => [
        [
            'slug' => 'test-lane',
            'name' => 'Test Lane',
            'kind' => 'public',
            'frequency' => 99.1,
            'position' => ['x' => 1, 'y' => 0],
            // Its door to a room that was never imported goes nowhere on the map.
            'links' => ['test-home'],
            'occupants' => 3,
        ],
        [
            'slug' => 'test-home',
            'name' => 'Test Residences',
            'kind' => 'residence',
            'frequency' => null,
            'position' => ['x' => 1, 'y' => 1],
            'links' => ['test-lane'],
            // Who is at home is nobody else's business.
            'occupants' => null,
        ],
    ]]);
});

test('a door on one side is enough to join two rooms on the map', function () {
    // test-market has no doors; the lane's "nowhere" door is pointed at it instead.
    $map = json_decode((string) file_get_contents(base_path('tests/fixtures/maps/test-lane.json')), true);
    $map['layers'][3]['objects'][1]['properties'][0]['value'] = 'test-market';
    config(['hushfield.maps.path' => $dir = sys_get_temp_dir().'/hushfield-town-'.uniqid()]);
    mkdir($dir);
    file_put_contents("{$dir}/test-lane.json", json_encode($map));
    copy(base_path('tests/fixtures/maps/test-market.json'), "{$dir}/test-market.json");
    importRoom('test-lane');
    importRoom('test-market');

    $rooms = collect(townMap()->json('rooms'))->keyBy('slug');

    expect($rooms['test-market']['links'])->toBe(['test-lane'])
        ->and($rooms['test-lane']['links'])->toBe(['test-home', 'test-market']);

    array_map(unlink(...), glob("{$dir}/*.json"));
    rmdir($dir);
});

test('a room whose map has no town position is still listed, off the grid', function () {
    importRoom('test-square');

    $square = collect(townMap()->json('rooms'))->firstWhere('slug', 'test-square');

    expect($square)->toMatchArray(['name' => 'Test Square', 'position' => null, 'links' => [], 'occupants' => 0]);
});

test('the town map needs a world session', function () {
    $this->actingAs($this->resident)->getJson(route('world.town'))->assertStatus(409);
});

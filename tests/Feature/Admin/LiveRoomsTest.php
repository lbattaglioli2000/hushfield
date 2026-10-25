<?php

use App\Domain\Presence\InstanceMembers;
use App\Events\Presence\InstanceReassigned;
use App\Filament\Pages\LiveRooms;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    fakeWorldEvents();
    $this->room = importRoom();
    $this->one = $this->room->instances()->where('number', 1)->firstOrFail();
    $this->two = $this->room->instances()->create(['number' => 2, 'is_open' => true]);
    $this->members = app(InstanceMembers::class);
    $this->moderator = User::factory()->staff()->create();
    $this->actingAs($this->moderator);
});

test('the page shows live occupancy per room', function () {
    enterWorld(resident(), $this->room, $this->two);
    enterWorld(resident(), $this->room, $this->two);

    Livewire::test(LiveRooms::class)
        ->assertSee('Test Square')
        ->assertSee('2 players')
        ->assertSee('2 / 40');
});

test('closing an instance moves its players out', function () {
    enterWorld(resident(), $this->room, $this->two);

    Livewire::test(LiveRooms::class)
        ->callAction('closeInstance', arguments: ['instance' => $this->two->id])
        ->assertNotified('Instance closed. Moved 1 player.');

    expect($this->two->fresh()->is_open)->toBeFalse()
        ->and($this->members->count($this->two->id))->toBe(0)
        ->and(Activity::query()->where('event', 'instance.closed')->sole()->causer_id)->toBe($this->moderator->id);
    Event::assertDispatchedTimes(InstanceReassigned::class, 1);
});

test('closing an instance that is already closed changes nothing', function () {
    $this->two->update(['is_open' => false]);

    Livewire::test(LiveRooms::class)
        ->callAction('closeInstance', arguments: ['instance' => $this->two->id])
        ->assertNotified("{$this->two->label()} is already closed.");

    expect(Activity::query()->count())->toBe(0);
});

test('gathering moves everyone into instance 1', function () {
    $three = $this->room->instances()->create(['number' => 3, 'is_open' => true]);
    enterWorld(resident(), $this->room, $this->two);
    enterWorld(resident(), $this->room, $three);

    Livewire::test(LiveRooms::class)
        ->callAction('gather', arguments: ['room' => $this->room->id])
        ->assertNotified('Moved 2 players to instance 1.');

    expect($this->two->fresh()->is_open)->toBeFalse()
        ->and($three->fresh()->is_open)->toBeFalse()
        ->and($this->one->fresh()->is_open)->toBeTrue();
    Event::assertDispatched(InstanceReassigned::class, fn (InstanceReassigned $event): bool => $event->targetInstanceId === $this->one->id);
});

<?php

use App\Actions\World\JoinRoom;
use App\Domain\Housing\HomeVisitors;
use App\Domain\Items\InventorySource;
use App\Events\World\FurnitureChanged;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    fakeWorldEvents();
    importRoom('test-lane');
    $this->residence = importRoom('test-home');
    $this->moderator = User::factory()->staff()->create();
    $this->actingAs($this->moderator);
});

test('a moderator resets a home: furniture is put away in house and yard, the door is closed, and it is audited', function () {
    $owner = resident();
    enterWorld($owner, $this->residence);
    $home = $owner->home()->sole();
    $yard = app(JoinRoom::class)($owner, importRoom('test-yard'))->instance;
    $home->update(['visitors' => HomeVisitors::Everyone]);

    // Two of one chair, one in the house and one in the yard.
    $chair = Item::factory()->furniture()->create();

    foreach ([$home->instance, $yard] as $instance) {
        $owned = InventoryItem::query()->create(['user_id' => $owner->id, 'item_id' => $chair->id, 'source' => InventorySource::Purchase]);
        $home->furniture()->create(['room_instance_id' => $instance->id, 'inventory_item_id' => $owned->id, 'x' => 1, 'y' => 1]);
    }

    Livewire::test(ViewUser::class, ['record' => $owner->getRouteKey()])
        ->callAction('resetHome', data: ['reason' => 'Arranged into a slur'])
        ->assertHasNoActionErrors()
        ->assertNotified('Home reset');

    expect($home->fresh()->visitors)->toBe(HomeVisitors::Closed)
        ->and($home->furniture()->count())->toBe(0)
        ->and($owner->inventory()->count())->toBe(2)
        ->and(Activity::query()->where('event', 'home.reset')->sole()->properties['furniture_removed'])->toBe(2);

    foreach ([$home->room_instance_id, $yard->id] as $instanceId) {
        Event::assertDispatched(FurnitureChanged::class, fn (FurnitureChanged $event): bool => $event->broadcastOn()->name === "presence-room.{$instanceId}" && $event->furniture === []);
    }
});

test('there is nothing to reset for a resident who has not moved in', function () {
    Livewire::test(ViewUser::class, ['record' => resident()->getRouteKey()])
        ->assertActionHidden('resetHome');
});

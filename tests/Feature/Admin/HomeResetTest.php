<?php

use App\Domain\Items\InventorySource;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    fakeWorldEvents();
    importRoom('test-lane');
    $this->residence = importRoom('test-home');
    $this->moderator = User::factory()->staff()->create();
    $this->actingAs($this->moderator);
});

test('a moderator resets a home: furniture is put away, the door is closed, and it is audited', function () {
    $owner = resident();
    enterWorld($owner, $this->residence);
    $home = $owner->home()->sole();
    $home->update(['is_open' => true]);
    $owned = InventoryItem::query()->create(['user_id' => $owner->id, 'item_id' => Item::factory()->furniture()->create()->id, 'source' => InventorySource::Purchase]);
    $home->furniture()->create(['inventory_item_id' => $owned->id, 'x' => 1, 'y' => 1]);

    Livewire::test(ViewUser::class, ['record' => $owner->getRouteKey()])
        ->callAction('resetHome', data: ['reason' => 'Arranged into a slur'])
        ->assertHasNoActionErrors()
        ->assertNotified('Home reset');

    expect($home->fresh()->is_open)->toBeFalse()
        ->and($home->furniture()->count())->toBe(0)
        ->and($owner->inventory()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'home.reset')->sole()->properties['furniture_removed'])->toBe(1);
});

test('there is nothing to reset for a resident who has not moved in', function () {
    Livewire::test(ViewUser::class, ['record' => resident()->getRouteKey()])
        ->assertActionHidden('resetHome');
});

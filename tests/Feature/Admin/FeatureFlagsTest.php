<?php

use App\Domain\Identity\StaffRole;
use App\Features\ShopEnabled;
use App\Features\WorldFlag;
use App\Filament\Pages\FeatureFlags;
use App\Models\Item;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = User::factory()->staff(StaffRole::Admin)->create();
    $this->actingAs($this->admin);
});

test('an admin turns chat off and the change is audited', function () {
    Livewire::test(FeatureFlags::class)
        ->assertSee('Room joins')
        ->callAction('toggle', data: ['reason' => 'Raid in progress'], arguments: ['flag' => WorldFlag::Chat->value, 'active' => false])
        ->assertHasNoActionErrors()
        ->assertNotified('Chat is off');

    $entry = Activity::query()->sole();

    expect(WorldFlag::Chat->isActive())->toBeFalse()
        ->and(WorldFlag::Joins->isActive())->toBeTrue()
        ->and($entry->log_name)->toBe('flags')
        ->and($entry->event)->toBe('flag.disabled')
        ->and($entry->causer_id)->toBe($this->admin->id)
        ->and($entry->properties['reason'])->toBe('Raid in progress');
});

test('two admins turning the same flag off leave it off, with one audit entry', function () {
    // Both pages were rendered while chat was on, so both buttons ask for off.
    $first = Livewire::test(FeatureFlags::class);
    $second = Livewire::test(FeatureFlags::class);
    $off = ['flag' => WorldFlag::Chat->value, 'active' => false];

    $first->callAction('toggle', data: ['reason' => 'First'], arguments: $off);
    $second->callAction('toggle', data: ['reason' => 'Second'], arguments: $off);

    expect(WorldFlag::Chat->isActive())->toBeFalse()
        ->and(Activity::query()->count())->toBe(1);
});

test('turning a flag back on is audited as enabled', function () {
    $component = Livewire::test(FeatureFlags::class);

    $component->callAction('toggle', data: ['reason' => 'Pause'], arguments: ['flag' => WorldFlag::Joins->value, 'active' => false]);
    $component->callAction('toggle', data: ['reason' => 'Resume'], arguments: ['flag' => WorldFlag::Joins->value, 'active' => true]);

    expect(WorldFlag::Joins->isActive())->toBeTrue()
        ->and(Activity::query()->orderBy('id')->pluck('event')->all())->toBe(['flag.disabled', 'flag.enabled']);
});

test('unknown flags are not found', function () {
    Livewire::test(FeatureFlags::class)
        ->call('mountAction', 'toggle', ['flag' => 'App\\Models\\User', 'active' => false])
        ->assertNotFound();
});

test('an admin closes the shop from the Feature flags page, audited, and purchases stop', function () {
    Livewire::test(FeatureFlags::class)
        ->assertSee('Shop')
        ->callAction('toggle', data: ['reason' => 'Price bug'], arguments: ['flag' => WorldFlag::Shop->value, 'active' => false])
        ->assertHasNoActionErrors()
        ->assertNotified('Shop is off');

    $entry = Activity::query()->sole();

    expect(WorldFlag::Shop->isActive())->toBeFalse()
        ->and(WorldFlag::Chat->isActive())->toBeTrue()
        ->and($entry->event)->toBe('flag.disabled')
        ->and($entry->causer_id)->toBe($this->admin->id)
        ->and($entry->properties->all())->toBe(['flag' => ShopEnabled::class, 'active' => false, 'reason' => 'Price bug']);

    $resident = resident();
    $item = Item::factory()->create(['price' => 0]);

    asResident($resident, startSession($resident))->postJson(route('world.shop.purchase', $item))
        ->assertStatus(409)
        ->assertJsonPath('reason', 'shop_closed');
});

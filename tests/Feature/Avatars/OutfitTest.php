<?php

use App\Domain\Items\InventorySource;
use App\Domain\Items\ItemSlot;
use App\Events\World\OutfitChanged;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    fakeWorldEvents();
    $this->lane = importRoom('test-lane');
    $this->wren = resident(['username' => 'Wren']);
    ['token' => $this->token, 'instance' => $this->instance] = enterWorld($this->wren, $this->lane);
});

function wearable(User $owner, string $slug, ItemSlot $slot): InventoryItem
{
    $item = Item::factory()->create(['slug' => $slug, 'slot' => $slot]);

    return InventoryItem::query()->create(['user_id' => $owner->id, 'item_id' => $item->id, 'source' => InventorySource::Purchase]);
}

test('a resident puts things on, one per slot, takes them off, and the room sees each change', function () {
    $cap = wearable($this->wren, 'wool-cap', ItemSlot::Head);
    $beret = wearable($this->wren, 'beret', ItemSlot::Head);
    $boots = wearable($this->wren, 'quiet-boots', ItemSlot::Feet);
    $as = asResident($this->wren, $this->token);

    $as->postJson(route('world.outfit.wear', $cap))->assertOk()->assertJsonPath('wallet.worn', [$cap->id]);
    $as->postJson(route('world.outfit.wear', $boots))->assertOk();
    // A second hat replaces the first.
    $as->postJson(route('world.outfit.wear', $beret))->assertOk();

    expect(collect($as->getJson(route('world.wallet'))->json('wallet.worn'))->sort()->values()->all())->toBe([$beret->id, $boots->id]);

    $as->getJson(route('world.instances.snapshot', $this->instance))
        ->assertJsonPath('players.0.avatar.hair', 'short')
        ->assertJsonPath('players.0.avatar.worn.0.slug', 'quiet-boots')
        ->assertJsonPath('players.0.avatar.worn.1.slug', 'beret')
        ->assertJsonPath('players.0.avatar.worn.1.slot', 'head');

    $as->deleteJson(route('world.outfit.take-off', $boots))->assertOk()->assertJsonPath('wallet.worn', [$beret->id]);

    Event::assertDispatchedTimes(OutfitChanged::class, 4);
    Event::assertDispatched(OutfitChanged::class, fn (OutfitChanged $event): bool => $event->broadcastOn()->name === "presence-room.{$this->instance->id}"
        && $event->userId === $this->wren->id
        && array_column($event->avatar['worn'], 'slug') === ['beret']);
});

test('only an owned piece of something wearable can be put on', function () {
    $theirs = wearable(resident(), 'wool-cap', ItemSlot::Head);
    $chair = InventoryItem::query()->create(['user_id' => $this->wren->id, 'item_id' => Item::factory()->furniture()->create()->id, 'source' => InventorySource::Purchase]);

    foreach ([$theirs, $chair] as $piece) {
        asResident($this->wren, $this->token)->postJson(route('world.outfit.wear', $piece))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'not_wearable');
    }

    expect($this->wren->avatar->worn()->count())->toBe(0);
    Event::assertNotDispatched(OutfitChanged::class);
});

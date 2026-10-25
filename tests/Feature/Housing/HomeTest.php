<?php

use App\Actions\World\CloseEmptyInstances;
use App\Domain\Housing\HomeVisitors;
use App\Domain\Items\InventorySource;
use App\Domain\Presence\InstanceMembers;
use App\Events\Presence\InstanceReassigned;
use App\Events\World\FurnitureChanged;
use App\Models\Home;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    fakeWorldEvents();
    $this->lane = importRoom('test-lane');
    $this->residence = importRoom('test-home');
    $this->owner = resident(['username' => 'Wren']);
    ['token' => $this->ownerToken] = enterWorld($this->owner, $this->residence);
    $this->home = $this->owner->home()->sole();
    $this->members = app(InstanceMembers::class);
});

function visitHome(User $visitor, ?int $instanceId)
{
    return asResident($visitor, startSession($visitor))
        ->postJson(route('world.rooms.join', test()->residence), ['instance_id' => $instanceId]);
}

function own(User $user, Item $item): void
{
    InventoryItem::query()->create(['user_id' => $user->id, 'item_id' => $item->id, 'source' => InventorySource::Purchase]);
}

function placeFurniture(Item $item, int $x, int $y)
{
    return asResident(test()->owner, test()->ownerToken)
        ->postJson(route('world.home.furniture.place', $item), ['x' => $x, 'y' => $y]);
}

test('entering the residence takes a resident to their own home, created once', function () {
    $this->residence->update(['frequency' => 104.8, 'home_frequency' => 88.3]);
    $visitor = resident();

    $first = visitHome($visitor, null)->assertOk()
        ->assertJsonPath('snapshot.home.owner_id', $visitor->id)
        ->assertJsonPath('snapshot.home.visitors', 'closed')
        // Every home shares one station on the dial.
        ->assertJsonPath('snapshot.room.frequency', 88.3)
        ->assertJsonPath('snapshot.instance.label', "{$visitor->username}'s residence")
        ->assertJsonPath('snapshot.furniture', []);

    visitHome($visitor, null)->assertOk()->assertJsonPath('snapshot.instance.id', $first->json('snapshot.instance.id'));

    expect(Home::query()->count())->toBe(2)
        ->and($first->json('snapshot.instance.id'))->not->toBe($this->home->room_instance_id);
});

test('a closed home turns visitors away and leaves them where they were', function () {
    $visitor = resident();
    ['instance' => $lane, 'token' => $token] = enterWorld($visitor, $this->lane);

    asResident($visitor, $token)
        ->postJson(route('world.rooms.join', $this->residence), ['instance_id' => $this->home->room_instance_id])
        ->assertForbidden()
        ->assertJsonPath('reason', 'home_closed');

    expect($this->members->contains($lane->id, $visitor->id))->toBeTrue()
        ->and($this->members->contains($this->home->room_instance_id, $visitor->id))->toBeFalse()
        ->and($visitor->fresh()->home)->toBeNull();
});

test('an open home is listed and lets visitors in, up to its capacity', function () {
    asResident($this->owner, $this->ownerToken)->postJson(route('world.home.visitors'), ['visitors' => 'everyone'])
        ->assertOk()
        ->assertJsonPath('message', __('hushfield.home_opened'));

    $guest = resident();
    visitHome($guest, $this->home->room_instance_id)->assertOk()
        ->assertJsonPath('snapshot.home.owner', 'Wren')
        ->assertJsonPath('snapshot.players.*.id', fn (array $ids): bool => collect($ids)->sort()->values()->all() === [$this->owner->id, $guest->id]);

    asResident($guest, $guest->worldSession->session_token)->getJson(route('world.homes'))
        ->assertOk()
        ->assertJsonPath('residence', 'test-home')
        ->assertJsonPath('own', null)
        ->assertJsonPath('open.0.owner', 'Wren')
        ->assertJsonPath('open.0.friends_only', false)
        ->assertJsonPath('open.0.visitors', 2);

    // test-home holds 3: the owner, the guest, and one more.
    visitHome(resident(), $this->home->room_instance_id)->assertOk();
    visitHome(resident(), $this->home->room_instance_id)->assertStatus(409)->assertJsonPath('reason', 'home_full');
});

test('closing the door shows visitors out to the square and leaves the owner in', function () {
    $this->home->update(['visitors' => HomeVisitors::Everyone]);
    $guest = resident();
    visitHome($guest, $this->home->room_instance_id)->assertOk();

    asResident($this->owner, $this->ownerToken)->postJson(route('world.home.visitors'), ['visitors' => 'closed'])
        ->assertOk()
        ->assertJsonPath('own.visitors', 'closed');

    expect($this->members->all($this->home->room_instance_id))->toBe([$this->owner->id]);

    Event::assertDispatchedTimes(InstanceReassigned::class, 1);
    Event::assertDispatched(InstanceReassigned::class, fn (InstanceReassigned $event): bool => $event->sessionToken === $guest->worldSession->session_token
        && $event->broadcastWith()['room_slug'] === config('hushfield.world.default_room')
        && $event->broadcastWith()['message'] === __('hushfield.home_door_closed'));
});

test('owners place, move and put away their furniture, and everyone inside sees it', function () {
    $chair = Item::factory()->furniture()->create(['slug' => 'chair']);
    own($this->owner, $chair);

    placeFurniture($chair, 1, 1)->assertOk()
        ->assertJsonPath('furniture.0.item.slug', 'chair')
        ->assertJsonPath('furniture.0.x', 1);

    placeFurniture($chair, 3, 2)->assertOk()
        ->assertJsonCount(1, 'furniture')
        ->assertJsonPath('furniture.0.x', 3)
        ->assertJsonPath('furniture.0.y', 2);

    asResident($this->owner, $this->ownerToken)->getJson(route('world.instances.snapshot', $this->home->room_instance_id))
        ->assertJsonPath('furniture.0.item.slug', 'chair');

    asResident($this->owner, $this->ownerToken)->deleteJson(route('world.home.furniture.remove', $chair))
        ->assertOk()
        ->assertJsonPath('furniture', []);

    expect($this->owner->inventory()->count())->toBe(1);

    Event::assertDispatchedTimes(FurnitureChanged::class, 3);
    Event::assertDispatched(FurnitureChanged::class, fn (FurnitureChanged $event): bool => $event->broadcastOn()->name === "presence-room.{$this->home->room_instance_id}");
});

test('furniture is refused off the floor, on doors and arrivals, on top of other furniture, and when not owned or not furniture', function (Closure $item, int $x, int $y, string $reason) {
    $table = Item::factory()->furniture()->create();
    own($this->owner, $table);
    placeFurniture($table, 1, 1)->assertOk();

    placeFurniture($item(), $x, $y)->assertStatus(422)->assertJsonPath('reason', $reason);
})->with([
    'off the map' => [fn () => tap(Item::factory()->furniture()->create(), fn (Item $item) => own(test()->owner, $item)), 9, 9, 'furniture_blocked'],
    'on the door' => [fn () => tap(Item::factory()->furniture()->create(), fn (Item $item) => own(test()->owner, $item)), 2, 4, 'furniture_blocked'],
    'on the arrival point' => [fn () => tap(Item::factory()->furniture()->create(), fn (Item $item) => own(test()->owner, $item)), 2, 3, 'furniture_blocked'],
    'on another piece' => [fn () => tap(Item::factory()->furniture()->create(), fn (Item $item) => own(test()->owner, $item)), 1, 1, 'furniture_blocked'],
    'not owned' => [fn () => Item::factory()->furniture()->create(), 3, 3, 'not_furniture'],
    'a hat' => [fn () => tap(Item::factory()->create(['slot' => 'head']), fn (Item $item) => own(test()->owner, $item)), 3, 3, 'not_furniture'],
]);

test('empty homes are never closed by the instance sweep', function () {
    $this->members->remove($this->home->room_instance_id, $this->owner->id);
    $this->home->instance->forceFill(['empty_since' => now()->subDay()])->save();

    app(CloseEmptyInstances::class)();

    expect($this->home->instance->fresh()->is_open)->toBeTrue();
});

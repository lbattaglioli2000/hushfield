<?php

use App\Actions\World\CloseEmptyInstances;
use App\Domain\Housing\HomeVisitors;
use App\Domain\Items\InventorySource;
use App\Domain\Presence\InstanceMembers;
use App\Events\Presence\InstanceReassigned;
use App\Events\World\FurnitureChanged;
use App\Models\Home;
use App\Models\HomeFurniture;
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

function placeFurniture(Item $item, int $x, int $y, ?int $rotation = null)
{
    return asResident(test()->owner, test()->ownerToken)
        ->postJson(route('world.home.furniture.place', $item), array_filter(['x' => $x, 'y' => $y, 'rotation' => $rotation], fn ($value) => $value !== null));
}

/**
 * A furniture item the owner owns, wide × deep tiles.
 *
 * @param  array<string, mixed>  $attributes
 */
function ownedPiece(int $wide = 1, int $deep = 1, array $attributes = []): Item
{
    return tap(Item::factory()->furniture()->create(['wide' => $wide, 'deep' => $deep, ...$attributes]), fn (Item $item) => own(test()->owner, $item));
}

/** Walks the resident through a door of the room they are in, from the door's tile. */
function goThrough(User $user, string $exit, int $x, int $y)
{
    standAt($user, $x, $y);

    return asResident($user, $user->worldSession->session_token)
        ->postJson(route('world.instances.exit', ['instance' => $user->fresh()->last_room_instance_id, 'exit' => $exit]));
}

function walkTo(int $x, int $y)
{
    return asResident(test()->owner, test()->ownerToken)
        ->postJson(route('world.instances.move', test()->home->room_instance_id), ['x' => $x, 'y' => $y]);
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
    'off the map' => [fn () => tap(Item::factory()->furniture()->create(), fn (Item $item) => own(test()->owner, $item)), 9, 9, 'furniture_off_floor'],
    'on the door' => [fn () => tap(Item::factory()->furniture()->create(), fn (Item $item) => own(test()->owner, $item)), 2, 4, 'furniture_in_doorway'],
    'on the arrival point' => [fn () => tap(Item::factory()->furniture()->create(), fn (Item $item) => own(test()->owner, $item)), 2, 3, 'furniture_in_doorway'],
    'on another piece' => [fn () => tap(Item::factory()->furniture()->create(), fn (Item $item) => own(test()->owner, $item)), 1, 1, 'furniture_blocked'],
    'not owned' => [fn () => Item::factory()->furniture()->create(), 3, 3, 'not_furniture'],
    'a hat' => [fn () => tap(Item::factory()->create(['slot' => 'head']), fn (Item $item) => own(test()->owner, $item)), 3, 3, 'not_furniture'],
]);

// test-home is 5 × 5 tiles, all floor. The door is at (2, 4), its arrival point at (2, 3), and the owner stands at the spawn (2, 2).
// Its back door to the yard is at (4, 3), its arrival point at (3, 3). test-yard is 4 × 4, all grass, with its door back in at (0, 2).

test('a piece covers every tile of its footprint, and nothing else may stand on any of them', function () {
    $seat = ownedPiece(2, 1);

    placeFurniture($seat, 0, 0)->assertOk()
        ->assertJsonPath('furniture.0.rotation', 0)
        ->assertJsonPath('furniture.0.item.furniture', ['wide' => 2, 'deep' => 1, 'height' => 18, 'floor' => false, 'shape' => null]);

    placeFurniture(ownedPiece(), 1, 0)->assertStatus(422)->assertJsonPath('reason', 'furniture_blocked');
    // A 2 × 2 piece whose back corner is clear but whose other tiles are not.
    placeFurniture(ownedPiece(2, 2), 0, 1)->assertOk();
    placeFurniture(ownedPiece(2, 1), 3, 0)->assertOk();
    // Its far tile would be off the floor.
    placeFurniture(ownedPiece(2, 1), 4, 1)->assertStatus(422)->assertJsonPath('reason', 'furniture_off_floor');
    // Its far tile would be the arrival point.
    placeFurniture(ownedPiece(2, 1), 1, 3)->assertStatus(422)->assertJsonPath('reason', 'furniture_in_doorway');
});

test('a quarter turn swaps how wide and how deep a piece is, and moving it keeps its turn', function () {
    $seat = ownedPiece(2, 1);
    $chair = ownedPiece();

    placeFurniture($seat, 0, 0, 1)->assertOk()->assertJsonPath('furniture.0.rotation', 1);

    // Turned, it covers (0, 0) and (0, 1) instead of (0, 0) and (1, 0).
    placeFurniture($chair, 0, 1)->assertStatus(422)->assertJsonPath('reason', 'furniture_blocked');
    placeFurniture($chair, 1, 0)->assertOk();

    // Along the east edge it only fits turned: unturned, its far tile is off the floor.
    placeFurniture($seat, 4, 0, 2)->assertStatus(422)->assertJsonPath('reason', 'furniture_off_floor');
    placeFurniture($seat, 4, 0)->assertOk()->assertJsonPath('furniture.0.rotation', 1);
    placeFurniture($seat, 4, 0, 3)->assertOk()->assertJsonPath('furniture.0.rotation', 3);

    expect(HomeFurniture::query()->where('x', 4)->sole()->rotation)->toBe(3);

    // A turn the server cannot have.
    placeFurniture($seat, 0, 0, 4)->assertUnprocessable()->assertJsonValidationErrors('rotation');
});

test('a shaped piece covers only its filled tiles: its empty corner stays open floor', function () {
    $sofa = ownedPiece(2, 2, ['shape' => ['##', '#.']]);

    placeFurniture($sofa, 0, 0)->assertOk()
        ->assertJsonPath('furniture.0.item.furniture.shape', ['##', '#.']);

    walkTo(1, 0)->assertStatus(422)->assertJsonPath('reason', 'unreachable');
    walkTo(1, 1)->assertOk();
    walkTo(3, 3)->assertOk();

    placeFurniture(ownedPiece(), 1, 0)->assertStatus(422)->assertJsonPath('reason', 'furniture_blocked');
    placeFurniture(ownedPiece(), 0, 1)->assertStatus(422)->assertJsonPath('reason', 'furniture_blocked');
    // A side table tucks into the corner.
    placeFurniture(ownedPiece(), 1, 1)->assertOk();
});

test('a quarter turn turns a piece\'s shape with it', function () {
    $sofa = ownedPiece(2, 2, ['shape' => ['##', '#.']]);
    $table = ownedPiece();

    // Turned once it is ## over .#, so its empty corner is (0, 1), not (1, 1).
    placeFurniture($sofa, 0, 0, 1)->assertOk();
    placeFurniture($table, 1, 1)->assertStatus(422)->assertJsonPath('reason', 'furniture_blocked');
    placeFurniture($table, 0, 1)->assertOk();

    // Turned three times it is #. over ##, onto the table; with the table moved, its empty corner is (1, 0).
    placeFurniture($sofa, 0, 0, 3)->assertStatus(422)->assertJsonPath('reason', 'furniture_blocked');
    placeFurniture($table, 4, 0)->assertOk();
    placeFurniture($sofa, 0, 0, 3)->assertOk();
    placeFurniture($table, 0, 1)->assertStatus(422)->assertJsonPath('reason', 'furniture_blocked');
    placeFurniture($table, 1, 0)->assertOk();

    // Turned twice it is .# over ##: its empty corner would be (0, 0), walled in.
    placeFurniture($table, 4, 0)->assertOk();
    placeFurniture($sofa, 0, 0, 2)->assertStatus(422)->assertJsonPath('reason', 'furniture_cuts_off');
});

test('players walk around furniture, never through it or onto it', function () {
    placeFurniture(ownedPiece(1, 3), 1, 0)->assertOk();

    walkTo(1, 1)->assertStatus(422)->assertJsonPath('reason', 'unreachable');

    // From (2, 2) to (0, 0) the straight way is through the piece at x = 1, rows 0–2.
    $path = walkTo(0, 0)->assertOk()->json('path');

    expect($path[0])->toBe([2, 2])
        ->and(end($path))->toBe([0, 0])
        ->and(collect($path)->filter(fn (array $tile): bool => $tile[0] === 1 && $tile[1] <= 2)->all())->toBe([]);
});

test('rugs are walked over, and carry one other piece', function () {
    $rug = ownedPiece(2, 2, ['floor' => true]);
    $chair = ownedPiece();

    placeFurniture($rug, 0, 0)->assertOk();
    walkTo(1, 1)->assertOk();

    placeFurniture($chair, 1, 1)->assertOk();
    placeFurniture(ownedPiece(), 0, 0)->assertStatus(422)->assertJsonPath('reason', 'furniture_blocked');
    placeFurniture(ownedPiece(2, 2, ['floor' => true]), 1, 1)->assertStatus(422)->assertJsonPath('reason', 'furniture_blocked');

    // Slid under two pieces at once, it would carry both.
    placeFurniture(ownedPiece(), 3, 0)->assertOk();
    placeFurniture(ownedPiece(), 3, 1)->assertOk();
    placeFurniture($rug, 2, 0)->assertStatus(422)->assertJsonPath('reason', 'furniture_blocked');
    placeFurniture($rug, 0, 1)->assertOk();

    // The rug is still walkable; the chair on it is not.
    walkTo(0, 2)->assertOk();
    walkTo(1, 1)->assertStatus(422)->assertJsonPath('reason', 'unreachable');
});

test('nothing may cut part of the home off from the door', function () {
    placeFurniture(ownedPiece(), 1, 0)->assertOk();

    // Walls in (0, 0): diagonal steps never squeeze between two pieces.
    placeFurniture(ownedPiece(), 0, 1)->assertStatus(422)->assertJsonPath('reason', 'furniture_cuts_off');
    // A wall right across the room, between the door and the back.
    placeFurniture(ownedPiece(5, 1), 0, 2)->assertStatus(422)->assertJsonPath('reason', 'furniture_cuts_off');
    // A rug walls nothing off.
    placeFurniture(ownedPiece(1, 1, ['floor' => true]), 0, 1)->assertOk();
});

test('a piece may land on someone only if they can still walk off it', function () {
    $visitor = resident();
    $this->home->update(['visitors' => HomeVisitors::Everyone]);
    visitHome($visitor, $this->home->room_instance_id)->assertOk();

    // Standing in the corner a bed would fill, with nowhere to step.
    standAt($visitor, 0, 0);
    placeFurniture(ownedPiece(2, 2), 0, 0)->assertStatus(422)->assertJsonPath('reason', 'furniture_traps');

    // Standing at its front corner, with open floor beside them.
    standAt($visitor, 1, 1);
    placeFurniture(ownedPiece(2, 2), 0, 0)->assertOk();

    asResident($visitor, $visitor->worldSession->session_token)
        ->postJson(route('world.instances.move', $this->home->room_instance_id), ['x' => 3, 'y' => 1])
        ->assertOk()
        ->assertJsonPath('path.0', [1, 1]);
});

test('a home holds a limited number of pieces, but those placed can still be moved', function () {
    config(['hushfield.housing.max_furniture' => 2]);
    $first = ownedPiece();
    placeFurniture($first, 0, 0)->assertOk();
    placeFurniture(ownedPiece(), 4, 0)->assertOk();

    placeFurniture(ownedPiece(), 0, 4)->assertStatus(409)->assertJsonPath('reason', 'furniture_full');
    placeFurniture($first, 4, 4)->assertOk();
});

test('existing placements become one tile, unturned', function () {
    $chair = ownedPiece();
    placeFurniture($chair, 3, 1)->assertOk();

    expect(HomeFurniture::query()->sole()->only(['x', 'y', 'rotation']))->toBe(['x' => 3, 'y' => 1, 'rotation' => 0])
        ->and(Item::factory()->furniture()->create()->fresh()->only(['wide', 'deep', 'floor']))->toBe(['wide' => 1, 'deep' => 1, 'floor' => false]);
});

test('empty homes are never closed by the instance sweep', function () {
    $this->members->remove($this->home->room_instance_id, $this->owner->id);
    $this->home->instance->forceFill(['empty_since' => now()->subDay()])->save();

    app(CloseEmptyInstances::class)();

    expect($this->home->instance->fresh()->is_open)->toBeTrue();
});

test('the back door leads to the yard of the home you are in, and its door back into that same house', function () {
    importRoom('test-yard');
    $this->home->update(['visitors' => HomeVisitors::Everyone]);
    $guest = resident(['username' => 'Moth']);
    visitHome($guest, null)->assertOk()->assertJsonPath('snapshot.home.owner', 'Moth');
    asResident($guest, $guest->worldSession->session_token)
        ->postJson(route('world.rooms.join', $this->residence), ['instance_id' => $this->home->room_instance_id])
        ->assertOk();

    $own = goThrough($this->owner, 'yard', 4, 3)->assertOk()
        ->assertJsonPath('snapshot.room.slug', 'test-yard')
        ->assertJsonPath('snapshot.room.kind', 'yard')
        ->assertJsonPath('snapshot.room.frequency', 88.3)
        ->assertJsonPath('snapshot.home.owner_id', $this->owner->id)
        ->assertJsonPath('snapshot.instance.label', "Wren's yard")
        ->assertJsonPath('snapshot.players.0.path', [[1, 2]]);

    // The guest has a home of their own, but the back door of Wren's house leads to Wren's yard.
    goThrough($guest, 'yard', 4, 3)->assertOk()
        ->assertJsonPath('snapshot.instance.id', $own->json('snapshot.instance.id'))
        ->assertJsonPath('snapshot.home.owner', 'Wren');

    goThrough($guest, 'house', 0, 2)->assertOk()
        ->assertJsonPath('snapshot.instance.id', $this->home->room_instance_id)
        ->assertJsonPath('snapshot.home.owner', 'Wren');

    expect($this->home->instances()->count())->toBe(2)
        ->and($this->members->contains($own->json('snapshot.instance.id'), $this->owner->id))->toBeTrue();
});

test('the yard follows the home\'s visitor setting', function () {
    $yardRoom = importRoom('test-yard');
    $yard = goThrough($this->owner, 'yard', 4, 3)->assertOk()->json('snapshot.instance.id');
    $stranger = resident();

    // A closed home's yard is closed too, however it is asked for.
    foreach ([$this->home->room_instance_id, $yard] as $instanceId) {
        asResident($stranger, startSession($stranger))
            ->postJson(route('world.rooms.join', $yardRoom), ['instance_id' => $instanceId])
            ->assertForbidden()
            ->assertJsonPath('reason', 'home_closed');
    }

    // Opened, a guest walks out to it; closed again, they are shown out of the yard as well.
    $this->home->update(['visitors' => HomeVisitors::Everyone]);
    $guest = resident();
    visitHome($guest, $this->home->room_instance_id)->assertOk();
    goThrough($guest, 'yard', 4, 3)->assertOk()->assertJsonPath('snapshot.instance.id', $yard);

    asResident($this->owner, $this->ownerToken)->postJson(route('world.home.visitors'), ['visitors' => 'closed'])->assertOk();

    expect($this->members->all($yard))->toBe([$this->owner->id]);
});

test('furniture placed in the yard stays in the yard', function () {
    importRoom('test-yard');
    $chair = ownedPiece(attributes: ['slug' => 'chair']);
    $fern = ownedPiece(attributes: ['slug' => 'fern']);
    placeFurniture($chair, 1, 1)->assertOk();

    $yard = goThrough($this->owner, 'yard', 4, 3)->assertOk()->json('snapshot.instance.id');

    // Out here the floor is the yard's: the yard's own door is refused, the house's back door tile is not.
    placeFurniture($fern, 0, 2)->assertStatus(422)->assertJsonPath('reason', 'furniture_in_doorway');
    placeFurniture($fern, 3, 3)->assertOk()->assertJsonCount(1, 'furniture')->assertJsonPath('furniture.0.item.slug', 'fern');

    asResident($this->owner, $this->ownerToken)->getJson(route('world.instances.snapshot', $yard))
        ->assertJsonPath('furniture', fn (array $placed): bool => collect($placed)->pluck('item.slug')->all() === ['fern']);

    expect(collect($this->home->instance->furniturePayload())->pluck('item.slug')->all())->toBe(['chair'])
        ->and(HomeFurniture::query()->whereRelation('inventoryItem', 'item_id', $fern->id)->sole()->room_instance_id)->toBe($yard)
        ->and(HomeFurniture::query()->whereRelation('inventoryItem', 'item_id', $chair->id)->sole()->room_instance_id)->toBe($this->home->room_instance_id);

    // House and yard share one allowance.
    config(['hushfield.housing.max_furniture' => 2]);
    placeFurniture(ownedPiece(), 2, 2)->assertStatus(409)->assertJsonPath('reason', 'furniture_full');

    // Carried out of the house, the chair leaves it.
    placeFurniture($chair, 2, 0)->assertOk()->assertJsonCount(2, 'furniture');
    expect($this->home->instance->furniture()->count())->toBe(0);
    Event::assertDispatched(FurnitureChanged::class, fn (FurnitureChanged $event): bool => $event->broadcastOn()->name === "presence-room.{$this->home->room_instance_id}" && $event->furniture === []);
});

<?php

use App\Actions\Economy\RecordLedgerEntry;
use App\Actions\World\JoinRoom;
use App\Domain\Economy\LedgerReason;
use App\Domain\Economy\Wallet;
use App\Domain\Items\InventorySource;
use App\Features\ShopEnabled;
use App\Features\WorldFlags;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\LedgerEntry;
use App\Models\User;

beforeEach(function () {
    fakeWorldEvents();
    // The shop only trades at a counter: test-market has one, test-square does not.
    $this->market = importRoom('test-market');
    $this->resident = resident();
    ['token' => $this->token] = enterWorld($this->resident, $this->market);
});

function giveCoins(User $user, int $amount): void
{
    app(RecordLedgerEntry::class)($user, $amount, LedgerReason::StaffAdjustment, 'test:'.Str::uuid());
}

function buy(Item $item)
{
    return asResident(test()->resident, test()->token)->postJson(route('world.shop.purchase', $item));
}

test('the shop lists only items on sale', function () {
    $hat = Item::factory()->create(['name' => 'Moth Hat', 'price' => 30]);
    $locked = Item::factory()->membersOnly()->create(['price' => 10]);
    Item::factory()->notInShop()->create();

    asResident($this->resident, $this->token)->getJson(route('world.shop'))
        ->assertOk()
        ->assertJsonPath('protocol', 1)
        ->assertJsonPath('open', true)
        ->assertJsonPath('items.*.id', [$locked->id, $hat->id])
        ->assertJsonPath('items.1.name', 'Moth Hat')
        ->assertJsonPath('items.0.members_only', true);
});

test('buying an item charges its price and adds it to the inventory', function () {
    giveCoins($this->resident, 80);
    $hat = Item::factory()->create(['price' => 30]);

    buy($hat)
        ->assertOk()
        ->assertJsonPath('message', __('hushfield.purchase_complete'))
        ->assertJsonPath('wallet.balance', 50)
        ->assertJsonPath('wallet.inventory.0.id', $hat->id);

    $owned = InventoryItem::query()->sole();
    $debit = LedgerEntry::query()->where('reason', LedgerReason::Purchase)->sole();

    expect($owned->source)->toBe(InventorySource::Purchase)
        ->and($owned->ledger_entry_id)->toBe($debit->id)
        ->and($debit->amount)->toBe(-30)
        ->and($debit->item_id)->toBe($hat->id);
});

test('a repeated purchase is charged once', function () {
    giveCoins($this->resident, 100);
    $hat = Item::factory()->create(['price' => 30]);

    buy($hat)->assertOk()->assertJsonPath('wallet.balance', 70);
    buy($hat)->assertOk()->assertJsonPath('wallet.balance', 70);

    expect(InventoryItem::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->where('reason', LedgerReason::Purchase)->count())->toBe(1);
});

test('a resident cannot spend more than they have', function () {
    giveCoins($this->resident, 29);
    $hat = Item::factory()->create(['price' => 30]);

    buy($hat)
        ->assertStatus(422)
        ->assertJsonPath('reason', 'insufficient_coins')
        ->assertJsonPath('message', __('hushfield.insufficient_coins'));

    expect(InventoryItem::query()->count())->toBe(0)
        ->and(app(Wallet::class)->balance($this->resident))->toBe(29);
});

test('spending exactly the whole balance is allowed', function () {
    giveCoins($this->resident, 30);

    buy(Item::factory()->create(['price' => 30]))->assertOk()->assertJsonPath('wallet.balance', 0);
});

test('an item already granted by staff cannot be bought, and nothing is charged', function () {
    giveCoins($this->resident, 100);
    $hat = Item::factory()->create(['price' => 30]);
    InventoryItem::query()->create(['user_id' => $this->resident->id, 'item_id' => $hat->id, 'source' => InventorySource::StaffGrant]);

    buy($hat)->assertStatus(409)->assertJsonPath('reason', 'item_owned');

    expect(app(Wallet::class)->balance($this->resident))->toBe(100);
});

test('items off the shelf or for members only cannot be bought', function (Closure $item, int $status, string $reason) {
    giveCoins($this->resident, 100);

    buy($item())->assertStatus($status)->assertJsonPath('reason', $reason);

    expect(InventoryItem::query()->count())->toBe(0)
        ->and(app(Wallet::class)->balance($this->resident))->toBe(100);
})->with([
    'not in the shop' => [fn () => Item::factory()->notInShop()->create(), 422, 'item_unavailable'],
    'members only' => [fn () => Item::factory()->membersOnly()->create(), 403, 'item_members_only'],
]);

test('while the shop is switched off nothing can be bought, but the shelves, belongings and stipend remain', function () {
    giveCoins($this->resident, 100);
    $owned = Item::factory()->create(['price' => 10]);
    $hat = Item::factory()->create(['price' => 30]);
    buy($owned)->assertOk();

    WorldFlags::set(ShopEnabled::class, false);

    buy($hat)
        ->assertStatus(409)
        ->assertJsonPath('protocol', 1)
        ->assertJsonPath('reason', 'shop_closed')
        ->assertJsonPath('message', __('hushfield.shop_closed'));

    // A replay of a purchase that already went through is refused too, and still charges nothing.
    buy($owned)->assertStatus(409)->assertJsonPath('reason', 'shop_closed');

    asResident($this->resident, $this->token)->getJson(route('world.shop'))
        ->assertOk()
        ->assertJsonPath('open', false)
        ->assertJsonCount(2, 'items');

    asResident($this->resident, $this->token)->getJson(route('world.wallet'))
        ->assertOk()
        ->assertJsonPath('wallet.balance', 90)
        ->assertJsonPath('wallet.inventory.*.id', [$owned->id]);

    asResident($this->resident, $this->token)->postJson(route('world.stipend.claim'))->assertCreated();

    WorldFlags::set(ShopEnabled::class, true);

    buy($hat)->assertOk()->assertJsonPath('wallet.balance', 85);
});

test('the shop only lists and sells to residents in a room with a shop counter', function () {
    giveCoins($this->resident, 100);
    $hat = Item::factory()->create(['price' => 30]);
    app(JoinRoom::class)($this->resident, importRoom('test-square'));

    asResident($this->resident, $this->token)->getJson(route('world.shop'))
        ->assertStatus(409)
        ->assertJsonPath('reason', 'shop_elsewhere')
        ->assertJsonPath('message', __('hushfield.shop_elsewhere'));
    buy($hat)->assertStatus(409)->assertJsonPath('reason', 'shop_elsewhere');

    expect(InventoryItem::query()->count())->toBe(0)
        ->and(app(Wallet::class)->balance($this->resident))->toBe(100);

    // Back at the market counter, the same purchase goes through.
    app(JoinRoom::class)($this->resident, $this->market);

    asResident($this->resident, $this->token)->getJson(route('world.shop'))->assertOk()->assertJsonCount(1, 'items');
    buy($hat)->assertOk()->assertJsonPath('wallet.balance', 70);
});

test('a resident whose session has no room cannot reach the shop', function () {
    $wanderer = resident();
    giveCoins($wanderer, 100);

    asResident($wanderer, startSession($wanderer))
        ->postJson(route('world.shop.purchase', Item::factory()->create(['price' => 30])))
        ->assertStatus(409)
        ->assertJsonPath('reason', 'shop_elsewhere');
});

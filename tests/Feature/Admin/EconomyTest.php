<?php

use App\Actions\Economy\RecordLedgerEntry;
use App\Domain\Economy\LedgerReason;
use App\Domain\Economy\Wallet;
use App\Domain\Identity\StaffRole;
use App\Domain\Items\InventorySource;
use App\Domain\Items\ItemRarity;
use App\Domain\Items\ItemSlot;
use App\Filament\Resources\Items\Pages\CreateItem;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\LedgerEntries\Pages\ListLedgerEntries;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\RelationManagers\InventoryRelationManager;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\LedgerEntry;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = User::factory()->staff(StaffRole::Admin)->create();
    $this->actingAs($this->admin);
    $this->player = resident();
});

function viewPlayer()
{
    return Livewire::test(ViewUser::class, ['record' => test()->player->getRouteKey()]);
}

test('an admin grants coins with a reason that is written to the ledger and the audit log', function () {
    viewPlayer()
        ->callAction('adjustCoins', data: ['amount' => 40, 'reason' => 'Lost in the outage'])
        ->assertHasNoActionErrors()
        ->assertNotified('Coins adjusted');

    $entry = LedgerEntry::query()->sole();

    expect(app(Wallet::class)->balance($this->player))->toBe(40)
        ->and($entry->reason)->toBe(LedgerReason::StaffAdjustment)
        ->and($entry->actor_id)->toBe($this->admin->id)
        ->and($entry->note)->toBe('Lost in the outage')
        ->and(Activity::query()->where('event', 'coins.granted')->sole()->properties['ledger_entry_id'])->toBe($entry->id);
});

test('removing more coins than a resident has is refused and changes nothing', function () {
    app(RecordLedgerEntry::class)($this->player, 10, LedgerReason::Stipend, 'stipend:test');

    viewPlayer()
        ->callAction('adjustCoins', data: ['amount' => -11, 'reason' => 'Correction'])
        ->assertNotified('That would take the balance below zero. It is 10.');

    expect(app(Wallet::class)->balance($this->player))->toBe(10)
        ->and(LedgerEntry::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'coins.removed')->exists())->toBeFalse();
});

test('an adjustment of zero coins is rejected by the form', function () {
    viewPlayer()
        ->callAction('adjustCoins', data: ['amount' => 0, 'reason' => 'Nothing'])
        ->assertHasActionErrors(['amount']);

    expect(LedgerEntry::query()->count())->toBe(0);
});

test('an admin grants an item that is not in the shop', function () {
    $cloak = Item::factory()->notInShop()->create();

    viewPlayer()
        ->callAction('grantItem', data: ['item_id' => $cloak->id, 'reason' => 'Event prize'])
        ->assertHasNoActionErrors()
        ->assertNotified('Item granted');

    $owned = InventoryItem::query()->sole();

    expect($owned->user_id)->toBe($this->player->id)
        ->and($owned->source)->toBe(InventorySource::StaffGrant)
        ->and($owned->granted_by)->toBe($this->admin->id)
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'item.granted')->sole()->properties['reason'])->toBe('Event prize');

    Livewire::test(InventoryRelationManager::class, ['ownerRecord' => $this->player, 'pageClass' => ViewUser::class])
        ->assertCanSeeTableRecords([$owned]);
});

test('an item the resident owns cannot be granted again', function () {
    $hat = Item::factory()->create();
    InventoryItem::query()->create(['user_id' => $this->player->id, 'item_id' => $hat->id, 'source' => InventorySource::Purchase]);

    viewPlayer()
        ->callAction('grantItem', data: ['item_id' => $hat->id, 'reason' => 'Again'])
        ->assertHasActionErrors(['item_id']);

    expect(InventoryItem::query()->count())->toBe(1);
});

test('moderators and admins on their own page cannot move coins or items', function (Closure $as, Closure $record) {
    $this->actingAs($as());

    Livewire::test(ViewUser::class, ['record' => $record()->getRouteKey()])
        ->assertActionHidden('adjustCoins')
        ->assertActionHidden('grantItem');
})->with([
    'moderator' => [fn () => User::factory()->staff()->create(), fn () => test()->player],
    'admin on themselves' => [fn () => test()->admin, fn () => test()->admin],
]);

test('moderators cannot reach the catalog or the ledger', function () {
    $this->actingAs(User::factory()->staff()->create());

    Livewire::test(ListLedgerEntries::class)->assertForbidden();
    Livewire::test(CreateItem::class)->assertForbidden();
});

test('an admin adds an item to the catalog', function () {
    Livewire::test(CreateItem::class)
        ->fillForm([
            'name' => 'Velvet Hat',
            'slug' => 'velvet-hat',
            'slot' => ItemSlot::Head->value,
            'rarity' => ItemRarity::Rare->value,
            'price' => 75,
            'in_shop' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $item = Item::query()->sole();

    expect($item->slot)->toBe(ItemSlot::Head)
        ->and($item->price)->toBe(75)
        ->and($item->in_shop)->toBeTrue();
});

test('an admin sizes a piece of furniture and marks it as a rug', function () {
    $rug = Item::factory()->furniture()->create();

    Livewire::test(EditItem::class, ['record' => $rug->getRouteKey()])
        ->assertFormFieldIsVisible('wide')
        ->fillForm(['wide' => 3, 'deep' => 2, 'height' => 2, 'floor' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rug->fresh()->only(['wide', 'deep', 'height', 'floor']))->toBe(['wide' => 3, 'deep' => 2, 'height' => 2, 'floor' => true]);

    Livewire::test(EditItem::class, ['record' => Item::factory()->create(['slot' => ItemSlot::Head])->getRouteKey()])
        ->assertFormFieldIsHidden('wide');
});

test('an admin shapes a piece of furniture tile by tile', function () {
    $sofa = Item::factory()->furniture()->create(['wide' => 2, 'deep' => 2]);

    Livewire::test(EditItem::class, ['record' => $sofa->getRouteKey()])
        ->assertFormSet(['shape' => ['0,0', '1,0', '0,1', '1,1']])
        ->fillForm(['shape' => ['0,0', '1,0', '0,1']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($sofa->fresh()->shape)->toBe(['##', '#.']);

    Livewire::test(EditItem::class, ['record' => $sofa->getRouteKey()])
        ->assertFormSet(['shape' => ['0,0', '1,0', '0,1']])
        ->fillForm(['shape' => ['0,0', '1,0', '0,1', '1,1']])
        ->call('save')
        ->assertHasNoFormErrors();

    // Every tile ticked is a plain block.
    expect($sofa->fresh()->shape)->toBeNull();
});

test('a shape that is not one connected piece filling its block is refused', function (array $ticked) {
    $piece = Item::factory()->furniture()->create(['wide' => 3, 'deep' => 2]);

    Livewire::test(EditItem::class, ['record' => $piece->getRouteKey()])
        ->fillForm(['shape' => $ticked])
        ->call('save')
        ->assertHasFormErrors(['shape']);

    expect($piece->fresh()->shape)->toBeNull();
})->with([
    'no tiles' => [[]],
    'two pieces' => [['0,0', '0,1', '2,0', '2,1']],
    'joined only at a corner' => [['0,0', '1,1', '2,0']],
    'an empty last column' => [['0,0', '1,0', '0,1', '1,1']],
    'an empty back row' => [['0,1', '1,1', '2,1']],
]);

test('items cannot be deleted once in the catalog', function () {
    $item = Item::factory()->create();

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->assertActionDoesNotExist('delete');

    expect($this->admin->can('delete', $item))->toBeFalse();
});

test('the ledger lists every movement', function () {
    app(RecordLedgerEntry::class)($this->player, 25, LedgerReason::Stipend, 'stipend:a');
    app(RecordLedgerEntry::class)($this->player, -5, LedgerReason::Purchase, 'purchase:a');

    Livewire::test(ListLedgerEntries::class)
        ->assertCanSeeTableRecords(LedgerEntry::query()->get())
        ->filterTable('reason', LedgerReason::Purchase->value)
        ->assertCountTableRecords(1);
});

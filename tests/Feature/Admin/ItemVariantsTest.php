<?php

use App\Actions\Items\AddItemVariant;
use App\Domain\Identity\StaffRole;
use App\Domain\Items\ItemSlot;
use App\Exceptions\ModerationActionRefused;
use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\RelationManagers\VariantsRelationManager;
use App\Models\Item;
use App\Models\User;
use Database\Seeders\ItemSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->staff(StaffRole::Admin)->create();
    $this->actingAs($this->admin);
    $this->seat = Item::factory()->furniture()->create(['slug' => 'love-seat', 'wide' => 2, 'deep' => 2, 'height' => 26, 'shape' => ['##', '#.'], 'price' => 80]);
});

test('a colour stands on its parent\'s tiles whatever it is given, and follows the parent when it is resized', function () {
    $moss = Item::factory()->create(['variant_of' => $this->seat->id, 'slot' => ItemSlot::Head, 'wide' => 5, 'deep' => 1, 'shape' => null, 'price' => 95]);

    expect($moss->fresh()->only(Item::INHERITED))->toEqual($this->seat->only(Item::INHERITED))
        ->and($moss->fresh()->price)->toBe(95);

    // Staff can't make a colour stand differently either.
    $moss->update(['wide' => 1, 'floor' => true]);
    expect($moss->fresh()->footprintAt(0, 0, 0))->toEqual($this->seat->footprintAt(0, 0, 0));

    $this->seat->update(['wide' => 3, 'deep' => 1, 'shape' => ['##.']]);
    expect($moss->fresh()->only(['wide', 'deep', 'shape']))->toBe(['wide' => 3, 'deep' => 1, 'shape' => ['##.']])
        ->and($moss->fresh()->footprintAt(4, 4, 1))->toEqual($this->seat->fresh()->footprintAt(4, 4, 1));
});

test('staff add a colour in the Variants section, off the shelves, and are taken to its art', function () {
    Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $this->seat, 'pageClass' => EditItem::class])
        ->callAction(TestAction::make('addVariant')->table(), data: ['variant_label' => 'Moss', 'swatch' => '#6F8A4F', 'price' => 90])
        ->assertHasNoActionErrors();

    $moss = Item::query()->where('slug', 'love-seat-moss')->sole();

    expect($moss->only(['variant_of', 'variant_label', 'swatch', 'name', 'price', 'in_shop', 'wide', 'deep', 'shape']))->toBe([
        'variant_of' => $this->seat->id, 'variant_label' => 'Moss', 'swatch' => '#6f8a4f', 'name' => $this->seat->name,
        'price' => 90, 'in_shop' => false, 'wide' => 2, 'deep' => 2, 'shape' => ['##', '#.'],
    ]);

    // The colour's own edit page has its art uploads, and no Variants of its own.
    expect(VariantsRelationManager::canViewForRecord($moss, EditItem::class))->toBeFalse();
    Livewire::test(EditItem::class, ['record' => $moss->getRouteKey()])
        ->fillForm(['price' => 99])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($moss->fresh()->only(['price', 'wide', 'deep', 'shape']))->toBe(['price' => 99, 'wide' => 2, 'deep' => 2, 'shape' => ['##', '#.']]);
});

test('a colour of a colour, or of something that is not furniture, is refused', function () {
    $add = app(AddItemVariant::class);
    $moss = $add($this->seat, 'Moss', '#6f8a4f', 80);

    expect(fn () => $add($moss, 'Fern', '#00ff00', 80))->toThrow(ModerationActionRefused::class)
        ->and(fn () => $add(Item::factory()->create(['slot' => ItemSlot::Head]), 'Red', '#ff0000', 10))->toThrow(ModerationActionRefused::class)
        ->and(fn () => $add($this->seat, 'moss', '#6f8a4f', 80))->toThrow(ModerationActionRefused::class);
});

test('the catalog seeds the love seat in three colours, and seeding again changes nothing', function () {
    $this->seed(ItemSeeder::class);
    $this->seed(ItemSeeder::class);

    $parent = Item::query()->where('slug', 'hearthmore-love-seat')->sole();

    expect($parent->variants()->orderBy('id')->pluck('variant_label')->all())->toBe(['Moss', 'Slate'])
        ->and($parent->variant_label)->toBe('Rose')
        ->and($parent->variants->every(fn (Item $variant): bool => $variant->footprintAt(0, 0, 0) == $parent->footprintAt(0, 0, 0)))->toBeTrue();
    expect(ItemResource::getUrl('edit', ['record' => $parent]))->toContain((string) $parent->id);
});

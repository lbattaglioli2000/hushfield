<?php

use App\Domain\Books\BookCover;
use App\Domain\Books\BookState;
use App\Domain\Books\CopySource;
use App\Domain\Identity\StaffRole;
use App\Domain\Items\InventorySource;
use App\Filament\Resources\Books\Pages\CreateBook;
use App\Filament\Resources\Books\Pages\EditBook;
use App\Filament\Resources\Books\Pages\ViewBook;
use App\Filament\Resources\Books\RelationManagers\CopiesRelationManager;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\LibraryShelves\LibraryShelfResource;
use App\Filament\Resources\LibraryShelves\Pages\CreateLibraryShelf;
use App\Filament\Resources\LibraryShelves\Pages\ListLibraryShelves;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\LibraryShelf;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = User::factory()->staff(StaffRole::Admin)->create();
    $this->actingAs($this->admin);
    $this->arrivals = LibraryShelf::query()->where('slug', 'new-arrivals')->sole();
});

function writeBook(array $data = []): Book
{
    Livewire::test(CreateBook::class)
        ->fillForm([
            'title' => 'The Long Fog',
            'body' => "It came in at dusk.\r\n\r\n\r\n\r\nIt stayed.\u{200B}",
            'cover' => BookCover::Navy->value,
            'library_shelf_id' => test()->arrivals->id,
            ...$data,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    return Book::query()->latest('id')->firstOrFail();
}

test('an admin writes a draft, credits a resident, and publishes it onto its library shelf', function () {
    $wren = resident(['username' => 'Wren']);
    $book = writeBook(['author_id' => $wren->id]);

    // Line breaks are kept (one blank line between paragraphs at most); invisible characters go.
    expect($book->only(['title', 'body', 'author_id', 'written_by']))->toBe([
        'title' => 'The Long Fog', 'body' => "It came in at dusk.\n\nIt stayed.", 'author_id' => $wren->id, 'written_by' => $this->admin->id,
    ])->and($book->state())->toBe(BookState::Draft)
        // Credit doesn't come with a copy.
        ->and($wren->bookCopies()->count())->toBe(0);

    Livewire::test(EditBook::class, ['record' => $book->getRouteKey()])
        ->callAction('publish')
        ->assertHasNoActionErrors();

    expect($book->fresh()->state())->toBe(BookState::Published)
        ->and(Activity::query()->where('event', 'book.published')->where('subject_id', $book->id)->exists())->toBeTrue();
});

test('a blocked term stops publishing, and links or phone numbers need confirming', function () {
    blockTerm('grimbly');
    $blocked = writeBook(['title' => 'A Grimbly Tale']);

    Livewire::test(EditBook::class, ['record' => $blocked->getRouteKey()])->callAction('publish');
    expect($blocked->fresh()->state())->toBe(BookState::Draft);

    // "1923–1931" reads as a phone number to the chat's check: a warning, not a stop.
    $dated = writeBook(['title' => 'Annals', 'body' => 'The fog of 1923-1931 never lifted.']);
    Livewire::test(EditBook::class, ['record' => $dated->getRouteKey()])
        ->callAction('publish', data: ['confirmed' => false])
        ->assertHasActionErrors(['confirmed']);
    expect($dated->fresh()->state())->toBe(BookState::Draft);

    Livewire::test(EditBook::class, ['record' => $dated->getRouteKey()])
        ->callAction('publish', data: ['confirmed' => true])
        ->assertHasNoActionErrors();
    expect($dated->fresh()->state())->toBe(BookState::Published);
});

test('changes to a published book are checked as publishing is, and audited', function () {
    blockTerm('grimbly');
    $book = Book::factory()->published()->create(['title' => 'Annals']);

    Livewire::test(EditBook::class, ['record' => $book->getRouteKey()])
        ->fillForm(['body' => 'A grimbly ending.'])
        ->call('save');
    expect($book->fresh()->body)->not->toBe('A grimbly ending.');

    Livewire::test(EditBook::class, ['record' => $book->getRouteKey()])
        ->fillForm(['body' => 'A quiet ending.'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($book->fresh()->body)->toBe('A quiet ending.')
        ->and(Activity::query()->where('event', 'book.edited')->where('subject_id', $book->id)->exists())->toBeTrue();
});

test('a moderator reads any book and withdraws or restores it, but writes nothing', function () {
    $this->actingAs(User::factory()->staff(StaffRole::Moderator)->create());
    $book = Book::factory()->published()->create();

    Livewire::test(ViewBook::class, ['record' => $book->getRouteKey()])
        ->assertOk()
        ->assertActionHidden('publish')
        ->callAction('withdraw', data: ['reason' => 'Upset a reader'])
        ->assertHasNoActionErrors();
    expect($book->fresh()->state())->toBe(BookState::Withdrawn);

    Livewire::test(ViewBook::class, ['record' => $book->getRouteKey()])
        ->callAction('restore', data: ['reason' => 'Reviewed, fine']);
    expect($book->fresh()->state())->toBe(BookState::Published);

    $this->get(route('filament.admin.resources.books.create'))->assertForbidden();
    $this->get(route('filament.admin.resources.books.edit', $book))->assertForbidden();
    $this->get(LibraryShelfResource::getUrl('index'))->assertForbidden();
});

test('an admin gives a resident a copy to keep, once', function () {
    $book = Book::factory()->published()->create();
    $wren = resident(['username' => 'Wren']);
    $give = TestAction::make('giveCopy')->table();

    Livewire::test(CopiesRelationManager::class, ['ownerRecord' => $book, 'pageClass' => ViewBook::class])
        ->callAction($give, data: ['user_id' => $wren->id, 'reason' => 'Contest prize'])
        ->assertHasNoActionErrors()
        ->callAction($give, data: ['user_id' => $wren->id, 'reason' => 'Again']);

    expect(BookCopy::query()->where('user_id', $wren->id)->sole()->only(['book_id', 'source', 'given_by']))
        ->toBe(['book_id' => $book->id, 'source' => CopySource::StaffGift, 'given_by' => $this->admin->id]);
});

test('library shelves are added and audited, and one with books can\'t be deleted', function () {
    Livewire::test(CreateLibraryShelf::class)
        ->fillForm(['name' => 'Town history', 'slug' => 'town-history', 'position' => 2])
        ->call('create')
        ->assertHasNoFormErrors();
    $history = LibraryShelf::query()->where('slug', 'town-history')->sole();

    expect(Activity::query()->where('event', 'library_shelf.created')->where('causer_id', $this->admin->id)->exists())->toBeTrue();

    Book::factory()->create();
    Livewire::test(ListLibraryShelves::class)
        ->assertActionHidden(TestAction::make('delete')->table($this->arrivals))
        ->callAction(TestAction::make('delete')->table($history));
    expect(LibraryShelf::query()->pluck('slug')->all())->toBe(['new-arrivals']);
});

test('"Holds books" can\'t drop below what a shelf of the item, or its colours, already holds', function () {
    $case = Item::factory()->furniture()->create(['holds_books' => 4]);
    $oak = Item::factory()->create(['variant_of' => $case->id]);
    $wren = resident();
    $shelf = InventoryItem::query()->create(['user_id' => $wren->id, 'item_id' => $oak->id, 'source' => InventorySource::Purchase]);

    foreach (Book::factory()->published()->count(3)->create() as $book) {
        BookCopy::query()->create(['book_id' => $book->id, 'user_id' => $wren->id, 'source' => CopySource::StaffGift])
            ->forceFill(['shelf_id' => $shelf->id, 'shelved_at' => now()])->save();
    }

    Livewire::test(EditItem::class, ['record' => $case->getRouteKey()])
        ->fillForm(['holds_books' => 2])
        ->call('save')
        ->assertHasFormErrors(['holds_books']);

    Livewire::test(EditItem::class, ['record' => $case->getRouteKey()])
        ->fillForm(['holds_books' => 3])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($oak->fresh()->holds_books)->toBe(3);
});

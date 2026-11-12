<?php

use App\Domain\Books\CopySource;
use App\Domain\Housing\HomeVisitors;
use App\Domain\Items\InventorySource;
use App\Events\World\ShelfChanged;
use App\Features\BooksEnabled;
use App\Features\WorldFlags;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\HomeFurniture;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\LibraryShelf;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/*
 * test-library has two library shelves: "new-arrivals" at (3, 1) and
 * "town-history" at (7, 1); everyone arrives at (1, 1). test-home is 5 × 5,
 * its door at (2, 4).
 */
beforeEach(function () {
    fakeWorldEvents();
    $this->library = importRoom('test-library');
    importRoom('test-lane');
    $this->residence = importRoom('test-home');

    $this->arrivals = LibraryShelf::query()->where('slug', 'new-arrivals')->sole();
    $this->history = LibraryShelf::query()->create(['slug' => 'town-history', 'name' => 'Town history', 'position' => 1]);
    $this->book = Book::factory()->published()->create(['title' => 'The Long Fog', 'body' => "First page.\n\nSecond page."]);

    $this->reader = resident(['username' => 'Wren']);
    ['token' => $this->token] = enterWorld($this->reader, $this->library);
});

function readerAt(int $x, int $y): void
{
    standAt(test()->reader, $x, $y);
}

function checkOut(Book $book, string $shelf = 'new-arrivals'): TestResponse
{
    return asResident(test()->reader, test()->token)->postJson(route('world.library.check-out', [$shelf, $book]));
}

/** Takes the reader home, with a bookshelf of theirs holding $holds books placed at (0, 0). */
function goHomeWithShelf(int $holds = 2): InventoryItem
{
    $shelf = InventoryItem::query()->create([
        'user_id' => test()->reader->id,
        'item_id' => Item::factory()->furniture()->create(['holds_books' => $holds])->id,
        'source' => InventorySource::Purchase,
    ]);
    ['token' => test()->token] = enterWorld(test()->reader, test()->residence);
    readerAt(1, 1);
    asResident(test()->reader, test()->token)->postJson(route('world.home.furniture.place', $shelf), ['x' => 0, 'y' => 0])->assertOk();

    return $shelf;
}

function shelve(InventoryItem $shelf, BookCopy $copy, ?User $as = null, ?string $token = null): TestResponse
{
    return asResident($as ?? test()->reader, $token ?? test()->token)->postJson(route('world.home.shelf.shelve', [$shelf, $copy]));
}

/** A visitor in the reader's home, standing on (x, y), and their token. */
function visitor(int $x, int $y): array
{
    test()->reader->home->forceFill(['visitors' => HomeVisitors::Everyone])->save();
    $guest = resident();
    ['token' => $token] = enterWorld($guest, test()->residence, test()->reader->home->instance);
    standAt($guest, $x, $y);

    return [$guest, $token];
}

test('a resident at a library shelf browses it, reads a book, and checks out one copy into their belongings', function () {
    $draft = Book::factory()->create();
    $withdrawn = Book::factory()->withdrawn()->create();
    $elsewhere = Book::factory()->published()->create(['library_shelf_id' => $this->history->id]);
    readerAt(3, 2);

    asResident($this->reader, $this->token)->getJson(route('world.library.show', 'new-arrivals'))->assertOk()
        ->assertJsonPath('shelf.name', 'New arrivals')
        // Only published books on this category, and no text.
        ->assertJsonPath('shelf.books.*.book', [$this->book->id])
        ->assertJsonMissingPath('shelf.books.0.body')
        ->assertJsonPath('shelf.held', []);

    asResident($this->reader, $this->token)->getJson(route('world.library.read', ['new-arrivals', $this->book]))->assertOk()
        ->assertJsonPath('book.body', "First page.\n\nSecond page.");

    checkOut($this->book)->assertCreated()
        ->assertJsonPath('books.0.title', 'The Long Fog')
        ->assertJsonPath('books.0.source', 'checked_out')
        ->assertJsonPath('books.0.shelf', null)
        ->assertJsonPath('shelf.held', [$this->book->id]);

    // One copy of a book per resident: a second tap is refused.
    checkOut($this->book)->assertConflict()->assertJsonPath('reason', 'book_already_held');

    foreach ([$draft, $withdrawn, $elsewhere] as $unavailable) {
        checkOut($unavailable)->assertNotFound()->assertJsonPath('reason', 'book_unavailable');
        asResident($this->reader, $this->token)->getJson(route('world.library.read', ['new-arrivals', $unavailable]))->assertNotFound();
    }

    expect($this->reader->bookCopies()->pluck('book_id')->all())->toBe([$this->book->id]);
});

test('a library shelf is only used standing at it, and standing at one shelf reaches only its own books', function () {
    $history = Book::factory()->published()->create(['library_shelf_id' => $this->history->id]);

    readerAt(1, 1);
    asResident($this->reader, $this->token)->getJson(route('world.library.show', 'new-arrivals'))
        ->assertUnprocessable()->assertJsonPath('reason', 'library_too_far');
    checkOut($this->book)->assertUnprocessable();

    // At the town history shelf, a New arrivals book is still out of reach, through either shelf's address.
    readerAt(7, 1);
    checkOut($this->book)->assertUnprocessable()->assertJsonPath('reason', 'library_too_far');
    checkOut($this->book, 'town-history')->assertNotFound();
    checkOut($history, 'town-history')->assertCreated();

    // A shelf the map doesn't have, and the library from another room.
    asResident($this->reader, $this->token)->getJson(route('world.library.show', 'poetry'))->assertConflict();
    ['token' => $this->token] = enterWorld($this->reader, importRoom('test-square'));
    asResident($this->reader, $this->token)->getJson(route('world.library.show', 'new-arrivals'))
        ->assertConflict()->assertJsonPath('reason', 'library_elsewhere');
});

test('the owner shelves a book at home, up to what the shelf holds, and everyone in the room is told', function () {
    readerAt(3, 1);
    checkOut($this->book)->assertCreated();
    $second = Book::factory()->published()->create();
    $third = Book::factory()->published()->create();
    checkOut($second)->assertCreated();
    checkOut($third)->assertCreated();
    [$first, $two, $three] = array_map(fn (Book $book): BookCopy => $this->reader->bookCopies()->where('book_id', $book->id)->sole(), [$this->book, $second, $third]);

    $shelf = goHomeWithShelf(holds: 2);
    shelve($shelf, $first)->assertOk()->assertJsonPath('shelf.books.*.book', [$this->book->id]);
    shelve($shelf, $two)->assertOk()->assertJsonPath('shelf.books.*.book', [$this->book->id, $second->id]);
    shelve($shelf, $three)->assertConflict()->assertJsonPath('reason', 'shelf_full');

    Event::assertDispatched(ShelfChanged::class, fn (ShelfChanged $event): bool => $event->piece === $shelf->id && $event->instanceId === $this->reader->home->room_instance_id);

    // From across the room, the shelf won't open.
    readerAt(3, 3);
    asResident($this->reader, $this->token)->getJson(route('world.home.shelf', $shelf))
        ->assertUnprocessable()->assertJsonPath('reason', 'shelf_too_far');

    // Taken back off, it is in belongings again, and there is room for the third.
    readerAt(1, 0);
    asResident($this->reader, $this->token)->deleteJson(route('world.books.unshelve', $first))->assertOk()
        ->assertJsonPath('books.*.shelf', fn (array $shelves): bool => collect($shelves)->filter()->count() === 1);
    shelve($shelf, $three)->assertOk();
});

test('a visitor sees and reads the books on a home shelf from beside it, but never takes or adds one', function () {
    readerAt(3, 1);
    checkOut($this->book)->assertCreated();
    $copy = $this->reader->bookCopies()->sole();
    $shelf = goHomeWithShelf();
    shelve($shelf, $copy)->assertOk();

    [$guest, $guestToken] = visitor(3, 3);
    asResident($guest, $guestToken)->getJson(route('world.home.shelf', $shelf))->assertUnprocessable();
    asResident($guest, $guestToken)->getJson(route('world.books.read', $copy))->assertUnprocessable();

    standAt($guest, 1, 1);
    asResident($guest, $guestToken)->getJson(route('world.home.shelf', $shelf))->assertOk()
        ->assertJsonPath('shelf.own', false)
        ->assertJsonPath('shelf.owner', 'Wren')
        ->assertJsonPath('shelf.books.0.title', 'The Long Fog');
    asResident($guest, $guestToken)->getJson(route('world.books.read', $copy))->assertOk()
        ->assertJsonPath('book.title', 'The Long Fog');

    asResident($guest, $guestToken)->deleteJson(route('world.books.unshelve', $copy))->assertForbidden();
    $guestCopy = BookCopy::query()->create(['book_id' => Book::factory()->published()->create()->id, 'user_id' => $guest->id, 'source' => CopySource::StaffGift]);
    shelve($shelf, $guestCopy, $guest, $guestToken)->assertForbidden();

    expect($copy->fresh()->only(['user_id', 'shelf_id']))->toBe(['user_id' => $this->reader->id, 'shelf_id' => $shelf->id]);
});

test('books stay on a shelf that is put away, and come back to belongings from anywhere', function () {
    readerAt(3, 1);
    checkOut($this->book)->assertCreated();
    $copy = $this->reader->bookCopies()->sole();
    $shelf = goHomeWithShelf();
    shelve($shelf, $copy)->assertOk();

    asResident($this->reader, $this->token)->deleteJson(route('world.home.furniture.remove', $shelf))->assertOk();
    expect($copy->fresh()->shelf_id)->toBe($shelf->id)
        ->and(HomeFurniture::query()->where('inventory_item_id', $shelf->id)->exists())->toBeFalse();

    asResident($this->reader, $this->token)->getJson(route('world.home.shelf', $shelf))->assertNotFound();
    // Their own copy reads from anywhere, wherever it is.
    readerAt(4, 4);
    asResident($this->reader, $this->token)->getJson(route('world.books.read', $copy))->assertOk();
    asResident($this->reader, $this->token)->deleteJson(route('world.books.unshelve', $copy))->assertOk();
    expect($copy->fresh()->shelf_id)->toBeNull();
});

test('a withdrawn book keeps its copies, but shows no title or text anywhere', function () {
    readerAt(3, 1);
    checkOut($this->book)->assertCreated();
    $copy = $this->reader->bookCopies()->sole();
    $this->book->forceFill(['withdrawn_at' => now()])->save();

    asResident($this->reader, $this->token)->getJson(route('world.books'))->assertOk()
        ->assertJsonPath('books.0.withdrawn', true)
        ->assertJsonPath('books.0.title', null)
        ->assertJsonPath('books.0.author', null);
    asResident($this->reader, $this->token)->getJson(route('world.books.read', $copy))->assertNotFound();
    asResident($this->reader, $this->token)->getJson(route('world.library.show', 'new-arrivals'))->assertJsonPath('shelf.books', []);
});

test('staff can switch books off in the game, and everyone keeps their copies', function () {
    readerAt(3, 1);
    checkOut($this->book)->assertCreated();
    WorldFlags::set(BooksEnabled::class, false);

    checkOut(Book::factory()->published()->create())->assertServiceUnavailable()->assertJsonPath('reason', 'books_closed');
    asResident($this->reader, $this->token)->getJson(route('world.books.read', $this->reader->bookCopies()->sole()))->assertServiceUnavailable();
    asResident($this->reader, $this->token)->getJson(route('world.books'))->assertOk()->assertJsonCount(1, 'books');
});

test('a bookshelf\'s colours hold as many books as it does, and only furniture holds books', function () {
    $case = Item::factory()->furniture()->create(['holds_books' => 6]);
    $oak = Item::factory()->create(['variant_of' => $case->id, 'holds_books' => 40]);

    expect($oak->fresh()->holds_books)->toBe(6)
        ->and(Item::factory()->create(['slot' => 'head', 'holds_books' => 3])->holds_books)->toBeNull()
        ->and($case->toPayload()['furniture']['holds_books'])->toBe(6);
});

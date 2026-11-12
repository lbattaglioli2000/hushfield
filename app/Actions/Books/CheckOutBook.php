<?php

namespace App\Actions\Books;

use App\Domain\Books\CopySource;
use App\Domain\Books\Library;
use App\Exceptions\WorldActionRefused;
use App\Features\WorldFlag;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A resident standing at a library shelf checks out a copy of one of its
 * published books into their belongings. One copy of each book per
 * resident, however they got it: the database refuses a second, so two taps
 * at once make one copy.
 */
final readonly class CheckOutBook
{
    public function __construct(private Library $library) {}

    public function __invoke(User $user, string $shelfSlug, Book $book): BookCopy
    {
        WorldFlag::Books->ensureActive();
        $shelf = $this->library->ensureAt($user, $shelfSlug);

        if (! $book->isReadable() || $book->library_shelf_id !== $shelf->id) {
            throw WorldActionRefused::bookUnavailable();
        }

        try {
            // Its own savepoint: on Postgres a failed insert spoils the transaction it is in.
            return DB::transaction(fn (): BookCopy => BookCopy::query()->create([
                'book_id' => $book->id,
                'user_id' => $user->id,
                'source' => CopySource::CheckedOut,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw WorldActionRefused::bookAlreadyHeld();
        }
    }
}

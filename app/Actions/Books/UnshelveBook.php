<?php

namespace App\Actions\Books;

use App\Domain\Books\Bookshelves;
use App\Exceptions\WorldActionRefused;
use App\Features\WorldFlag;
use App\Models\BookCopy;
use App\Models\User;

/**
 * A resident takes a copy of theirs off one of their bookshelves, back into
 * their belongings. They stand beside the shelf if it is placed; from a shelf
 * that is put away, they take it back from anywhere (their Belongings), so
 * no book is ever stuck. Visitors never take books.
 */
final readonly class UnshelveBook
{
    public function __construct(private Bookshelves $shelves) {}

    public function __invoke(User $user, BookCopy $copy): BookCopy
    {
        WorldFlag::Books->ensureActive();

        if ($copy->user_id !== $user->id) {
            throw WorldActionRefused::bookNotYours();
        }

        $shelf = $copy->shelf;

        if ($shelf === null) {
            return $copy;
        }

        if ($shelf->placement()->exists()) {
            $this->shelves->ensureBeside($user, $shelf);
        }

        // Only clears the shelf it was on, so a move to another shelf meanwhile wins.
        BookCopy::query()->whereKey($copy->id)->where('shelf_id', $shelf->id)->update(['shelf_id' => null, 'shelved_at' => null]);
        $this->shelves->changed($shelf->id);

        return $copy->refresh();
    }
}

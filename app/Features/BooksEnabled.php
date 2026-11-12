<?php

namespace App\Features;

use Laravel\Pennant\Attributes\Name;

/**
 * Kill switch for books in the game: the library's shelves and bookshelves at
 * home. Copies stay with their holders. Checked with the global "world" scope.
 */
#[Name('books-enabled')]
final class BooksEnabled
{
    public function resolve(mixed $scope): bool
    {
        return true;
    }
}

<?php

namespace App\Actions\Books;

use App\Domain\Books\Bookshelves;
use App\Domain\Books\Library;
use App\Exceptions\WorldActionRefused;
use App\Features\WorldFlag;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\User;

/**
 * A book's text, for one reader. Shelves and belongings carry spines only;
 * the text is fetched when a book is opened, and only shown for a published
 * book the reader can reach: a copy they hold, a copy on a home bookshelf
 * they stand beside, or a book on the library shelf they stand at. Drafts and
 * withdrawn books are never shown in the game.
 */
final readonly class ReadBook
{
    public function __construct(
        private Bookshelves $shelves,
        private Library $library,
    ) {}

    /**
     * @return array{book: int, title: string, author: string|null, cover: string, body: string}
     */
    public function copy(User $reader, BookCopy $copy): array
    {
        WorldFlag::Books->ensureActive();

        if ($copy->user_id !== $reader->id) {
            $shelf = $copy->shelf ?? throw WorldActionRefused::bookUnavailable();
            $this->shelves->ensureBeside($reader, $shelf);
        }

        return $this->text($copy->book);
    }

    /**
     * @return array{book: int, title: string, author: string|null, cover: string, body: string}
     */
    public function fromLibrary(User $reader, string $shelfSlug, Book $book): array
    {
        WorldFlag::Books->ensureActive();
        $shelf = $this->library->ensureAt($reader, $shelfSlug);

        if ($book->library_shelf_id !== $shelf->id) {
            throw WorldActionRefused::bookUnavailable();
        }

        return $this->text($book);
    }

    /**
     * @return array{book: int, title: string, author: string|null, cover: string, body: string}
     */
    private function text(Book $book): array
    {
        if (! $book->isReadable()) {
            throw WorldActionRefused::bookUnavailable();
        }

        return [
            'book' => $book->id,
            'title' => $book->title,
            'author' => $book->author?->username,
            'cover' => $book->cover->hex(),
            'body' => $book->body,
        ];
    }
}

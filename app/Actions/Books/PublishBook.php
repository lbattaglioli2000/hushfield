<?php

namespace App\Actions\Books;

use App\Domain\Audit\AuditLog;
use App\Domain\Books\BookState;
use App\Domain\Books\BookText;
use App\Exceptions\ModerationActionRefused;
use App\Models\Book;
use App\Models\User;

/**
 * Puts a draft on its library shelf, where residents can read it and check
 * out a copy. Its title and text are checked against the blocked terms list
 * first (BookText): a blocked term stops it, and links, emails or phone
 * numbers need staff to confirm. Audited.
 */
final readonly class PublishBook
{
    public function __construct(private BookText $text) {}

    public function __invoke(Book $book, User $staff, bool $confirmed = false): Book
    {
        if ($book->state() !== BookState::Draft) {
            throw ModerationActionRefused::bookNotDraft();
        }

        SaveBookDraft::ensurePasses($book->title, $book->body, $confirmed, $this->text);

        $book->forceFill(['published_at' => now()])->save();

        activity(AuditLog::Library)
            ->performedOn($book)
            ->causedBy($staff)
            ->withProperties(['title' => $book->title, 'shelf' => $book->libraryShelf->name, 'author_id' => $book->author_id])
            ->event('book.published')
            ->log("Published {$book->title}");

        return $book;
    }
}

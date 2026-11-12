<?php

namespace App\Actions\Books;

use App\Domain\Audit\AuditLog;
use App\Domain\Books\BookState;
use App\Exceptions\ModerationActionRefused;
use App\Models\Book;
use App\Models\User;

/**
 * Takes a published book off the library's shelves and out of reading
 * everywhere: copies stay with their holders, as plain untitled spines.
 * restore() undoes it. Admins and moderators may; both are audited.
 */
final readonly class WithdrawBook
{
    public function __invoke(Book $book, User $staff, string $reason): Book
    {
        if ($book->state() !== BookState::Published) {
            throw ModerationActionRefused::bookNotPublished();
        }

        $book->forceFill(['withdrawn_at' => now()])->save();
        $this->audit($book, $staff, $reason, 'book.withdrawn', "Withdrew {$book->title}");

        return $book;
    }

    public function restore(Book $book, User $staff, string $reason): Book
    {
        if ($book->state() !== BookState::Withdrawn) {
            throw ModerationActionRefused::bookNotWithdrawn();
        }

        $book->forceFill(['withdrawn_at' => null])->save();
        $this->audit($book, $staff, $reason, 'book.restored', "Restored {$book->title}");

        return $book;
    }

    private function audit(Book $book, User $staff, string $reason, string $event, string $description): void
    {
        activity(AuditLog::Library)
            ->performedOn($book)
            ->causedBy($staff)
            ->withProperties(['title' => $book->title, 'reason' => $reason])
            ->event($event)
            ->log($description);
    }
}

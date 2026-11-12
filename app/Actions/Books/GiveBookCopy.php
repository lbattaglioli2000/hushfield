<?php

namespace App\Actions\Books;

use App\Domain\Audit\AuditLog;
use App\Domain\Books\CopySource;
use App\Exceptions\ModerationActionRefused;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Staff give a resident a copy of a published book, to keep for good, with
 * a reason. Refused if they already hold one. Audited.
 */
final readonly class GiveBookCopy
{
    public function __invoke(Book $book, User $target, User $staff, string $reason): BookCopy
    {
        if (! $book->isReadable()) {
            throw ModerationActionRefused::bookNotPublished();
        }

        try {
            // Its own savepoint: on Postgres a failed insert spoils the transaction it is in.
            $copy = DB::transaction(fn (): BookCopy => BookCopy::query()->create([
                'book_id' => $book->id,
                'user_id' => $target->id,
                'source' => CopySource::StaffGift,
                'given_by' => $staff->id,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ModerationActionRefused::bookAlreadyHeld($target->username);
        }

        activity(AuditLog::Library)
            ->performedOn($target)
            ->causedBy($staff)
            ->withProperties(['book_id' => $book->id, 'title' => $book->title, 'reason' => $reason])
            ->event('book.given')
            ->log("Gave a copy of {$book->title}");

        return $copy;
    }
}

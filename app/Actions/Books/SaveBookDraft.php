<?php

namespace App\Actions\Books;

use App\Domain\Audit\AuditLog;
use App\Domain\Books\BookCover;
use App\Domain\Books\BookText;
use App\Exceptions\ModerationActionRefused;
use App\Models\Book;
use App\Models\LibraryShelf;
use App\Models\User;

/**
 * Writes a new book, or saves changes to one. Staff call it from the admin
 * now; an in-game desk can call it later with the resident as the writer.
 * Changes to a published book go live at once, so they are checked like
 * publishing (PublishBook) and audited.
 */
final readonly class SaveBookDraft
{
    public function __construct(private BookText $text) {}

    /**
     * @param  array{title: string, body: string, cover: BookCover|string, author_id: int|null, library_shelf_id: int}  $data
     * @param  bool  $confirmed  Staff saw the warning about links, emails or phone numbers and want it published anyway.
     *
     * @throws ModerationActionRefused when the text is too long, or a published book's new text fails the check.
     */
    public function __invoke(?Book $book, array $data, User $writer, bool $confirmed = false): Book
    {
        $title = BookText::clean($data['title']);
        $body = BookText::clean($data['body']);

        if ($title === '' || $body === '' || mb_strlen($title) > Book::MAX_TITLE || mb_strlen($body) > Book::MAX_BODY) {
            throw ModerationActionRefused::bookTooLong();
        }

        $book ??= new Book(['written_by' => $writer->id]);
        $live = $book->exists && $book->isReadable();

        if ($live) {
            $this->ensurePasses($title, $body, $confirmed, $this->text);
        }

        $book->fill([
            'title' => $title,
            'body' => $body,
            'cover' => $data['cover'],
            'author_id' => $data['author_id'],
            'library_shelf_id' => LibraryShelf::query()->findOrFail($data['library_shelf_id'])->id,
        ])->save();

        if ($live && $book->wasChanged()) {
            activity(AuditLog::Library)
                ->performedOn($book)
                ->causedBy($writer)
                ->withProperties(['changed' => array_keys($book->getChanges())])
                ->event('book.edited')
                ->log("Edited a published book: {$book->title}");
        }

        return $book;
    }

    /**
     * A blocked term always stops it; contact details only once staff confirm.
     *
     * @throws ModerationActionRefused
     */
    public static function ensurePasses(string $title, string $body, bool $confirmed, BookText $text): void
    {
        ['blocked' => $blocked, 'warnings' => $warnings] = $text->check($title, $body);

        if ($blocked !== []) {
            throw ModerationActionRefused::bookBlocked($blocked);
        }

        if ($warnings !== [] && ! $confirmed) {
            throw ModerationActionRefused::bookNeedsConfirming($warnings);
        }
    }
}

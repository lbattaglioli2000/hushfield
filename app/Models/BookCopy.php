<?php

namespace App\Models;

use App\Domain\Books\CopySource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One copy of a book a resident holds: at most one of each book, however
 * they got it (book_copies is unique on book and holder). It sits in their
 * belongings, or on one of their bookshelves (`shelf_id`, the owned piece,
 * so it stays on the shelf while the shelf is put away). Created only by
 * App\Actions\Books\CheckOutBook and GiveBookCopy.
 *
 * @property int $id
 * @property int $book_id
 * @property int $user_id
 * @property CopySource $source
 * @property int|null $given_by
 * @property int|null $shelf_id
 * @property Carbon|null $shelved_at
 * @property Carbon $created_at
 * @property-read Book $book
 * @property-read User $user
 * @property-read User|null $giver
 * @property-read InventoryItem|null $shelf
 */
#[Fillable(['book_id', 'user_id', 'source', 'given_by'])]
class BookCopy extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'source' => CopySource::class,
            'shelved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function giver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'given_by');
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function shelf(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'shelf_id');
    }

    /**
     * How a copy shows in its holder's belongings and on a shelf.
     *
     * @return array{copy: int, book: int, title: string|null, author: string|null, cover: string, withdrawn: bool, source: string, acquired_at: string, shelf: int|null}
     */
    public function toPayload(): array
    {
        return [
            'copy' => $this->id,
            ...$this->book->toSpine(),
            'source' => $this->source->value,
            'acquired_at' => $this->created_at->toIso8601String(),
            'shelf' => $this->shelf_id,
        ];
    }
}

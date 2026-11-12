<?php

namespace App\Models;

use App\Domain\Books\BookCover;
use App\Domain\Books\BookState;
use Database\Factories\BookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A piece of writing: what copies of it say. Staff write books in the admin
 * for now (App\Actions\Books\SaveBookDraft and PublishBook, which an in-game
 * desk can call later). A published book stands on its library shelf, where
 * residents check out copies (BookCopy). Withdrawing hides it everywhere.
 *
 * @property int $id
 * @property string $title
 * @property string $body Plain text: paragraphs split by blank lines.
 * @property BookCover $cover
 * @property int|null $author_id The resident credited on the spine, if any.
 * @property int $library_shelf_id
 * @property int|null $written_by The staff member who wrote it in the admin.
 * @property Carbon|null $published_at
 * @property Carbon|null $withdrawn_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $author
 * @property-read LibraryShelf $libraryShelf
 * @property-read Collection<int, BookCopy> $copies
 */
#[Fillable(['title', 'body', 'cover', 'author_id', 'library_shelf_id', 'written_by'])]
class Book extends Model
{
    /** @use HasFactory<BookFactory> */
    use HasFactory;

    public const int MAX_TITLE = 60;

    public const int MAX_BODY = 20_000;

    protected function casts(): array
    {
        return [
            'cover' => BookCover::class,
            'published_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<LibraryShelf, $this> */
    public function libraryShelf(): BelongsTo
    {
        return $this->belongsTo(LibraryShelf::class);
    }

    /** @return HasMany<BookCopy, $this> */
    public function copies(): HasMany
    {
        return $this->hasMany(BookCopy::class);
    }

    public function state(): BookState
    {
        return match (true) {
            $this->withdrawn_at !== null => BookState::Withdrawn,
            $this->published_at !== null => BookState::Published,
            default => BookState::Draft,
        };
    }

    /** On the library's shelves, and readable in the game. */
    public function isReadable(): bool
    {
        return $this->state() === BookState::Published;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function readable(Builder $query): void
    {
        $query->whereNotNull('published_at')->whereNull('withdrawn_at');
    }

    /**
     * How a book shows on a shelf or in belongings: no text, which is fetched
     * when it is opened (see ReadBook). A withdrawn book shows a plain spine,
     * without its title or author.
     *
     * @return array{book: int, title: string|null, author: string|null, cover: string, withdrawn: bool}
     */
    public function toSpine(): array
    {
        $readable = $this->isReadable();

        return [
            'book' => $this->id,
            'title' => $readable ? $this->title : null,
            'author' => $readable ? $this->author?->username : null,
            'cover' => $readable ? $this->cover->hex() : BookCover::Slate->hex(),
            'withdrawn' => ! $readable,
        ];
    }
}

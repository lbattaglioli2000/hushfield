<?php

namespace App\Actions\Books;

use App\Domain\Books\Bookshelves;
use App\Exceptions\WorldActionRefused;
use App\Features\WorldFlag;
use App\Models\BookCopy;
use App\Models\Home;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A resident standing at one of their own bookshelves puts a copy they hold
 * on it, at the end, or moves it there from another of their shelves. The
 * home is locked while it checks the shelf has room, as placing furniture
 * locks it, so two shelvings at once can't overfill it.
 */
final readonly class ShelveBook
{
    public function __construct(private Bookshelves $shelves) {}

    public function __invoke(User $user, InventoryItem $piece, BookCopy $copy): BookCopy
    {
        WorldFlag::Books->ensureActive();

        if ($piece->user_id !== $user->id || $copy->user_id !== $user->id) {
            throw WorldActionRefused::bookNotYours();
        }

        $placed = $this->shelves->ensureBeside($user, $piece);
        $from = $copy->shelf_id;

        if ($from === $piece->id) {
            return $copy;
        }

        DB::transaction(function () use ($placed, $piece, $copy): void {
            Home::query()->whereKey($placed->home_id)->lockForUpdate()->first();

            if (BookCopy::query()->where('shelf_id', $piece->id)->count() >= (int) $piece->item->holds_books) {
                throw WorldActionRefused::shelfFull();
            }

            $copy->forceFill(['shelf_id' => $piece->id, 'shelved_at' => now()])->save();
        });

        $this->shelves->changed($piece->id);

        if ($from !== null) {
            $this->shelves->changed($from);
        }

        return $copy;
    }
}

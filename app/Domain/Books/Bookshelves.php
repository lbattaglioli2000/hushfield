<?php

namespace App\Domain\Books;

use App\Domain\Presence\InstanceMembers;
use App\Domain\World\Clock;
use App\Domain\World\PositionStore;
use App\Events\World\ShelfChanged;
use App\Events\WorldBroadcast;
use App\Exceptions\WorldActionRefused;
use App\Models\BookCopy;
use App\Models\HomeFurniture;
use App\Models\InventoryItem;
use App\Models\RoomInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Bookshelves at home: furniture whose item holds books (items.holds_books),
 * and the copies its owner keeps on each. A shelf is browsed and read
 * standing beside it, by its owner or a visitor, and only while it is placed.
 */
final readonly class Bookshelves
{
    /** Steps from the shelf's footprint a player may use it from, diagonals counting as one. */
    public const int REACH = 1;

    public function __construct(
        private InstanceMembers $members,
        private PositionStore $positions,
    ) {}

    /**
     * @throws WorldActionRefused unless the piece is a bookshelf, placed in the room the player is in, and they stand beside it.
     */
    public function ensureBeside(User $user, InventoryItem $piece): HomeFurniture
    {
        if ($piece->item->holds_books === null) {
            throw WorldActionRefused::notABookshelf();
        }

        $placed = HomeFurniture::query()->with('inventoryItem.item')->where('inventory_item_id', $piece->id)->first();

        if ($placed === null || ! $this->members->contains($placed->room_instance_id, $user->id)) {
            throw WorldActionRefused::furnitureNotHere();
        }

        $room = RoomInstance::query()->with('room')->findOrFail($placed->room_instance_id)->room;
        $now = Clock::nowMs();
        $position = $this->positions->currentOrSpawn($user->id, $room, $now)->positionAt($now);

        if ($placed->footprint()->distanceTo($position->x, $position->y) > self::REACH) {
            throw WorldActionRefused::shelfTooFar();
        }

        return $placed;
    }

    /**
     * The copies on a shelf, in the order they were shelved.
     *
     * @return Collection<int, BookCopy>
     */
    public function contents(InventoryItem $piece): Collection
    {
        return BookCopy::query()->with('book.author')->where('shelf_id', $piece->id)->orderBy('shelved_at')->orderBy('id')->get();
    }

    /**
     * What a player standing at the shelf sees: whose it is, how many books it
     * holds, and the spines on it.
     *
     * @return array{kind: string, piece: int, name: string, owner: string, own: bool, holds: int, books: list<array<string, mixed>>}
     */
    public function view(User $viewer, InventoryItem $piece): array
    {
        $piece->loadMissing(['item', 'user']);

        return [
            'kind' => 'home',
            'piece' => $piece->id,
            'name' => $piece->item->name,
            'owner' => $piece->user->username,
            'own' => $piece->user_id === $viewer->id,
            'holds' => (int) $piece->item->holds_books,
            'books' => array_values($this->contents($piece)->map(fn (BookCopy $copy): array => [
                'copy' => $copy->id,
                ...$copy->book->toSpine(),
            ])->all()),
        ];
    }

    /** Tells everyone in the room the shelf stands in, if it is placed, that its books changed. */
    public function changed(int $pieceId): void
    {
        $instanceId = HomeFurniture::query()->where('inventory_item_id', $pieceId)->value('room_instance_id');

        if ($instanceId !== null) {
            WorldBroadcast::send(new ShelfChanged((int) $instanceId, $pieceId));
        }
    }
}

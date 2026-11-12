<?php

namespace App\Domain\Books;

use App\Domain\World\FixtureKind;
use App\Domain\World\FixtureReach;
use App\Domain\World\RoomKind;
use App\Domain\World\WalkabilityGridRepository;
use App\Exceptions\WorldActionRefused;
use App\Models\Book;
use App\Models\LibraryShelf;
use App\Models\Room;
use App\Models\User;

/**
 * The Hushfield Public Library: a shelf per category (LibraryShelf), each a
 * fixture of kind "library" on a map, named the category's slug. Players
 * browse, read and check out a shelf's books standing at it or a step away.
 */
final readonly class Library
{
    public function __construct(
        private FixtureReach $reach,
        private WalkabilityGridRepository $grids,
    ) {}

    /**
     * @throws WorldActionRefused unless the player stands at the shelf for this category.
     */
    public function ensureAt(User $user, string $slug): LibraryShelf
    {
        $this->reach->ensureWithin($user, FixtureKind::Library, $slug);

        return LibraryShelf::query()->where('slug', $slug)->first() ?? throw WorldActionRefused::bookUnavailable();
    }

    /**
     * What a player at the shelf sees: its category and the spines of its published books.
     *
     * @return array{kind: string, slug: string, name: string, books: list<array<string, mixed>>, held: list<int>}
     */
    public function view(User $viewer, LibraryShelf $shelf): array
    {
        $books = $shelf->books()->readable()->with('author')->orderByDesc('published_at')->orderByDesc('id')->get();

        return [
            'kind' => 'library',
            'slug' => $shelf->slug,
            'name' => $shelf->name,
            'books' => array_values($books->map(fn (Book $book): array => $book->toSpine())->all()),
            // The books this player already holds a copy of, which they can't check out again.
            'held' => array_values(array_map(intval(...), $viewer->bookCopies()->whereIn('book_id', $books->modelKeys())->pluck('book_id')->all())),
        ];
    }

    /**
     * The slugs of every library shelf on any active public map, for the admin
     * to show which categories have a shelf in the game yet.
     *
     * @return list<string>
     */
    public function shelvesOnMaps(): array
    {
        $slugs = [];

        foreach (Room::query()->where('is_active', true)->where('kind', RoomKind::Public)->whereNotNull('map_version')->get() as $room) {
            foreach ($this->grids->forRoom($room)->fixtures(FixtureKind::Library) as $fixture) {
                $slugs[] = $fixture->name;
            }
        }

        return array_values(array_unique($slugs));
    }
}

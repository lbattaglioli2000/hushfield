<?php

namespace App\Models;

use App\Domain\Audit\AuditLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A category of the Hushfield Public Library, such as "New arrivals". Each
 * book is on one. Players reach it at its shelf in the library: a fixture of
 * kind "library" on the map, named this category's slug (see
 * resources/maps/README.md, "Fixtures"). The slug never changes, since the
 * map refers to it.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property int $position Shelves are listed in this order, lowest first.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Book> $books
 */
#[Fillable(['slug', 'name', 'position'])]
class LibraryShelf extends Model
{
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /** Staff add, rename and remove categories in the admin: each change is audited, by whoever made it. */
    protected static function booted(): void
    {
        foreach (['created' => 'Added', 'updated' => 'Changed', 'deleted' => 'Removed'] as $event => $verb) {
            static::{$event}(function (self $shelf) use ($event, $verb): void {
                activity(AuditLog::Library)
                    ->performedOn($shelf)
                    ->withProperties(['slug' => $shelf->slug, 'name' => $shelf->name, 'changed' => array_keys($shelf->getChanges())])
                    ->event("library_shelf.{$event}")
                    ->log("{$verb} the library shelf {$shelf->name}");
            });
        }
    }

    /** @return HasMany<Book, $this> */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }
}

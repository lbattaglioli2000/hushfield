<?php

namespace App\Models;

use App\Domain\Assets\ItemArt;
use App\Domain\Items\ItemRarity;
use App\Domain\Items\ItemSlot;
use App\Domain\World\Footprint;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A catalog entry: what an item is, not who owns it (see InventoryItem).
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property ItemSlot $slot
 * @property ItemRarity $rarity
 * @property int $price
 * @property int $wide Furniture only: tiles along x, before any turn.
 * @property int $deep Furniture only: tiles along y, before any turn.
 * @property int $height Furniture only: how tall its placeholder block stands, in pixels.
 * @property bool $floor Furniture only: lies flat, like a rug. Walked over, and one other piece may stand on it.
 * @property list<string>|null $shape Furniture only: which tiles of the wide × deep block it covers, before any turn, as a Footprint mask. Null covers them all.
 * @property bool $in_shop
 * @property bool $members_only
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ItemArtUpload> $artUploads
 * @property-read Collection<int, ItemArtUpload> $publishedArt
 */
#[Fillable(['slug', 'name', 'description', 'slot', 'rarity', 'price', 'wide', 'deep', 'height', 'floor', 'shape', 'in_shop', 'members_only'])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'slot' => ItemSlot::class,
            'rarity' => ItemRarity::class,
            'price' => 'integer',
            'wide' => 'integer',
            'deep' => 'integer',
            'height' => 'integer',
            'floor' => 'boolean',
            'shape' => 'array',
            'in_shop' => 'boolean',
            'members_only' => 'boolean',
        ];
    }

    /**
     * Art drawn for one size and shape doesn't fit another, so changing them
     * marks every upload stale: the game stops drawing it, and the admin
     * catalog asks for new art.
     */
    protected static function booted(): void
    {
        static::updated(function (self $item): void {
            if ($item->wasChanged(['wide', 'deep', 'shape'])) {
                $item->artUploads()->whereNull('stale_at')->update(['stale_at' => now()]);
            }
        });
    }

    /** @return HasMany<InventoryItem, $this> */
    public function owners(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    /** @return HasMany<ItemArtUpload, $this> */
    public function artUploads(): HasMany
    {
        return $this->hasMany(ItemArtUpload::class);
    }

    /**
     * The uploads the game draws: published, and drawn for the item as it is.
     *
     * @return HasMany<ItemArtUpload, $this>
     */
    public function publishedArt(): HasMany
    {
        return $this->artUploads()->whereNotNull('published_at')->whereNull('stale_at');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function inShop(Builder $query): void
    {
        $query->where('in_shop', true);
    }

    /**
     * The tiles this piece of furniture covers with its back corner at ($x, $y),
     * turned $rotation quarter turns. Mirrors footprintOf in resources/js/game/shared/furniture.ts.
     */
    public function footprintAt(int $x, int $y, int $rotation): Footprint
    {
        return (new Footprint($x, $y, $this->wide, $this->deep, $this->shape))->turned($rotation);
    }

    /**
     * What the client is told about an item.
     *
     * @return array{id: int, slug: string, name: string, description: string|null, slot: string, rarity: string, price: int, members_only: bool, furniture: array{wide: int, deep: int, height: int, floor: bool, shape: list<string>|null, art: object}|null}
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'slot' => $this->slot->value,
            'rarity' => $this->rarity->value,
            'price' => $this->price,
            'members_only' => $this->members_only,
            'furniture' => $this->slot === ItemSlot::Furniture
                ? [
                    'wide' => $this->wide, 'deep' => $this->deep, 'height' => $this->height, 'floor' => $this->floor, 'shape' => $this->shape,
                    // Facing => URL, for the facings that have art.
                    'art' => (object) array_map(fn (array $art): string => $art['url'], app(ItemArt::class)->facings($this)),
                ]
                : null,
        ];
    }
}

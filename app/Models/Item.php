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
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A catalog entry: what an item is, not who owns it (see InventoryItem).
 *
 * @property int $id
 * @property string $slug
 * @property int|null $variant_of Furniture only: the item this is a colour of. A variant stands like its parent: same size and shape.
 * @property string|null $variant_label Furniture only: the colour's name, such as "Moss", for the parent as much as its variants.
 * @property string|null $swatch Furniture only: the colour as #rrggbb, shown to pick it in the shop.
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
 * @property-read Item|null $parent
 * @property-read Collection<int, Item> $variants
 * @property-read Collection<int, ItemArtUpload> $artUploads
 * @property-read Collection<int, ItemArtUpload> $publishedArt
 */
#[Fillable(['slug', 'variant_of', 'variant_label', 'swatch', 'name', 'description', 'slot', 'rarity', 'price', 'wide', 'deep', 'height', 'floor', 'shape', 'in_shop', 'members_only'])]
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

    /** What a variant takes from its parent, so every colour stands on the same tiles. */
    public const array INHERITED = ['slot', 'wide', 'deep', 'height', 'floor', 'shape'];

    /**
     * A variant always stands like its parent, and follows it when staff
     * resize the parent. Art drawn for one size and shape doesn't fit
     * another, so changing them marks every upload stale: the game stops
     * drawing it, and the admin catalog asks for new art.
     */
    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if ($item->variant_of !== null) {
                $item->fill(self::query()->findOrFail($item->variant_of)->only(self::INHERITED));
            }
        });

        static::updated(function (self $item): void {
            if ($item->wasChanged(['wide', 'deep', 'shape'])) {
                $item->artUploads()->whereNull('stale_at')->update(['stale_at' => now()]);
            }

            if ($item->wasChanged(self::INHERITED)) {
                $item->variants->each(fn (self $variant): bool => $variant->save());
            }
        });
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'variant_of');
    }

    /** @return HasMany<self, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(self::class, 'variant_of');
    }

    /** "Hearthmore Love Seat · Moss", for staff telling colours apart. */
    public function label(): string
    {
        return $this->variant_label === null ? $this->name : "{$this->name} · {$this->variant_label}";
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
     * `family` is the item a colour belongs to (itself for a parent or an item
     * without colours), so the shop shows one entry per family.
     *
     * @return array{id: int, slug: string, family: int, colour: string|null, swatch: string|null, name: string, description: string|null, slot: string, rarity: string, price: int, members_only: bool, furniture: array{wide: int, deep: int, height: int, floor: bool, shape: list<string>|null, art: object}|null}
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'family' => $this->variant_of ?? $this->id,
            'colour' => $this->variant_label,
            'swatch' => $this->swatch,
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

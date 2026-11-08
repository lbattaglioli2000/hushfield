<?php

namespace App\Models;

use App\Domain\Assets\ItemArt;
use Database\Factories\AvatarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * How a resident looks: a skin colour the base body is tinted with, a hair
 * style, and what they wear, one owned item per slot (docs/assets-plan.md §14).
 *
 * @property int $id
 * @property int $user_id
 * @property string $body_color
 * @property string $hair
 */
#[Fillable(['body_color', 'hair'])]
class Avatar extends Model
{
    /** @use HasFactory<AvatarFactory> */
    use HasFactory;

    /** The hair every resident has until they can pick one. */
    public const string DEFAULT_HAIR = 'short';

    public const array BODY_COLORS = [
        '#e8a87c', '#c38d9e', '#41b3a3', '#85cdca', '#e27d60', '#a3b18a',
        '#b8a1d9', '#f2c14e', '#7fa7d9', '#d98f8f', '#9bc995', '#c9b79c',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What they have on, with the slot each is worn in.
     *
     * @return BelongsToMany<InventoryItem, $this>
     */
    public function worn(): BelongsToMany
    {
        return $this->belongsToMany(InventoryItem::class, 'avatar_wearables')->withPivot('slot');
    }

    /**
     * @return array{body_color: string, hair: string, worn: list<array{slot: string, slug: string, art: object}>}
     */
    public function toPayload(): array
    {
        $art = app(ItemArt::class);

        return [
            'body_color' => $this->body_color,
            'hair' => $this->hair,
            'worn' => array_values($this->worn->map(fn (InventoryItem $owned): array => [
                'slot' => $owned->item->slot->value,
                'slug' => $owned->item->slug,
                // Part => URL, for the parts drawn; a wearable without art isn't drawn.
                'art' => (object) $art->wearable($owned->item),
            ])->sortBy('slot')->all()),
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One owned furniture item placed on a tile of its owner's home.
 *
 * @property int $id
 * @property int $home_id
 * @property int $inventory_item_id
 * @property int $x
 * @property int $y
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Home $home
 * @property-read InventoryItem $inventoryItem
 */
#[Fillable(['home_id', 'inventory_item_id', 'x', 'y'])]
class HomeFurniture extends Model
{
    protected $table = 'home_furniture';

    protected function casts(): array
    {
        return [
            'x' => 'integer',
            'y' => 'integer',
        ];
    }

    /** @return BelongsTo<Home, $this> */
    public function home(): BelongsTo
    {
        return $this->belongsTo(Home::class);
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /**
     * @return array{x: int, y: int, item: array<string, mixed>}
     */
    public function toPayload(): array
    {
        return [
            'x' => $this->x,
            'y' => $this->y,
            'item' => $this->inventoryItem->item->toPayload(),
        ];
    }
}

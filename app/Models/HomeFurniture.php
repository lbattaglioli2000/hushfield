<?php

namespace App\Models;

use App\Domain\World\Footprint;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One owned furniture item placed in its owner's home, its back corner on
 * tile (x, y), turned `rotation` quarter turns.
 *
 * @property int $id
 * @property int $home_id
 * @property int $inventory_item_id
 * @property int $x
 * @property int $y
 * @property int $rotation Quarter turns clockwise from how the item's art faces, 0–3.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Home $home
 * @property-read InventoryItem $inventoryItem
 */
#[Fillable(['home_id', 'inventory_item_id', 'x', 'y', 'rotation'])]
class HomeFurniture extends Model
{
    protected $table = 'home_furniture';

    protected function casts(): array
    {
        return [
            'x' => 'integer',
            'y' => 'integer',
            'rotation' => 'integer',
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

    public function footprint(): Footprint
    {
        return $this->inventoryItem->item->footprintAt($this->x, $this->y, $this->rotation);
    }

    /** Rugs and other floor pieces are walked over; everything else is walked around. */
    public function blocks(): bool
    {
        return ! $this->inventoryItem->item->floor;
    }

    /**
     * @return array{x: int, y: int, rotation: int, item: array<string, mixed>}
     */
    public function toPayload(): array
    {
        return [
            'x' => $this->x,
            'y' => $this->y,
            'rotation' => $this->rotation,
            'item' => $this->inventoryItem->item->toPayload(),
        ];
    }
}

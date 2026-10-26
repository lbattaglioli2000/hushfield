<?php

namespace App\Models;

use App\Domain\Items\InventorySource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One piece of an item a resident owns; they may own several of one item,
 * and each piece is placed in their home at most once. Created only by
 * App\Actions\Economy\PurchaseItem and App\Actions\Items\GrantItem.
 *
 * @property int $id
 * @property int $user_id
 * @property int $item_id
 * @property InventorySource $source
 * @property int|null $ledger_entry_id
 * @property int|null $granted_by
 * @property Carbon $created_at
 * @property-read User $user
 * @property-read Item $item
 * @property-read LedgerEntry|null $ledgerEntry
 * @property-read User|null $granter
 */
#[Fillable(['user_id', 'item_id', 'source', 'ledger_entry_id', 'granted_by'])]
class InventoryItem extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'source' => InventorySource::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<LedgerEntry, $this> */
    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}

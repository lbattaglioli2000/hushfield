<?php

namespace App\Models;

use App\Domain\Economy\LedgerReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One movement of coins. Append-only: entries are never updated or deleted;
 * a mistake is corrected with a new, opposite entry. Write through
 * App\Actions\Economy\RecordLedgerEntry, never directly.
 *
 * @property int $id
 * @property int $user_id
 * @property int $amount Positive credits, negative debits.
 * @property LedgerReason $reason
 * @property string $idempotency_key
 * @property int|null $item_id
 * @property int|null $actor_id
 * @property string|null $note
 * @property Carbon $created_at
 * @property-read User $user
 * @property-read Item|null $item
 * @property-read User|null $actor
 */
#[Fillable(['user_id', 'amount', 'reason', 'idempotency_key', 'item_id', 'actor_id', 'note'])]
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Ledger entries are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'reason' => LedgerReason::class,
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

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

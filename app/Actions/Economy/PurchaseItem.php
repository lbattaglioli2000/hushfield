<?php

namespace App\Actions\Economy;

use App\Domain\Economy\InsufficientCoins;
use App\Domain\Economy\LedgerReason;
use App\Domain\Items\InventorySource;
use App\Domain\World\FixtureKind;
use App\Domain\World\FixtureReach;
use App\Exceptions\WorldActionRefused;
use App\Features\WorldFlag;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Buys an item from the shop: the debit and the inventory row are written in
 * one transaction, so a resident never pays without owning or owns without paying.
 * A resident may own several of one item, so each purchase carries the
 * client's idempotency key: a replayed purchase (a double click, a retry)
 * returns the piece it already bought instead of buying another.
 * Only players in a room with a shop counter can buy, and only while the shop
 * kill switch is on.
 */
final readonly class PurchaseItem
{
    public function __construct(
        private RecordLedgerEntry $recordLedgerEntry,
        private FixtureReach $fixtures,
    ) {}

    public function __invoke(User $user, Item $item, string $key): InventoryItem
    {
        WorldFlag::Shop->ensureActive();

        $this->fixtures->ensureWithin($user, FixtureKind::Shop);

        if (! $item->in_shop) {
            throw WorldActionRefused::itemUnavailable();
        }

        // Nobody is a member yet; members-only items wait in the catalog until membership exists.
        if ($item->members_only) {
            throw WorldActionRefused::itemMembersOnly();
        }

        return DB::transaction(function () use ($user, $item, $key): InventoryItem {
            try {
                $entry = ($this->recordLedgerEntry)($user, -$item->price, LedgerReason::Purchase, "purchase:{$user->id}:{$key}", $item);
            } catch (InsufficientCoins) {
                throw WorldActionRefused::insufficientCoins();
            }

            if (! $entry->wasRecentlyCreated) {
                // A replay of a purchase that already went through.
                return InventoryItem::query()->where('ledger_entry_id', $entry->id)->sole();
            }

            return InventoryItem::query()->create([
                'user_id' => $user->id,
                'item_id' => $item->id,
                'source' => InventorySource::Purchase,
                'ledger_entry_id' => $entry->id,
            ]);
        });
    }
}

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
 * A resident owns at most one of each item, so the item itself is the
 * idempotency key: a repeated purchase returns what was already bought.
 * Only players in a room with a shop counter can buy, and only while the shop
 * kill switch is on.
 */
final readonly class PurchaseItem
{
    public function __construct(
        private RecordLedgerEntry $recordLedgerEntry,
        private FixtureReach $fixtures,
    ) {}

    public function __invoke(User $user, Item $item): InventoryItem
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

        return DB::transaction(function () use ($user, $item): InventoryItem {
            try {
                $entry = ($this->recordLedgerEntry)($user, -$item->price, LedgerReason::Purchase, "purchase:{$user->id}:{$item->id}", $item);
            } catch (InsufficientCoins) {
                throw WorldActionRefused::insufficientCoins();
            }

            $owned = InventoryItem::query()->where('user_id', $user->id)->where('item_id', $item->id)->first();

            if (! $entry->wasRecentlyCreated) {
                // A replay of a purchase that already went through.
                return $owned ?? throw WorldActionRefused::itemOwned();
            }

            if ($owned !== null) {
                // Owned some other way (a staff grant): the debit is rolled back with the transaction.
                throw WorldActionRefused::itemOwned();
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

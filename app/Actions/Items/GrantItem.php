<?php

namespace App\Actions\Items;

use App\Domain\Audit\AuditLog;
use App\Domain\Items\InventorySource;
use App\Exceptions\ModerationActionRefused;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\User;

/**
 * Staff give a resident an item for free, with a reason. Any catalog item can
 * be granted, including ones not in the shop. Audited.
 */
final readonly class GrantItem
{
    public function __invoke(User $target, Item $item, User $staff, string $reason): InventoryItem
    {
        $owned = InventoryItem::query()->createOrFirst(
            ['user_id' => $target->id, 'item_id' => $item->id],
            ['source' => InventorySource::StaffGrant, 'granted_by' => $staff->id],
        );

        if (! $owned->wasRecentlyCreated) {
            throw ModerationActionRefused::alreadyOwned();
        }

        activity(AuditLog::Economy)
            ->performedOn($target)
            ->causedBy($staff)
            ->withProperties(['item_id' => $item->id, 'item' => $item->slug, 'reason' => $reason])
            ->event('item.granted')
            ->log("Granted {$item->name}");

        return $owned;
    }
}

<?php

namespace App\Domain\Items;

use Filament\Support\Contracts\HasLabel;

/**
 * How a resident came to own an item.
 */
enum InventorySource: string implements HasLabel
{
    case Purchase = 'purchase';
    case StaffGrant = 'staff_grant';

    public function getLabel(): string
    {
        return match ($this) {
            self::Purchase => 'Purchased',
            self::StaffGrant => 'Granted by staff',
        };
    }
}

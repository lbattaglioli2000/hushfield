<?php

namespace App\Domain\Economy;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Why coins moved. Every ledger entry has exactly one.
 */
enum LedgerReason: string implements HasColor, HasLabel
{
    /** The daily Hearthmore Group stipend. */
    case Stipend = 'stipend';

    /** A shop purchase (always a debit). */
    case Purchase = 'purchase';

    /** A catch landed off the end of The Pier, up to the daily fishing cap. */
    case Fishing = 'fishing';

    /** Coins added or removed by staff, with a reason. */
    case StaffAdjustment = 'staff_adjustment';

    public function getLabel(): string
    {
        return match ($this) {
            self::Stipend => 'Daily stipend',
            self::Purchase => 'Purchase',
            self::Fishing => 'Fishing',
            self::StaffAdjustment => 'Staff adjustment',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Stipend => 'success',
            self::Purchase => 'info',
            self::Fishing => 'primary',
            self::StaffAdjustment => 'warning',
        };
    }
}

<?php

namespace App\Domain\Books;

use Filament\Support\Contracts\HasLabel;

/**
 * How a resident came to hold a copy. Only checked-out copies will ever be
 * returned to the library (or run up late fees); staff gifts are kept for good.
 */
enum CopySource: string implements HasLabel
{
    case CheckedOut = 'checked_out';
    case StaffGift = 'staff_gift';

    public function getLabel(): string
    {
        return match ($this) {
            self::CheckedOut => 'Checked out',
            self::StaffGift => 'Given by staff',
        };
    }
}

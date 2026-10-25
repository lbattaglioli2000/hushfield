<?php

namespace App\Domain\World;

use Filament\Support\Contracts\HasLabel;

/**
 * Public rooms are shared and split into numbered instances as they fill.
 * A residence room is a floor plan: each of its instances is one resident's home.
 */
enum RoomKind: string implements HasLabel
{
    case Public = 'public';
    case Residence = 'residence';

    public function getLabel(): string
    {
        return match ($this) {
            self::Public => 'Public',
            self::Residence => 'Residence',
        };
    }
}

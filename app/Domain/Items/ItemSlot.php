<?php

namespace App\Domain\Items;

use Filament\Support\Contracts\HasLabel;

/**
 * Where an item goes: on a resident (one worn item per slot), or, for
 * furniture, on a tile of their home.
 */
enum ItemSlot: string implements HasLabel
{
    case Head = 'head';
    case Face = 'face';
    case Body = 'body';
    case Feet = 'feet';
    case Hand = 'hand';
    case Pin = 'pin';
    case Background = 'background';
    case Furniture = 'furniture';

    public function getLabel(): string
    {
        return match ($this) {
            self::Head => 'Head',
            self::Face => 'Face',
            self::Body => 'Body',
            self::Feet => 'Feet',
            self::Hand => 'Hand',
            self::Pin => 'Pin',
            self::Background => 'Background',
            self::Furniture => 'Furniture',
        };
    }
}

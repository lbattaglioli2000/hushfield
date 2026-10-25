<?php

namespace App\Domain\Items;

use Filament\Support\Contracts\HasLabel;

/**
 * Where a cosmetic sits on a resident. A resident wears at most one item per slot.
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
        };
    }
}

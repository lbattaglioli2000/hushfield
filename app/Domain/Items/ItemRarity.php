<?php

namespace App\Domain\Items;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ItemRarity: string implements HasColor, HasLabel
{
    case Common = 'common';
    case Uncommon = 'uncommon';
    case Rare = 'rare';
    case Peculiar = 'peculiar';

    public function getLabel(): string
    {
        return match ($this) {
            self::Common => 'Common',
            self::Uncommon => 'Uncommon',
            self::Rare => 'Rare',
            self::Peculiar => 'Peculiar',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Common => 'gray',
            self::Uncommon => 'success',
            self::Rare => 'info',
            self::Peculiar => 'warning',
        };
    }
}

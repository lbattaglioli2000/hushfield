<?php

namespace App\Domain\Assets;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** How much of an item's art is built; see ItemArt. */
enum ArtStatus: string implements HasColor, HasLabel
{
    case None = 'none';
    case Partial = 'partial';
    case Complete = 'complete';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::None => 'gray',
            self::Partial => 'warning',
            self::Complete => 'success',
        };
    }
}

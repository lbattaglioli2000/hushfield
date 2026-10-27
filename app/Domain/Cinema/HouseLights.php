<?php

namespace App\Domain\Cinema;

use Filament\Support\Contracts\HasLabel;

/**
 * A theater's house lights, as staff set them: down to a dim while a film is
 * on and up between films (auto), or held up, dim or dark whatever plays.
 */
enum HouseLights: string implements HasLabel
{
    case Auto = 'auto';
    case Up = 'up';
    case Dim = 'dim';
    case Dark = 'dark';

    public function getLabel(): string
    {
        return match ($this) {
            self::Auto => 'Auto (dim during films)',
            self::Up => 'Up',
            self::Dim => 'Dim',
            self::Dark => 'Dark',
        };
    }
}

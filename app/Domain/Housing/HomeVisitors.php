<?php

namespace App\Domain\Housing;

use Filament\Support\Contracts\HasLabel;

/**
 * Who may come into a resident's home besides the resident.
 */
enum HomeVisitors: string implements HasLabel
{
    case Closed = 'closed';
    case Friends = 'friends';
    case Everyone = 'everyone';

    public function getLabel(): string
    {
        return match ($this) {
            self::Closed => 'Closed',
            self::Friends => 'Friends only',
            self::Everyone => 'Open to everyone',
        };
    }
}

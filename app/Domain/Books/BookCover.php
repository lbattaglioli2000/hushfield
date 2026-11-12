<?php

namespace App\Domain\Books;

use Filament\Support\Contracts\HasLabel;

/**
 * The colours a book's cover comes in: its spine on a shelf and its cover in
 * the reader. Stored by name, so a colour can be retuned without touching books.
 */
enum BookCover: string implements HasLabel
{
    case Oxblood = 'oxblood';
    case Moss = 'moss';
    case Navy = 'navy';
    case Ochre = 'ochre';
    case Plum = 'plum';
    case Slate = 'slate';
    case Cream = 'cream';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    /** As #rrggbb, for the game to paint. */
    public function hex(): string
    {
        return match ($this) {
            self::Oxblood => '#7a2e2a',
            self::Moss => '#556b3f',
            self::Navy => '#2d3e5c',
            self::Ochre => '#b8892f',
            self::Plum => '#5e3a55',
            self::Slate => '#4d5560',
            self::Cream => '#d9cfb4',
        };
    }
}
